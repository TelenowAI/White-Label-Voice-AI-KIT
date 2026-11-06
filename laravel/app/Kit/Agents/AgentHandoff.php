<?php

namespace App\Kit\Agents;

use App\Models\Agent;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Handing a live call to another of the account's agents, in the kit's words: the other agent takes over on the same call, with the conversation
 * so far. As Telenow has it this is one row of `metadata.tools`, `kind: agent` (services/tool.rs resolve_agent_handoff), naming agents by Telenow's
 * ids: the kit's own id goes beside each, so a read needs no query. Each target has a name the agent picks it by, the target's own unless given.
 *
 * Deleting an agent takes it off the others that hand over to it (`Agents::delete`): at Telenow it would stay offered and fail on the call.
 *
 * Not here: transfer to a person, which is a second phone call (AgentSettings), other tools (AgentTools).
 */
final class AgentHandoff
{
    private const NAME = 'handoff_to_agent';

    private const KIND = 'agent';

    public function has(string $key): bool
    {
        return $key === 'handoff';
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['settings.handoff' => ['sometimes', 'array']] + $this->rowRules('settings.handoff');
    }

    /** @return array<string, list<mixed>> the targets and the line, at `$at` */
    public function rowRules(string $at): array
    {
        $l = config('kit.agents.limits');

        return [
            "{$at}.agents" => ["present_with:{$at}", 'array', 'max:'.$l['handoff_agents_max']],
            "{$at}.agents.*.agentId" => ['required', 'string'],
            "{$at}.agents.*.label" => ['sometimes', 'nullable', 'string', 'max:'.$l['transfer_label_max_length']],
            "{$at}.agents.*.mode" => ['required', Rule::in(config('kit.agents.handoff_modes'))],
            "{$at}.message" => ['sometimes', 'nullable', 'string', 'max:'.$l['line_max_length']],
        ];
    }

    /** @return array{agents: list<array{agentId: string, label: string, mode: string}>, message: ?string} */
    public function checked(array $handoff, Agent $agent, string $at = 'settings.handoff'): array
    {
        $errors = [];
        $rows = array_values($handoff['agents'] ?? []);
        $targets = Agent::query()->where('workspace_id', $agent->workspace_id)->whereNull('deleted_at')->whereIn('id', array_column($rows, 'agentId'))->get()->keyBy('id');
        $agents = [];
        $labels = [];
        foreach ($rows as $i => $row) {
            $target = $targets[$row['agentId']] ?? null;
            if ($target === null) {
                $errors["{$at}.agents.{$i}.agentId"] = [__('agents.handoff_unknown')];

                continue;
            }
            if ($target->id === $agent->id) {
                $errors["{$at}.agents.{$i}.agentId"] = [__('agents.handoff_itself')];
            } elseif ($target->telenow_id === null) {
                $errors["{$at}.agents.{$i}.agentId"] = [__('agents.not_ready')];
            }
            $label = $row['label'] ?? $target->name;
            if (in_array(mb_strtolower($label), $labels, true)) {
                $errors["{$at}.agents.{$i}.label"] = [__('agents.handoff_label_twice')];
            }
            $labels[] = mb_strtolower($label);
            $agents[] = ['agentId' => $target->id, 'label' => $label, 'mode' => $row['mode']];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['agents' => $agents, 'message' => $handoff['message'] ?? null];
    }

    /** @return array<string, mixed> */
    public function read(array $metadata): array
    {
        $row = $this->row($metadata);

        return ['handoff' => $this->readConfig(is_array($row['config'] ?? null) ? $row['config'] : [])];
    }

    /** @return array{agents: list<array{agentId: string, label: string, mode: string}>, message: ?string} a row's `config`, as the kit has it */
    public function readConfig(array $config): array
    {
        $agents = [];
        foreach ((array) ($config['agents'] ?? []) as $target) {
            if (is_array($target) && is_string($target['kitAgentId'] ?? null)) {
                $agents[] = ['agentId' => $target['kitAgentId'], 'label' => (string) ($target['label'] ?? ''), 'mode' => ($target['mode'] ?? null) === 'connect' ? 'connect' : 'transfer'];
            }
        }

        return ['agents' => $agents, 'message' => is_string($config['message'] ?? null) && $config['message'] !== '' ? $config['message'] : null];
    }

    private function row(array $metadata): ?array
    {
        foreach ((array) ($metadata['tools'] ?? []) as $row) {
            if (is_array($row) && ($row['kind'] ?? null) === self::KIND) {
                return $row;
            }
        }

        return null;
    }

    public function write(array &$metadata, array $handoff): void
    {
        $rows = array_values(array_filter((array) ($metadata['tools'] ?? []), fn ($row) => ! is_array($row) || ($row['kind'] ?? null) !== self::KIND));
        $config = $this->config($handoff);
        if ($config['agents'] !== []) {
            $rows[] = ['name' => self::NAME, 'kind' => self::KIND, 'description' => config('kit.agents.tool_descriptions.handoff'), 'config' => $config];
        }
        $metadata['tools'] = $rows;
    }

    /** @return array<string, mixed> the row's `config`, by Telenow's ids, each with the kit's own id beside it: an agent since deleted is left out */
    public function config(array $handoff): array
    {
        $ids = Agent::query()->whereIn('id', array_column($handoff['agents'], 'agentId'))->whereNull('deleted_at')->whereNotNull('telenow_id')->pluck('telenow_id', 'id');
        $agents = [];
        foreach ($handoff['agents'] as $target) {
            if (isset($ids[$target['agentId']])) {
                $agents[] = ['agentId' => $ids[$target['agentId']], 'kitAgentId' => $target['agentId'], 'label' => $target['label'], 'mode' => $target['mode']];
            }
        }

        return ['agents' => $agents] + ($handoff['message'] === null ? [] : ['message' => $handoff['message']]);
    }

    /**
     * The account's other agents that hand over to `$agent`, each with the setting that takes it off them.
     *
     * @return list<array{agent: Agent, handoff: array}>
     */
    public function pointingAt(Agent $agent, AgentSettings $settings): array
    {
        $holders = Agent::query()->where('workspace_id', $agent->workspace_id)->whereNull('deleted_at')->where('id', '!=', $agent->id)
            ->where(fn ($q) => $q->where('telenow_snapshot', 'like', '%"kitAgentId":"'.$agent->id.'"%')->orWhere('settings', 'like', '%"agentId":"'.$agent->id.'"%'))->get();

        return $holders->map(function (Agent $holder) use ($agent, $settings) {
            $handoff = $settings->of($holder)['handoff'];
            $handoff['agents'] = array_values(array_filter($handoff['agents'], fn (array $target) => $target['agentId'] !== $agent->id));

            return ['agent' => $holder, 'handoff' => $handoff];
        })->all();
    }
}
