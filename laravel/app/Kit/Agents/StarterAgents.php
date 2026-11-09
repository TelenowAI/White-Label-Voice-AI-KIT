<?php

namespace App\Kit\Agents;

use App\Kit\Audit\Audit;
use App\Kit\Voices\OwnVoiceNames;
use App\Models\Agent;
use App\Models\AgentWebhook;
use App\Models\StarterAgent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Validator;

/**
 * Starter agents (Admin → Starter agents; console → New agent; contract/kit-api.yaml listStarterAgents, previewStarterAgent, publishStarterAgent,
 * updateStarterAgent, listOfferedStarterAgents, createAgentFromStarter): ready-made agents the operator offers every account. Never Telenow's
 * templates, which are one marketplace for every Telenow account.
 *
 * - Published only from an agent in an account the publishing staff member owns (their own demo account, say), never a customer's: a starter goes to
 *   every account, and an agent's prompt, lines and steps carry its account's business. The preview shows every piece of free text it would carry, and
 *   publishing takes the staff member's word that they reviewed it.
 * - A starter is a snapshot taken without anything of that account: prompt, description, every call setting, the voice setup (not an own cloned voice:
 *   it is that account's), provider settings, the realtime voice, analysis fields, and the published call steps with every tool, hand-over and
 *   transfer step made an ending. Never: keys, tools, look-ups, hand-overs, transfers, knowledge, webhooks, after-call email recipients.
 * - A customer's new agent is made from it through the duplicate's own path (AgentDuplicate::made, ::steps): the same create, the same fallback to the
 *   defaults when the voice setup is not offered, steps published or left as a draft.
 *
 * Not here: duplicating within an account (AgentDuplicate).
 */
final class StarterAgents
{
    /** Settings a starter never carries: they name the source's numbers, members or tools. */
    private const ACCOUNT_SETTINGS = ['transfer', 'emailAfterCall', 'sendCallerToTools'];

    /** Call steps that reach out of the call: a tool's address, another agent of the account, the account's numbers. */
    private const STEPS_ENDED = ['tool', 'agent', 'transfer'];

    public function __construct(private readonly AgentDuplicate $duplicate, private readonly AgentSettings $settings, private readonly Audit $audit) {}

    /** @return list<array{id: string, name: string, agents: list<array{id: string, name: string}>}> the accounts the staff member owns, with their agents */
    public function sources(User $staff): array
    {
        return Workspace::query()->whereIn('id', $this->owned($staff))->orderBy('name')->get()->map(fn (Workspace $w) => [
            'id' => $w->id, 'name' => $w->name,
            'agents' => Agent::query()->where('workspace_id', $w->id)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])->map(fn (Agent $a) => ['id' => $a->id, 'name' => $a->name])->all(),
        ])->all();
    }

    /** What a starter from this agent would carry, its free text in full for review, and what it leaves out. */
    public function preview(User $staff, array $input): array
    {
        $source = $this->source($staff, $input);
        $snapshot = $this->snapshot($source);

        return ['agent' => ['id' => $source->id, 'name' => $source->name, 'account' => $source->workspace->name],
            'texts' => $this->texts($snapshot), 'leftOut' => $snapshot['leftOut'], 'steps' => count($snapshot['flow']['steps'] ?? [])];
    }

    public function publish(User $staff, array $input): StarterAgent
    {
        $values = Validator::make($input, [
            'agentId' => ['required', 'string'],
            'name' => ['required', 'string', 'max:'.config('kit.agents.name_max_length')],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.config('kit.starters.description_max_length')],
            'reviewed' => ['accepted'],
        ], ['reviewed.accepted' => __('starters.review_first')], __('starters.fields'))->validate();
        $source = $this->source($staff, $values);
        $snapshot = $this->snapshot($source);
        $starter = (new StarterAgent)->forceFill([
            'name' => trim($values['name']), 'description' => $values['description'] ?? null,
            'agent' => $snapshot['agent'], 'flow' => $snapshot['flow'],
            'source_agent_id' => $source->id, 'source_agent_name' => $source->name, 'source_account_name' => $source->workspace->name,
            'published_by' => $staff->id, 'published_at' => now(),
        ]);
        $starter->save();
        $this->audit->record($staff, 'starters.published', 'starter_agent', $starter->id, ['name' => $starter->name, 'agent' => $source->name, 'account' => $source->workspace->name]);

        return $starter;
    }

    /** Its name and description, and whether it is offered. */
    public function change(User $staff, StarterAgent $starter, array $input): StarterAgent
    {
        $values = Validator::make($input, [
            'name' => ['sometimes', 'string', 'max:'.config('kit.agents.name_max_length')],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.config('kit.starters.description_max_length')],
            'offered' => ['sometimes', 'boolean'],
        ], [], __('starters.fields'))->validate();
        $was = ['name' => $starter->name, 'description' => $starter->description, 'offered' => $starter->withdrawn_at === null];
        if (array_key_exists('name', $values)) {
            $starter->name = trim($values['name']);
        }
        if (array_key_exists('description', $values)) {
            $starter->description = $values['description'];
        }
        if (array_key_exists('offered', $values) && (bool) $values['offered'] !== $was['offered']) {
            $starter->withdrawn_at = $values['offered'] ? null : now();
            $this->audit->record($staff, $values['offered'] ? 'starters.offered' : 'starters.withdrawn', 'starter_agent', $starter->id, ['name' => $starter->name]);
        }
        if ($starter->isDirty(['name', 'description'])) {
            $this->audit->record($staff, 'starters.changed', 'starter_agent', $starter->id, ['name' => $starter->name, 'was' => $was['name']]);
        }
        $starter->save();

        return $starter;
    }

    /** @return array{agent: Agent, toRedo: list<array{kind: string, name: ?string}>} a new agent in the account, made from an offered starter */
    public function createFrom(Workspace $workspace, StarterAgent $starter): array
    {
        $toRedo = [];
        $doc = $starter->agent;
        $name = $this->duplicate->freeName($workspace->id, $starter->name, '', 'agents.name_n');
        $input = ['name' => $name, 'description' => $doc['description'] ?? null, 'systemPrompt' => $doc['systemPrompt'] ?? null, 'settings' => $doc['settings'] ?? []];
        $agent = $this->duplicate->made($workspace, $input, $doc['voice'] ?? [], $doc['stack'] ?? [], $toRedo);
        $agent = $this->duplicate->steps($starter->flow, null, $agent, $toRedo);

        return ['agent' => $agent, 'toRedo' => $toRedo];
    }

    /** @return list<string> the ids of the accounts the staff member is an owner of */
    private function owned(User $staff): array
    {
        return WorkspaceMember::query()->where('user_id', $staff->id)->where('role', 'owner')->pluck('workspace_id')->all();
    }

    /** The agent named, live, in an account the staff member owns; any other is "not found", whatever account it is in. */
    private function source(User $staff, array $input): Agent
    {
        $id = Validator::make($input, ['agentId' => ['required', 'string']], [], __('starters.fields'))->validate()['agentId'];
        $agent = Agent::query()->with('workspace')->whereIn('workspace_id', $this->owned($staff))->whereNull('deleted_at')->find($id);

        return $agent ?? throw new AgentRefused(404, __('starters.agent_not_yours'));
    }

    /** @return array{agent: array, flow: ?array, leftOut: list<array{kind: string, count: int, names: list<string>}>} */
    private function snapshot(Agent $source): array
    {
        $now = $this->settings->of($source);
        $settings = array_diff_key($this->duplicate->plain($now), array_flip(self::ACCOUNT_SETTINGS));
        $voice = $this->duplicate->voiceSetup($source, $now);
        $leftOut = [];
        $left = function (string $kind, array $names = [], int $count = 0) use (&$leftOut) {
            if ($names !== [] || $count > 0) {
                $leftOut[] = ['kind' => $kind, 'count' => max($count, count($names)), 'names' => $names];
            }
        };
        if (array_filter($voice, OwnVoiceNames::isOwn(...)) !== []) {
            $voice = []; // the account's own cloned voice is that account's: the starter takes the defaults
            $left('ownVoice', [], 1);
        }
        $left('tools', array_column($now['tools'] ?? [], 'name'));
        $left('lookups', array_column($now['lookups'] ?? [], 'name'));
        $left('handoff', [], count($now['handoff']['agents'] ?? []));
        $left('transfer', [], ($now['transfer']['enabled'] ?? false) ? 1 : 0);
        $left('emailAfterCall', [], count($now['emailAfterCall']['recipients'] ?? []));
        $left('knowledge', $source->knowledgeBases()->whereNull('knowledge_bases.deleted_at')->pluck('knowledge_bases.name')->all());
        $left('webhooks', [], AgentWebhook::query()->where('agent_id', $source->id)->whereNull('deleted_at')->count());
        $flow = $now['flow'] ?? null;
        if ($flow !== null) {
            $ended = [];
            foreach ($flow['steps'] as $i => $step) {
                if (in_array($step['kind'], self::STEPS_ENDED, true)) {
                    $ended[] = $step['id'];
                    $left('steps', [$step['name'] ?? $step['id']]);
                    $flow['steps'][$i] = array_replace($step, ['kind' => 'end', 'line' => null, 'anywhere' => null, 'tool' => null, 'results' => [], 'sends' => [], 'transfer' => null, 'handoff' => null]);
                }
            }
            $flow['exits'] = array_values(array_filter($flow['exits'], fn (array $e) => ! in_array($e['from'], $ended, true)));
        }
        // One entry per kind: several ended steps make one `steps` line.
        $merged = [];
        foreach ($leftOut as $row) {
            $merged[$row['kind']] = isset($merged[$row['kind']])
                ? ['kind' => $row['kind'], 'count' => $merged[$row['kind']]['count'] + $row['count'], 'names' => [...$merged[$row['kind']]['names'], ...$row['names']]]
                : $row;
        }

        return ['agent' => ['description' => $source->description, 'systemPrompt' => $source->system_prompt, 'settings' => $settings, 'voice' => $voice,
            'stack' => AgentDuplicate::stackOf($source)], 'flow' => $flow, 'leftOut' => array_values($merged)];
    }

    /**
     * Every piece of free text a starter carries, for the operator to read before publishing; `of` says which step it is in (its name), or which
     * steps an exit joins ("from → to").
     *
     * @return list<array{where: string, key: ?string, of: ?string, text: string}>
     */
    private function texts(array $snapshot): array
    {
        $out = [];
        $add = function (string $where, ?string $key, mixed $text, ?string $of = null) use (&$out) {
            if (is_string($text) && trim($text) !== '') {
                $out[] = ['where' => $where, 'key' => $key, 'of' => $of, 'text' => $text];
            }
        };
        $add('description', null, $snapshot['agent']['description']);
        $add('prompt', null, $snapshot['agent']['systemPrompt']);
        foreach ($snapshot['agent']['settings'] as $key => $value) {
            if (str_starts_with($key, 'greeting') || str_ends_with($key, 'Message')) {
                $add('setting', $key, $value);
            }
        }
        foreach ([...($snapshot['agent']['settings']['analysis']['customFields'] ?? []), ...($snapshot['agent']['settings']['analysis']['qaCriteria'] ?? [])] as $field) {
            $add('analysis', $field['key'] ?? null, trim(($field['label'] ?? '').' '.($field['description'] ?? '')));
        }
        $named = [];
        foreach ($snapshot['flow']['steps'] ?? [] as $step) {
            $named[$step['id']] = (string) (($step['name'] ?? '') !== '' ? $step['name'] : $step['id']);
            foreach (['name', 'instructions', 'line'] as $part) {
                $add('step', $part, $step[$part] ?? null, $named[$step['id']]);
            }
        }
        foreach ($snapshot['flow']['exits'] ?? [] as $exit) {
            $joins = ($named[$exit['from']] ?? $exit['from']).' → '.($named[$exit['to']] ?? $exit['to']);
            $add('exit', 'label', $exit['label'] ?? null, $joins);
            $add('exit', 'when', $exit['when']['describe'] ?? null, $joins);
        }

        return $out;
    }
}
