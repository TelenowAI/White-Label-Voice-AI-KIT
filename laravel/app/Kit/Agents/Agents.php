<?php

namespace App\Kit\Agents;

use App\Kit\Agents\Telenow\AgentWrite;
use App\Kit\Catalog\Catalog;
use App\Kit\Catalog\CatalogVoices;
use App\Kit\Manifest\Manifest;
use App\Kit\Outbox\Outbox;
use App\Models\Agent;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A customer's prompt agents (console → Agents; SELF_HOSTED_KIT_PLAN.md §3.8 a, §4.4): written to the kit's DB and the
 * outbox in one transaction, then sent to Telenow at once with the customer's key; when Telenow cannot take a change
 * yet, the agent says so and `kit:outbox` keeps trying. Only the fields that changed are sent. What Telenow fields an
 * agent has, and their names, come from the manifest (contract/resources.yaml `agents`).
 *
 * - An agent can only be made once the account's sub-account exists (its key is what makes it).
 * - Deleting marks the agent deleted and deletes it at Telenow; an agent whose create Telenow refused never existed
 *   there, so its unsent writes are simply closed.
 *
 * - How it behaves on a call (AgentSettings) is written the same way, but lives in Telenow's blobs: only the settings that changed
 *   go in the outbox, and they become blobs when they are sent.
 *
 * - Who is emailed after a call is the kit's alone (AfterCallEmail), so changing it writes nothing to Telenow.
 *
 * - The provider stack is chosen whole (language model and its model, speech recognition, voice provider and voice),
 *   from what the operator offers (App\Kit\Catalog\Catalog::stack); a new agent given none starts from the defaults. Each provider's own settings
 *   (`providerSettings`, AgentProviderSettings) are checked against what is offered for it, a provider changed starts its settings afresh, and a voice must be
 *   one of the chosen speech model's voices. A realtime voice (`realtime`, AgentRealtime) is picked among those offered, or none; the stack stays whole beside
 *   it, since a call falls back to it and Telenow requires it (recorded).
 *
 * Not here: sending (Telenow/*), the test call (TestCall), what is offered (App\Kit\Catalog).
 */
final class Agents
{
    /** The kit's API names for the agent's editable columns. */
    private const INPUT = ['name' => 'name', 'description' => 'description', 'systemPrompt' => 'system_prompt', 'isActive' => 'is_active'];

    public function __construct(private readonly Outbox $outbox, private readonly Manifest $manifest, private readonly Catalog $catalog, private readonly AgentSettings $settings, private readonly AfterCallEmail $afterCallEmail, private readonly AgentWebhooks $webhooks, private readonly AgentProviderSettings $providerSettings, private readonly CatalogVoices $voices, private readonly AgentRealtime $realtime) {}

    public function create(Workspace $workspace, array $input): Agent
    {
        if ($workspace->telenow_sub_account_id === null) {
            throw new AgentRefused(409, __('agents.account_not_ready'));
        }
        $columns = $this->validated($input, creating: true, workspace: $workspace);
        unset($columns['is_active']); // a new agent is active (Telenow's create takes no isActive)
        $settings = $this->settings->forNewAgent($this->settings->validated($input)) + $this->withProviderSettings(null, $input, $columns, $columns) + $this->withRealtime($input);
        $email = $this->afterCallEmail->validated($input, (new Agent)->forceFill(['workspace_id' => $workspace->id]));

        $agent = DB::transaction(function () use ($workspace, $columns, $settings, $email) {
            $agent = new Agent($columns);
            $agent->workspace_id = $workspace->id;
            $agent->forceFill(['settings' => $settings, 'after_call_email' => $email])->save();
            $body = $this->manifest->writeBody(AgentWrite::RESOURCE, 'create', $columns, $agent->id);
            $this->outbox->enqueue(AgentWrite::RESOURCE, $agent->id, 'create', $this->settings->writes(['metadata' => $body['metadata'] ?? []], $settings) + $body, $agent->id);

            return $agent;
        });
        $this->outbox->runFor(AgentWrite::RESOURCE, $agent->id);

        return $agent->fresh();
    }

    public function update(Agent $agent, array $input): Agent
    {
        $columns = $this->validated($input, creating: false, workspace: $agent->workspace);
        $changed = array_filter(
            $columns,
            fn ($value, string $column) => $agent->getAttribute($column) !== $value,
            ARRAY_FILTER_USE_BOTH,
        );
        $now = $this->settings->of($agent);
        $settings = array_filter($this->settings->validated($input, $agent) + $this->withProviderSettings($agent, $input, $this->stackOf($columns, $agent), $changed) + $this->withRealtime($input),
            fn ($value, string $key) => $this->settings->changed($now, $key, $value), ARRAY_FILTER_USE_BOTH);
        $email = $this->afterCallEmail->validated($input, $agent);
        if ($email !== null && $email !== $this->afterCallEmail->of($agent)) {
            $agent->forceFill(['after_call_email' => $email])->save();
        }
        if ($changed === [] && $settings === []) {
            return $agent;
        }
        return $this->saveChecked($agent, $changed, $settings, $now);
    }

    /** Fields and settings already checked, and known to differ, on their way to Telenow. */
    public function saveChecked(Agent $agent, array $changed, array $settings, array $now): Agent
    {
        // A provider changed starts its settings from nothing, so they are sent as they are; the rest are merged into Telenow's blobs as they are when sent.
        $fresh = [];
        $merged = $settings;
        foreach (AgentProviderSettings::KEYS as $kind => $key) {
            if (isset($settings[$key]) && array_key_exists(config("kit.catalog.kinds.{$kind}.provider"), $changed)) {
                $values = array_filter($settings[$key]['values'], fn ($v) => $v !== null);
                $fresh[config("kit.catalog.settings.blobs.{$kind}.write")] = $values === [] ? new \stdClass : $values;
                unset($merged[$key]);
            }
        }
        DB::transaction(function () use ($agent, $changed, $settings, $now, $fresh, $merged) {
            $agent->forceFill($changed + ['sync_state' => 'pending'] + ($settings === [] ? [] : ['settings' => $this->settings->public($settings) + $now]))->save();
            $body = $this->manifest->writeBody(AgentWrite::RESOURCE, 'update', $changed) + $fresh;
            $this->outbox->enqueue(AgentWrite::RESOURCE, $agent->id, 'update', $merged === [] ? $body : $body + [AgentSettings::CHANGES => $merged]);
        });
        $this->outbox->runFor(AgentWrite::RESOURCE, $agent->id);

        return $agent->fresh();
    }

    /** A voice setup put back as it was, with no check: what is being kept may itself be no longer offered, and the customer has not chosen anything. */
    public function putStackBack(Agent $agent, array $columns): void
    {
        DB::transaction(function () use ($agent, $columns) {
            $agent->forceFill($columns + ['sync_state' => 'pending'])->save();
            $this->outbox->enqueue(AgentWrite::RESOURCE, $agent->id, 'update', $this->manifest->writeBody(AgentWrite::RESOURCE, 'update', $columns));
        });
    }

    public function delete(Agent $agent): void
    {
        $this->webhooks->removeAll($agent);
        if ($agent->telenow_id === null && $this->outbox->hasFailed(AgentWrite::RESOURCE, $agent->id)) {
            $this->outbox->cancel(AgentWrite::RESOURCE, $agent->id, __('agents.cancelled'));
            $agent->forceFill(['deleted_at' => now(), 'sync_state' => 'synced', 'sync_error' => null])->save();

            return;
        }
        DB::transaction(function () use ($agent) {
            $agent->forceFill(['deleted_at' => now(), 'sync_state' => 'pending'])->save();
            $this->outbox->enqueue(AgentWrite::RESOURCE, $agent->id, 'delete', null);
        });
        $this->outbox->runFor(AgentWrite::RESOURCE, $agent->id);
        foreach ($this->settings->handingOverTo($agent) as $other) {
            $held = $this->update($other['agent'], ['settings' => ['handoff' => $other['handoff']]]);
            $flow = $this->settings->cleanedFlow($held);
            if ($flow['kept'] !== []) {
                $this->saveChecked($held, [], ['flow' => $flow['flow']], $this->settings->of($held));
            }
        }
    }

    public function retry(Agent $agent): Agent
    {
        if ($agent->sync_state !== 'failed') {
            throw new AgentRefused(409, __('agents.nothing_to_retry'));
        }
        $agent->forceFill(['sync_state' => 'pending'])->save();
        $this->outbox->retry(AgentWrite::RESOURCE, $agent->id);

        return $agent->fresh();
    }

    /**
     * Each provider's own settings sent (`providerSettings.{kind}`), checked for the provider the agent will have; a provider changed with none sent starts afresh. Then the
     * voice must be one of the chosen speech model's voices (an own voice speaks with any).
     *
     * @param  array<string, mixed>  $columns  the stack being saved, by column
     * @param  array<string, mixed>  $changed  the columns that change
     * @return array<string, array> by settings key
     */
    private function withProviderSettings(?Agent $agent, array $input, array $columns, array $changed): array
    {
        $given = $input['providerSettings'] ?? null;
        if ($given !== null && ! is_array($given)) {
            throw ValidationException::withMessages(['providerSettings' => [__('catalog.settings_not_a_map')]]);
        }
        $out = [];
        foreach (AgentProviderSettings::KEYS as $kind => $key) {
            $column = config("kit.catalog.kinds.{$kind}.provider");
            $provider = $columns[$column] ?? $agent?->getAttribute($column);
            if (! is_string($provider)) {
                continue;
            }
            if (is_array($given) && array_key_exists($kind, $given)) {
                $out[$key] = $this->providerSettings->checked($kind, $provider, $given[$kind]);
            } elseif (array_key_exists($column, $changed)) {
                $out[$key] = ['provider' => $provider, 'values' => []];
            }
        }
        $tts = config('kit.catalog.kinds.tts');
        $key = AgentProviderSettings::KEYS['tts'];
        if (isset($out[$key]) || array_key_exists($tts['voice'], $changed)) {
            $this->voiceFitsModel($agent, $columns[$tts['provider']] ?? $agent?->getAttribute($tts['provider']), $columns[$tts['voice']] ?? $agent?->getAttribute($tts['voice']), $out[$key] ?? null);
        }

        return $out;
    }

    /** The realtime voice sent (`realtime`: a pick, or null for none), checked (AgentRealtime); nothing when none was sent. */
    private function withRealtime(array $input): array
    {
        return array_key_exists(AgentRealtime::KEY, $input) ? [AgentRealtime::KEY => $this->realtime->checked($input[AgentRealtime::KEY])] : [];
    }

    private function voiceFitsModel(?Agent $agent, mixed $provider, mixed $voiceId, ?array $saving): void
    {
        if (! is_string($provider) || ! is_string($voiceId)) {
            return;
        }
        $now = $agent === null ? [] : ($this->settings->of($agent)[AgentProviderSettings::KEYS['tts']] ?? []);
        $values = (($now['provider'] ?? null) === $provider ? ($now['values'] ?? []) : []);
        foreach ($saving['values'] ?? [] as $k => $v) {
            $values[$k] = $v;
        }
        $model = AgentProviderSettings::modelOf($this->providerSettings->fieldsOf('tts', $provider), array_filter($values, fn ($v) => $v !== null));
        $voice = $this->voices->find($provider, $voiceId);
        if ($voice !== null && ! CatalogVoices::fits($voice, $model)) {
            throw ValidationException::withMessages([$this->manifest->writeName(AgentWrite::RESOURCE, config('kit.catalog.kinds.tts.voice')) => [__('catalog.not_for_model')]]);
        }
    }

    /** @return array<string, mixed> the stack an update leaves the agent with, by column: what is sent (checked) over what it has */
    private function stackOf(array $columns, Agent $agent): array
    {
        $out = [];
        foreach (config('kit.agents.stack') as $column) {
            $out[$column] = $agent->getAttribute($column);
        }

        return array_intersect_key($columns, $out) + $out;
    }

    /** @return array<string, mixed> the given fields, by column */
    private function validated(array $input, bool $creating, Workspace $workspace): array
    {
        $some = $creating ? 'required' : 'sometimes';
        $values = Validator::make($input, [
            'name' => [$some, 'string', 'max:'.config('kit.agents.name_max_length')],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.config('kit.agents.description_max_length')],
            'systemPrompt' => ['sometimes', 'nullable', 'string', 'max:'.config('kit.agents.prompt_max_length')],
            'isActive' => ['sometimes', 'boolean'],
        ], [], array_map(fn (string $key) => __("agents.fields.{$key}"), array_combine(array_keys(self::INPUT), array_keys(self::INPUT))))->validate();

        $columns = [];
        foreach (self::INPUT as $key => $column) {
            if (array_key_exists($key, $values)) {
                $columns[$column] = $key === 'isActive' ? (bool) $values[$key] : $values[$key];
            }
        }
        $stack = array_map(fn (string $c) => $this->manifest->writeName(AgentWrite::RESOURCE, $c), config('kit.agents.stack'));
        if (array_intersect($stack, array_keys($input)) !== []) {
            $columns += $this->catalog->stack($input, $workspace);
        }

        return $columns;
    }
}
