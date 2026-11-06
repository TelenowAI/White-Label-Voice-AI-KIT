<?php

namespace App\Kit\Agents;

use App\Kit\Agents\Telenow\AgentWrite;
use App\Kit\Catalog\Catalog;
use App\Kit\Outbox\Outbox;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Agent;
use Illuminate\Validation\ValidationException;

/**
 * Building an agent's call steps: a draft kept here while it is worked on, a check, and publishing. Telenow would keep a draft too, but every save there makes a version, so half-made steps stay here,
 * with any key typed sealed. Publishing is the kit's checks (AgentFlow), then Telenow's own, since it stores any graph and discards a bad one on every call, then an ordinary write of the agent.
 *
 * Not here: the graph itself (AgentFlow), the routes (AgentFlowController).
 */
final class AgentFlows
{
    public function __construct(private readonly AgentFlow $flow, private readonly AgentSettings $settings, private readonly Agents $agents, private readonly TelenowClient $telenow, private readonly Outbox $outbox, private readonly SavedKeys $keys) {}

    /** @return array<string, mixed> what the page needs: never a key */
    public function show(Agent $agent, array $warnings = []): array
    {
        return [
            'published' => $this->settings->of($agent)['flow'] ?? null,
            'draft' => $this->keys->without($agent->flow_draft),
            'draftSavedAt' => $agent->flow_draft_saved_at?->toIso8601String(),
            'syncState' => $agent->sync_state,
            'syncError' => $agent->sync_error,
            'warnings' => $warnings,
            'kinds' => array_values(array_filter(config('kit.agents.flow.kinds'), fn (string $kind) => $kind !== 'transfer' || $this->settings->transferAllowed())),
            'exitKinds' => config('kit.agents.flow.exit_kinds'),
            'captureTypes' => config('kit.agents.flow.capture_types'),
            'captureFormats' => config('kit.agents.flow.capture_formats'),
            'otherAgents' => Agent::query()->where('workspace_id', $agent->workspace_id)->whereNull('deleted_at')->whereNotNull('telenow_id')->where('id', '!=', $agent->id)->orderBy('name')->get(['id', 'name'])->map(fn (Agent $a) => ['id' => $a->id, 'name' => $a->name])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function saveDraft(Agent $agent, array $input): array
    {
        $draft = $this->flow->drafted((array) ($input['flow'] ?? []), $agent->flow_draft, $this->settings->of($agent)['flow'] ?? null);
        $agent->forceFill(['flow_draft' => $draft, 'flow_draft_saved_at' => now()])->save();

        return $this->show($agent);
    }

    /** @return array<string, mixed> */
    public function discardDraft(Agent $agent): array
    {
        $agent->forceFill(['flow_draft' => null, 'flow_draft_saved_at' => null])->save();

        return $this->show($agent);
    }

    /** @return list<array{at: string, message: string}> what might go wrong on a call, for steps that are otherwise fine */
    public function check(Agent $agent, array $input): array
    {
        $this->atTelenow($agent);

        return $this->verdict($agent, $this->flow->checked((array) ($input['flow'] ?? []), $agent, $agent->flow_draft, $this->settings->of($agent), $this->settings));
    }

    /** @return array<string, mixed> */
    public function publish(Agent $agent, array $input): array
    {
        $this->ready($agent);
        $now = $this->settings->of($agent);
        $flow = $this->flow->checked((array) ($input['flow'] ?? []), $agent, $agent->flow_draft, $now, $this->settings);
        $warnings = $this->verdict($agent, $flow);
        if ($this->settings->changed($now, 'flow', $flow)) {
            $agent = $this->agents->saveChecked($agent, [], ['flow' => $flow], $now);
            if ($this->outbox->hasFailed(AgentWrite::RESOURCE, $agent->id)) {
                // Telenow said no and nothing changed there, so the agent goes on as it was and the work is not lost.
                $this->outbox->cancel(AgentWrite::RESOURCE, $agent->id, (string) $agent->sync_error);
                $agent->forceFill(['sync_state' => 'synced', 'sync_error' => null, 'settings' => ['flow' => $now['flow'] ?? null] + (array) $agent->settings, 'flow_draft' => $flow, 'flow_draft_saved_at' => now()])->save();
                throw new AgentRefused(409, __('agents.flow_refused'));
            }
        }
        $agent->forceFill(['flow_draft' => null, 'flow_draft_saved_at' => null])->save();

        return $this->show($agent->fresh(), $warnings);
    }

    /** Back to the agent's own settings. Any draft stays. @return array<string, mixed> */
    public function unpublish(Agent $agent): array
    {
        $this->ready($agent);
        $now = $this->settings->of($agent);
        if (($now['flow'] ?? null) !== null) {
            $agent = $this->agents->saveChecked($agent, [], ['flow' => null], $now);
        }

        return $this->show($agent);
    }

    /**
     * Telenow's own check, of the graph as a call would read it: what would lose the graph, or be refused on saving it, is refused here beside its step, and the rest is for the customer to weigh.
     *
     * @return list<array{at: string, message: string}>
     */
    private function verdict(Agent $agent, array $flow): array
    {
        try {
            $answer = $this->telenow->validateFlow((string) $agent->workspace->telenow_key, $this->flow->forChecking($flow, $this->settings), array_values((array) ($agent->telenow_snapshot['metadata']['variables'] ?? [])));
        } catch (TelenowError) {
            throw new AgentRefused(409, __('agents.flow_uncheckable'));
        }
        $problems = array_map(fn ($message) => $this->words((string) $message), (array) ($answer['errors'] ?? []));
        $refused = config('kit.agents.flow.refused_warnings');
        $steps = array_flip(array_column($flow['steps'], 'id'));
        $errors = $problems === [] ? [] : ['flow' => $problems];
        $warnings = [];
        foreach ((array) ($answer['warnings'] ?? []) as $warning) {
            $at = (string) ($warning['at'] ?? '');
            $message = $this->words((string) ($warning['message'] ?? ''));
            if (in_array($warning['code'] ?? null, $refused, true)) {
                $errors[isset($steps[$at]) ? "flow.steps.{$steps[$at]}.instructions" : 'flow'][] = $message;

                continue;
            }
            $warnings[] = ['at' => $at, 'message' => $message];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $warnings;
    }

    /** Telenow's sentence as it stands, but for its spacing: its name is never shown. */
    private function words(string $message): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return Catalog::namesPlatform($message) ? __('agents.flow_warning_other') : $message;
    }

    private function atTelenow(Agent $agent): void
    {
        if ($agent->telenow_id === null || ! is_string($agent->workspace->telenow_key)) {
            throw new AgentRefused(409, __('agents.not_ready'));
        }
    }

    private function ready(Agent $agent): void
    {
        $this->atTelenow($agent);
        if ($agent->sync_state !== 'synced') {
            throw new AgentRefused(409, __('agents.version_wait'));
        }
    }
}
