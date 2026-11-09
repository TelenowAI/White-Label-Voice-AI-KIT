<?php

namespace App\Kit\Agents;

use App\Kit\Knowledge\AgentKnowledge;
use App\Models\Agent;
use App\Models\AgentWebhook;
use Illuminate\Validation\ValidationException;

/**
 * Duplicating an agent within its account (console → an agent → Duplicate; contract/kit-api.yaml duplicateAgent): a new agent made from the
 * source as the console shows it, through the same writes a customer's own saves take (Agents::create, then Agents::update, AgentFlows::publish,
 * AgentKnowledge::set, AgentWebhooks::add), so every check is made again and every piece reaches Telenow: the operator's offering, the transfer
 * switch, hand-overs, members for the after-call email. Never Telenow's template operations, which share templates across every Telenow account.
 *
 * Copied: name ("… (copy)", numbered when taken), description, prompt, every call setting, the voice setup (an own voice too), the realtime voice,
 * after-call email, hand-overs, tools and look-ups, the call steps, knowledge bases, webhook addresses, its logo (a file of its own), and whether it is on. Keys are not: Telenow
 * alone holds a tool's, a look-up's or a step's key and the kit never reads them back (SavedKeys), so each is to be typed again; a webhook address
 * gets its own new signing secret. Not copied: phone numbers, the web chat box, campaigns, calls, versions. `toRedo` says what is left to do.
 *
 * Not here: making and saving agents (Agents), starter agents for every account.
 */
final class AgentDuplicate
{
    /** What the console shows of settings that are written apart from the rest: never sent as plain settings. */
    private const APART = ['tools', 'lookups', 'handoff', 'flow'];

    public function __construct(private readonly Agents $agents, private readonly AgentSettings $settings, private readonly AgentFlow $flow, private readonly AgentFlows $flows, private readonly AgentProviderSettings $providerSettings, private readonly AgentRealtime $realtime, private readonly \App\Kit\Catalog\Catalog $catalog, private readonly AgentKnowledge $knowledge, private readonly AgentWebhooks $webhooks, private readonly SavedKeys $keys) {}

    /** @return array{agent: Agent, toRedo: list<array{kind: string, name: ?string}>} */
    public function duplicate(Agent $source): array
    {
        $workspace = $source->workspace;
        $now = $this->settings->of($source);
        $toRedo = [];

        // What waits until the copy exists is checked first, as a save of it will be (every rule and limit, for an agent of this account),
        // so nothing is made that cannot be finished; a piece that no longer passes is left for the customer to set again.
        $later = [];
        $unsaved = (new Agent)->forceFill(['workspace_id' => $workspace->id])->setRelation('workspace', $workspace);
        $passes = fn (array $settings) => fn () => $this->settings->validated(['settings' => $settings], $unsaved);
        // A key to type again is said only for a tool or look-up the copy has.
        $tools = [];
        $toolKeys = [];
        foreach (array_values($now['tools'] ?? []) as $row) {
            $tools[] = $this->toolWithoutKey($row, $toolKeys);
        }
        if ($tools !== [] && $this->passes($passes(['tools' => $tools, 'sendCallerToTools' => $now['sendCallerToTools'] ?? false]), 'tools', $toRedo)) {
            $later['tools'] = $tools;
            array_push($toRedo, ...$toolKeys);
        }
        $lookups = [];
        $lookupKeys = [];
        foreach (array_values($now['lookups'] ?? []) as $row) {
            $lookups[] = $this->lookupWithoutKey($row, $lookupKeys);
        }
        if ($lookups !== [] && $this->passes($passes(['lookups' => $lookups]), 'lookups', $toRedo)) {
            $later['lookups'] = $lookups;
            array_push($toRedo, ...$lookupKeys);
        }
        $handoff = $now['handoff'] ?? null;
        if (is_array($handoff) && ($handoff['agents'] ?? []) !== [] && $this->passes($passes(['handoff' => $handoff]), 'handoff', $toRedo)) {
            $later['handoff'] = $handoff;
        }

        $name = $this->freeName($workspace->id, $source->name, __('agents.copy_suffix'), 'agents.copy_suffix_n');
        $input = ['name' => $name, 'description' => $source->description, 'systemPrompt' => $source->system_prompt, 'settings' => $this->plain($now)];
        $copy = $this->made($workspace, $input, $this->voiceSetup($source, $now), self::stackOf($source), $toRedo);
        $update = ($source->is_active ? [] : ['isActive' => false]) + ($later === [] ? [] : ['settings' => $later]);
        if ($update !== []) {
            $copy = $this->agents->update($copy, $update);
        }

        $copy = $this->steps($now['flow'] ?? null, $source->flow_draft, $copy, $toRedo);
        $bases = $source->knowledgeBases()->whereNull('knowledge_bases.deleted_at')->pluck('knowledge_bases.id')->all();
        if ($bases !== []) {
            $copy = $this->knowledge->set($copy, ['knowledgeBaseIds' => $bases]);
        }
        $hooks = AgentWebhook::query()->where('agent_id', $source->id)->whereNull('deleted_at')->orderBy('created_at')->orderBy('id')->get();
        foreach ($hooks as $hook) {
            $this->webhooks->add($copy, ['url' => $hook->url, 'events' => $hook->events, 'includeTranscript' => $hook->include_transcript]);
        }
        if ($hooks->isNotEmpty()) {
            $toRedo[] = ['kind' => 'webhooks', 'name' => null];
        }

        app(AgentLogos::class)->copy($source, $copy); // its own file: removing one never removes the other's

        return ['agent' => $copy->fresh(), 'toRedo' => $toRedo];
    }

    /**
     * A name no live agent of the account has: `$base` with `$first` ("… (copy)"; none: the name itself), else with `$nth` (a lang key taking `n`, from 2)
     * while that is taken; never longer than a name may be.
     */
    public function freeName(string $workspaceId, string $base, string $first, string $nth): string
    {
        $taken = Agent::query()->where('workspace_id', $workspaceId)->whereNull('deleted_at')->pluck('name')->all();
        $max = (int) config('kit.agents.name_max_length');
        for ($n = 1; ; $n++) {
            $suffix = $n === 1 ? $first : __($nth, ['n' => $n]);
            $name = mb_substr($base, 0, max(1, $max - mb_strlen($suffix))).$suffix;
            if (! in_array($name, $taken, true)) {
                return $name;
            }
        }
    }

    /**
     * A new agent made with `$voice`; when the operator does not offer part of it (any more) it starts from the defaults instead, and `voiceSetup` is said
     * when that is not `$sourceStack` (by column) or provider settings or a realtime voice were dropped.
     */
    public function made(\App\Models\Workspace $workspace, array $input, array $voice, array $sourceStack, array &$toRedo): Agent
    {
        try {
            return $this->agents->create($workspace, $input + $voice);
        } catch (ValidationException $e) {
            if ($voice === [] || ! $this->aboutVoice($e, $voice)) {
                throw $e;
            }
            $made = $this->agents->create($workspace, $input);
            if (self::stackOf($made) !== $sourceStack || isset($voice['providerSettings']) || isset($voice[AgentRealtime::KEY])) {
                $toRedo[] = ['kind' => 'voiceSetup', 'name' => null];
            }

            return $made;
        }
    }

    /** @return list<mixed> an agent's stack, by column (config('kit.agents.stack')) */
    public static function stackOf(Agent $agent): array
    {
        return array_map(fn (string $column) => $agent->getAttribute($column), config('kit.agents.stack'));
    }

    /** The settings written with the agent itself, as the console shows them (provider settings and the realtime voice are the voice setup's). */
    public function plain(array $now): array
    {
        $out = array_diff_key($now, array_flip([...self::APART, AgentRealtime::KEY, ...array_values(AgentProviderSettings::KEYS)]));
        if (! $this->settings->transferAllowed()) {
            unset($out['transfer']); // the operator has taken transfers back: a new agent starts without (AgentTransfers turned the source's off too)
        }

        return $out;
    }

    /** @return array<string, mixed> the stack, each provider's own settings and the realtime voice, as the console sends them; none when it has no stack */
    public function voiceSetup(Agent $source, array $now): array
    {
        $stack = array_filter($this->catalog->presentStack($source)['stack'], fn ($v) => $v !== null);
        if ($stack === []) {
            return [];
        }
        $out = $stack;
        foreach ($this->providerSettings->forCustomers($now) as $key => $value) {
            $values = (array) $value['values'];
            if ($values !== []) {
                $out['providerSettings'][array_search($key, AgentProviderSettings::KEYS, true)] = $values;
            }
        }
        $realtime = $this->realtime->forCustomers($now[AgentRealtime::KEY] ?? null);
        if ($realtime !== null) {
            $out[AgentRealtime::KEY] = array_intersect_key($realtime, array_flip(['provider', 'model', 'voice', 'fallback']));
        }

        return $out;
    }

    /** Whether every reason a create was refused is about the voice setup. */
    private function aboutVoice(ValidationException $e, array $voice): bool
    {
        $fields = array_keys($voice);

        return array_filter(array_keys($e->errors()), fn (string $at) => ! in_array(explode('.', $at)[0], $fields, true)) === [];
    }

    /** The call steps: published on the copy when they need no key, else kept as its draft to finish; `$draft` (the source's own) comes too. */
    public function steps(?array $published, ?array $draft, Agent $copy, array &$toRedo): Agent
    {
        $leftOver = null;
        if ($published !== null) {
            $leftOver = $published;
            if (! $this->flow->keepsKey($published)) {
                try {
                    $this->flows->publish($copy, ['flow' => $published]);
                    $leftOver = null;
                } catch (AgentRefused|ValidationException) {
                    // kept as a draft below, to be published from the steps page
                }
            }
        }
        $draft ??= $leftOver === null ? null : $this->flowWithoutKeys($leftOver);
        if ($draft !== null) {
            $copy->forceFill(['flow_draft' => $draft, 'flow_draft_saved_at' => now()])->save();
        }
        if ($leftOver !== null) {
            $toRedo[] = ['kind' => 'flow', 'name' => null];
        }

        return $copy->fresh();
    }

    private function passes(\Closure $check, string $kind, array &$toRedo): bool
    {
        try {
            $check();

            return true;
        } catch (ValidationException) {
            $toRedo[] = ['kind' => $kind, 'name' => null];

            return false;
        }
    }

    /** A tool as the console sends one, without its key (a token, a secret header): each lost one is to be typed again. */
    private function toolWithoutKey(array $row, array &$toRedo): array
    {
        $lost = ($row['tokenHint'] ?? null) !== null;
        $request = null;
        if (is_array($row['request'] ?? null)) {
            $headers = $this->headersWithoutKeys($row['request']['headers'] ?? [], $lost);
            $request = ['method' => $row['request']['method'], 'headers' => $headers, 'body' => $row['request']['body'] ?? null];
        }
        if ($lost) {
            $toRedo[] = ['kind' => 'key', 'name' => $row['name']];
        }

        return array_filter(['name' => $row['name'], 'description' => $row['description'] ?? null, 'url' => $row['url'], 'request' => $request,
            'parameters' => $row['parameters'] ?? [], 'sayWhileRunning' => $row['sayWhileRunning'] ?? null, 'timeoutSecs' => $row['timeoutSecs'] ?? null,
            'askFirst' => $row['askFirst'] ?? false], fn ($v) => $v !== null);
    }

    /** A look-up as the console sends one, without its key. */
    private function lookupWithoutKey(array $row, array &$toRedo): array
    {
        $lost = ($row['tokenHint'] ?? null) !== null;
        $headers = $this->headersWithoutKeys($row['headers'] ?? [], $lost);
        if ($lost) {
            $toRedo[] = ['kind' => 'key', 'name' => $row['name']];
        }

        return ['name' => $row['name'], 'url' => $row['url'], 'method' => $row['method'], 'headers' => $headers, 'body' => $row['body'] ?? null, 'fills' => $row['fills'] ?? []];
    }

    /** @return list<array{name: string, value: string}> the headers that hold no key Telenow keeps (shown masked) */
    private function headersWithoutKeys(array $headers, bool &$lost): array
    {
        $kept = [];
        foreach ($headers as $header) {
            if ($this->keys->isMask((string) ($header['value'] ?? ''))) {
                $lost = true;
            } else {
                $kept[] = ['name' => $header['name'], 'value' => $header['value']];
            }
        }

        return $kept;
    }

    /** Published steps as a draft for the copy: each step's tool without its key, so the steps page asks for it. */
    private function flowWithoutKeys(array $flow): array
    {
        foreach ($flow['steps'] ?? [] as $i => $step) {
            if (is_array($step['tool'] ?? null)) {
                $lost = false;
                if (is_array($step['tool']['request'] ?? null)) {
                    $flow['steps'][$i]['tool']['request']['headers'] = $this->headersWithoutKeys($step['tool']['request']['headers'] ?? [], $lost);
                }
                $flow['steps'][$i]['tool']['tokenHint'] = null;
            }
        }

        return $flow;
    }
}
