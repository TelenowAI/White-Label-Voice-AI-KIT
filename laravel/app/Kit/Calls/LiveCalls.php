<?php

namespace App\Kit\Calls;

use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Agent;
use App\Models\Call;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Cache;

/**
 * An account's calls on now, from Telenow with the account's own key: how many by agent (`countLiveCalls`), and the calls themselves, newest first, at most config('kit.calls.live_calls_listed')
 * (`listCalls` with status active). Telenow counts a call placed from the browser under a stand-in agent of its own (recorded, contract_k9human.py), which is none of the kit's, so whatever is not one of the
 * account's agents is answered as `others`. Each listed call is kept in the kit's copy as the feed keeps it (Calls::keepListed), so it has the kit's id, and says whether this member may listen in (ListenIn).
 *
 * Telenow is asked at most once an account in config('kit.calls.live_cache_seconds'), however many pages are open, since its rate limit is shared by the whole install.
 *
 * Not here: a call's own state (Calls), listening (ListenIn).
 */
final class LiveCalls
{
    public function __construct(private readonly TelenowClient $telenow, private readonly Calls $calls, private readonly ListenIn $listen) {}

    /** @return array{total: int, agents: list<array{id: string, name: string, count: int}>, others: int, calls: list<array<string, mixed>>, canListen: bool, refreshSeconds: int} */
    public function now(WorkspaceMember $member): array
    {
        $workspace = $member->workspace;
        $key = $workspace->telenow_key;
        $org = $workspace->telenow_sub_account_id;
        if (! is_string($key) || $key === '' || ! is_string($org)) {
            throw new TelenowError(0, __('calls.account_not_ready'));
        }
        $seconds = config('kit.calls.live_cache_seconds');
        $live = Cache::remember("kit.calls.live.{$workspace->id}", $seconds, fn () => [
            'counts' => $this->telenow->liveCalls($key),
            'callIds' => array_map(fn (Call $c) => $c->id, $this->calls->keepListed($workspace, $this->telenow->activeCalls($key, $org, config('kit.calls.live_calls_listed')))),
        ]);
        $counts = array_filter(is_array($live['counts']['counts'] ?? null) ? $live['counts']['counts'] : [], fn ($n, $id) => is_string($id) && is_int($n) && $n > 0, ARRAY_FILTER_USE_BOTH);
        $total = is_int($live['counts']['total'] ?? null) ? $live['counts']['total'] : array_sum($counts);
        $agents = Agent::query()->where('workspace_id', $workspace->id)->whereIn('telenow_id', array_keys($counts))->get(['id', 'name', 'telenow_id'])
            ->map(fn (Agent $a) => ['id' => $a->id, 'name' => $a->name, 'count' => $counts[$a->telenow_id]])
            ->sortByDesc('count')->values()->all();
        $order = array_flip($live['callIds']);
        $listed = Call::query()->with(['agent', 'placer'])->where('workspace_id', $workspace->id)->whereIn('id', $live['callIds'])
            ->whereNotIn('status', config('kit.calls.ended_statuses'))->get()->sortBy(fn (Call $c) => $order[$c->id])->values();

        return [
            'total' => $total,
            'agents' => $agents,
            'others' => max(0, $total - array_sum(array_column($agents, 'count'))),
            'calls' => $listed->map(fn (Call $c) => [
                'id' => $c->id,
                'agent' => $c->agent ? ['id' => $c->agent->id, 'name' => $c->agent->name] : null,
                'placedBy' => $c->placed_by === null ? null : ['name' => $c->placer?->name, 'me' => (int) $c->placed_by === (int) $member->user_id],
                'direction' => $c->direction,
                'channel' => $c->channel,
                'from' => $c->from_number,
                'to' => $c->to_number,
                'startedAt' => $c->started_at?->toIso8601String(),
                'canListen' => $this->listen->mayListen($member, $c),
            ])->all(),
            'canListen' => $this->listen->manages($member),
            'refreshSeconds' => $seconds,
        ];
    }
}
