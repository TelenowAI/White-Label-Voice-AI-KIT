<?php

namespace App\Kit\Agents;

use App\Kit\Agents\Telenow\AgentWrite;
use App\Kit\Catalog\Catalog;
use App\Kit\Outbox\Outbox;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Agent;
use Illuminate\Support\Facades\DB;

/**
 * An agent's saved versions: Telenow keeps one for every save that changes something, and can put one back. Only what is safe to show leaves here. A version's rows name who saved it by a
 * made-up address, and its results hold Telenow's charge to the operator. Telenow's change rows are its own words and its own settings' names, so they are read by a table in lang/en/agents.php:
 * a row the table does not know says only that something else changed, with no values.
 *
 * Putting one back writes the version back whole, whatever has happened since (recorded): a transfer the operator has since switched off, a hand-over to an agent since deleted, a voice setup no
 * longer offered, and old keys. So settle() takes off what may not come back, right after, through the same outbox, and says so on the page (`last_restore`). Keys do come back, and the page warns of
 * it. Not in a version at all: the knowledge it answers from (recorded), its web addresses and who is emailed (the kit's or separate rows).
 *
 * Not here: sending the restore (Telenow/RestoreAtTelenow), saves (Agents), the routes (AgentVersionsController, AgentsController).
 */
final class AgentVersions
{
    private const SEPARATOR = ' · ';

    public function __construct(private readonly TelenowClient $telenow, private readonly Outbox $outbox, private readonly Agents $agents, private readonly AgentSettings $settings, private readonly Catalog $catalog) {}

    /** @return list<array<string, mixed>> newest first, the first being the one in use; none until the agent is at Telenow */
    public function list(Agent $agent): array
    {
        if ($agent->telenow_id === null) {
            return [];
        }
        $rows = $this->read(fn () => $this->telenow->agentVersions($this->key($agent), $agent->telenow_id));
        $notes = config('kit.agents.version_notes');
        $out = [];
        foreach ($rows as $i => $row) {
            $note = (string) ($row['note'] ?? '');
            $restored = preg_match($notes['restored'], $note, $m) === 1;
            $out[] = ['version' => (int) ($row['version'] ?? 0), 'at' => is_string($row['createdAt'] ?? null) ? $row['createdAt'] : null, 'name' => is_string($row['name'] ?? null) ? $row['name'] : null,
                'kind' => $restored ? 'restored' : ($note === $notes['created'] ? 'created' : 'changed'), 'restoredFrom' => $restored ? (int) $m[1] : null, 'current' => $i === 0];
        }

        return $out;
    }

    /** @return ?array{version: int, kept: list<string>} what the last putting back left as it was, while that is still news */
    public function lastRestore(Agent $agent): ?array
    {
        $last = $agent->last_restore;
        if (! is_array($last) || ($last['kept'] ?? []) === [] || now()->subHours(config('kit.agents.restore_note_hours'))->gt($last['at'])) {
            return null;
        }

        return ['version' => (int) $last['version'], 'kept' => array_map(fn (string $what) => __("agents.version_kept.{$what}"), $last['kept'])];
    }

    /** @return list<array{label: string, from: ?string, to: ?string}> */
    public function changes(Agent $agent, int $version): array
    {
        $rows = $this->read(fn () => $this->telenow->agentVersionChanges($this->key($agent), $this->atTelenow($agent), $version));
        // Nothing to something empty is Telenow's own default appearing, not a change anyone made.
        $said = array_filter($rows, fn (array $row) => ($row['from'] ?? '') !== '' || ($row['to'] ?? '') !== '');

        return array_map(fn (array $row) => $this->change($row), array_values($said));
    }

    /** @return array{current: array<string, mixed>, previous: ?array<string, mixed>} how calls went while it was live, beside the one before: never what Telenow charged */
    public function results(Agent $agent, int $version): array
    {
        $data = $this->read(fn () => $this->telenow->agentVersionResults($this->key($agent), $this->atTelenow($agent), $version));

        return ['current' => $this->result((array) ($data['current'] ?? [])), 'previous' => is_array($data['previous'] ?? null) ? $this->result($data['previous']) : null];
    }

    public function restore(Agent $agent, int $version): Agent
    {
        $this->atTelenow($agent);
        if ($agent->sync_state !== 'synced') {
            throw new AgentRefused(409, __('agents.version_wait'));
        }
        $rows = $this->list($agent);
        if (! in_array($version, array_column($rows, 'version'), true)) {
            throw new AgentRefused(404, __('agents.version_not_found'));
        }
        if ($rows[0]['version'] === $version) {
            throw new AgentRefused(409, __('agents.version_in_use'));
        }
        $stack = [];
        foreach (config('kit.catalog.kinds') as $parts) {
            foreach ($parts as $column) {
                $stack[$column] = $agent->getAttribute($column);
            }
        }
        DB::transaction(function () use ($agent, $version, $stack) {
            $agent->forceFill(['sync_state' => 'pending'])->save();
            $this->outbox->enqueue(AgentWrite::RESOURCE, $agent->id, 'restore', ['version' => $version, 'stack' => $stack]);
        });
        $this->outbox->runFor(AgentWrite::RESOURCE, $agent->id);
        $agent = $agent->fresh();
        if ($this->outbox->hasFailed(AgentWrite::RESOURCE, $agent->id, 'restore')) {
            // Telenow said no and nothing changed: there is nothing to try again, so the agent goes on as it was. A write taking something off afterwards that is refused is another matter, and shows on the agent as any refused write does.
            $why = (string) $agent->sync_error;
            $this->outbox->cancel(AgentWrite::RESOURCE, $agent->id, $why);
            $agent->forceFill(['sync_state' => 'synced', 'sync_error' => null])->save();
            throw new AgentRefused(409, $why);
        }

        return $agent;
    }

    /**
     * Telenow has put the version back: take off what may not come back with it, as the customer could not have saved it today.
     *
     * @param  array{version: int, stack: array<string, ?string>}  $restore
     */
    public function settle(Agent $agent, array $restore): void
    {
        $kept = [];
        $stack = [];
        foreach (config('kit.catalog.kinds') as $kind => $parts) {
            $was = array_intersect_key($restore['stack'], array_flip($parts));
            $changed = array_filter($was, fn ($value, string $column) => $agent->getAttribute($column) !== $value, ARRAY_FILTER_USE_BOTH) !== [];
            if ($changed && ! $this->catalog->offersStackOf($agent, $kind)) {
                $stack += $was;
                $kept[] = $kind;
            }
        }
        $settings = [];
        $now = $this->settings->of($agent);
        if ($now['transfer']['enabled'] && ! $this->settings->transferAllowed()) {
            $settings['transfer'] = AgentTransfers::OFF;
            $kept[] = 'transfer';
        }
        $targets = $now['handoff']['agents'];
        $alive = Agent::query()->where('workspace_id', $agent->workspace_id)->whereNull('deleted_at')->whereNotNull('telenow_id')->whereIn('id', array_column($targets, 'agentId'))->pluck('id')->all();
        $left = array_values(array_filter($targets, fn (array $target) => in_array($target['agentId'], $alive, true)));
        if (count($left) !== count($targets)) {
            $settings['handoff'] = ['agents' => $left, 'message' => $now['handoff']['message']];
            $kept[] = 'handoff';
        }
        $flow = $this->settings->cleanedFlow($agent);
        if ($flow['kept'] !== []) {
            $settings['flow'] = $flow['flow'];
            $kept = array_values(array_unique([...$kept, ...$flow['kept']]));
        }
        $agent->forceFill(['last_restore' => ['version' => $restore['version'], 'kept' => $kept, 'at' => now()->toIso8601String()]])->save();
        if ($stack !== []) {
            $this->agents->putStackBack($agent, $stack);
        }
        if ($settings !== []) {
            $this->agents->saveChecked($agent, [], $settings, $this->settings->of($agent));
        }
    }

    /** @return array{label: string, from: ?string, to: ?string} Telenow's row in the kit's words */
    private function change(array $row): array
    {
        $parts = explode(self::SEPARATOR, (string) ($row['path'] ?? ''), 2);
        $head = $parts[0];
        $rest = $parts[1] ?? null;
        if ($head === config('kit.agents.version_tool_row') && $rest !== null) {
            $what = (array) __('agents.version_tool_changes');
            $to = explode(self::SEPARATOR, (string) ($row['to'] ?? ''))[0];

            return ['label' => ((array) __('agents.version_tools'))[$rest] ?? __('agents.version_tool', ['name' => $rest]), 'from' => null, 'to' => $what[$to] ?? null];
        }
        if ($head === config('kit.agents.version_flow_row') && $rest === null) {
            $to = explode(' ', (string) ($row['to'] ?? ''))[0];

            return ['label' => __('agents.version_flow'), 'from' => null, 'to' => ((array) __('agents.version_tool_changes'))[$to] ?? null];
        }
        $groups = (array) __('agents.version_groups');
        if ($rest !== null && isset($groups[$head])) {
            $label = ((array) __('agents.version_group_keys'))[$rest] ?? $this->readable($rest);

            return ['label' => "{$groups[$head]}: {$label}", 'from' => $this->value($row['from'] ?? null), 'to' => $this->value($row['to'] ?? null)];
        }
        $fields = (array) __('agents.version_fields');
        if ($rest === null && isset($fields[$head])) {
            return ['label' => $fields[$head], 'from' => $this->value($row['from'] ?? null), 'to' => $this->value($row['to'] ?? null)];
        }

        return ['label' => __('agents.version_other'), 'from' => null, 'to' => null];
    }

    /** A value as words: a whole blob written out as JSON says nothing to a customer, so it only shows that something changed. */
    private function value(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || $value[0] === '{' || $value[0] === '[') {
            return null;
        }

        return ((array) __('agents.version_values'))[$value] ?? $value;
    }

    private function readable(string $key): string
    {
        return strtolower((string) preg_replace('/(?<!^)([A-Z])/', ' $1', str_replace('_', ' ', $key)));
    }

    /** @return array<string, mixed> only what a customer should read: Telenow's charge is the operator's cost, and its per-part timings are its own */
    private function result(array $window): array
    {
        $number = fn (string $key) => is_int($window[$key] ?? null) || is_float($window[$key] ?? null) ? $window[$key] : null;

        return ['calls' => (int) ($window['calls'] ?? 0), 'answeredRate' => $number('answeredRate'), 'avgDurationSec' => $number('avgDurationSec'), 'avgScore' => $number('avgScore'),
            'sentiment' => ['positive' => (int) ($window['sentimentPositive'] ?? 0), 'neutral' => (int) ($window['sentimentNeutral'] ?? 0), 'negative' => (int) ($window['sentimentNegative'] ?? 0)],
            'from' => is_string($window['fromTs'] ?? null) ? $window['fromTs'] : null, 'to' => is_string($window['toTs'] ?? null) ? $window['toTs'] : null];
    }

    private function atTelenow(Agent $agent): string
    {
        if ($agent->telenow_id === null) {
            throw new AgentRefused(409, __('agents.not_ready'));
        }

        return $agent->telenow_id;
    }

    private function key(Agent $agent): string
    {
        $key = $agent->workspace->telenow_key;
        if (! is_string($key) || $key === '') {
            throw new AgentRefused(409, __('agents.account_not_ready'));
        }

        return $key;
    }

    private function read(\Closure $read): array
    {
        try {
            return $read();
        } catch (TelenowError $e) {
            throw $e->status === 404 ? new AgentRefused(404, __('agents.version_not_found')) : new AgentRefused(409, __('agents.version_unreadable'));
        }
    }
}
