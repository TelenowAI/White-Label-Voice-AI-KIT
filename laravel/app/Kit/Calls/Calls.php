<?php

namespace App\Kit\Calls;

use App\Kit\Agents\AfterCallEmail;
use App\Kit\Manifest\Manifest;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Kit\Wallet\CallCharges;
use App\Models\Agent;
use App\Models\Call;
use App\Models\Workspace;
use Illuminate\Support\Arr;

/**
 * The kit's copy of each customer's calls (SELF_HOSTED_KIT_PLAN.md §3.8 b-d): Telenow writes calls; the kit reads them
 * with the customer's own key and keeps them, and the console reads only the copy. Which kit column is which Telenow
 * field, the extra reads (the analysis) and the feed's parameters come from the manifest (contract/resources.yaml
 * `calls`).
 *
 * - read(): one call — on its `call.completed` event, or when the feed says it changed. A call is re-read until it is
 *   over and none of the parts the console shows (config('kit.calls.wait_for')) is still `pending` in Telenow's
 *   `completeness`, at base × 2^(reads − 1) seconds, at most config('kit.calls.max_reads') times.
 * - feed(): every call of the account created or changed since the feed's last position (Telenow's cursor; the
 *   account's creation before there is one), oldest change first, so a call whose event never arrived still does.
 *
 * A call read as far as it will be is handed to App\Kit\Wallet\CallCharges to be charged, and one with what its email says (config('kit.agents.after_call_email_waits_for')) to
 * App\Kit\Agents\AfterCallEmail to tell the team, without waiting for a recording.
 *
 * keepListed() keeps calls Telenow lists by other means the same way: the calls on now (LiveCalls).
 *
 * Not here: events (App\Kit\Events, Events\CallCompleted), what the console shows (CallsController).
 */
final class Calls
{
    public const RESOURCE = 'calls';

    public function __construct(private readonly TelenowClient $telenow, private readonly Manifest $manifest, private readonly CallCharges $charges, private readonly AfterCallEmail $afterCallEmail) {}

    /** Read one call from Telenow and keep it. Answers null when Telenow does not have it for this account. */
    public function read(Workspace $workspace, string $telenowId): ?Call
    {
        [$key, $org] = $this->account($workspace);
        try {
            $answer = $this->telenow->call($key, $org, $telenowId);
        } catch (TelenowError $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
        $object = $this->manifest->object(self::RESOURCE, 'read', $answer);
        $columns = $this->manifest->columns(self::RESOURCE, $object);
        $call = $this->row($workspace, $telenowId);
        foreach ($this->manifest->resource(self::RESOURCE)['extraReads'] ?? [] as $extra) {
            if (($columns['completeness'][$extra['whenPresent']] ?? null) === 'present' && $call->getAttribute($extra['column']) === null) {
                $columns[$extra['column']] = Arr::get($this->extra($extra['operationId'], $key, $org, $telenowId), $extra['objectAt'] ?? '');
            }
        }
        $reads = $call->reads + 1;
        $agentId = $this->agentId($workspace, $object['agent_id'] ?? null);
        $final = $this->final($columns, $agentId);
        $wasOpen = ! in_array($call->detail_state, ['final', 'gave_up'], true);
        $call->forceFill($columns + [
            'agent_id' => $agentId,
            'reads' => $reads,
            'detail_state' => $final ? 'final' : ($reads >= config('kit.calls.max_reads') ? 'gave_up' : 'pending'),
            'next_read_at' => $final ? null : now()->addSeconds($this->wait($reads)),
        ])->save();
        if ($call->detail_state !== 'pending') {
            $this->charges->queue($call); // its cost, once Telenow has rated it: a part that never came is no reason not to charge
        }
        if ($wasOpen && ($call->detail_state !== 'pending' || $this->arrived($columns, config('kit.agents.after_call_email_waits_for')))) {
            $this->tellTheTeam($call);
        }

        return $call;
    }

    /** An email that cannot be made must not lose the call it is about. */
    private function tellTheTeam(Call $call): void
    {
        try {
            $this->afterCallEmail->send($call);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Read the account's calls feed on from where it stopped, up to config('kit.calls.feed_pages_per_run') pages. Answers how many calls it saw. */
    public function feed(Workspace $workspace): int
    {
        [$key, $org] = $this->account($workspace);
        $backfill = $this->manifest->resource(self::RESOURCE)['backfill'];
        $seen = 0;
        for ($page = 0; $page < config('kit.calls.feed_pages_per_run'); $page++) {
            $position = $workspace->calls_cursor !== null
                ? [$backfill['cursorParameter'] => $workspace->calls_cursor]
                : [$backfill['startParameter'] => $workspace->created_at->utc()->format('Y-m-d\TH:i:s.v\Z')];
            $answer = $this->telenow->callsFeed($key, $org, $position);
            $rows = $answer['calls'] ?? [];
            foreach ($rows as $row) {
                $this->listed($workspace, $row);
                $seen++;
            }
            if (is_string($answer['nextCursor'] ?? null)) {
                $workspace->forceFill(['calls_cursor' => $answer['nextCursor']])->save();
            }
            if ($rows === []) {
                break;
            }
        }
        $workspace->forceFill(['calls_synced_at' => now()])->save();

        return $seen;
    }

    /** Re-read the calls that are due, up to config('kit.calls.reads_per_run'). Answers how many were read. */
    public function readDue(): int
    {
        $read = 0;
        $due = Call::query()->with('workspace')->where('detail_state', 'pending')->where('next_read_at', '<=', now())
            ->orderBy('next_read_at')->limit(config('kit.calls.reads_per_run'))->get();
        foreach ($due as $call) {
            if (! is_string($call->workspace->telenow_key)) {
                // Its customer is closed and its key forgotten: nothing more can be read.
                $call->forceFill(['detail_state' => 'gave_up', 'next_read_at' => null])->save();

                continue;
            }
            try {
                $this->read($call->workspace, $call->telenow_id);
                $read++;
            } catch (TelenowError) {
                $call->forceFill(['next_read_at' => now()->addSeconds($this->wait($call->reads + 1))])->save();
            }
        }

        return $read;
    }

    /**
     * Calls Telenow lists for the account (the calls on now, App\Kit\Calls\LiveCalls), kept as the feed keeps them.
     *
     * @return list<Call> the kit's copy of each, in the order given
     */
    public function keepListed(Workspace $workspace, array $rows): array
    {
        return array_values(array_filter(array_map(fn ($row) => is_array($row) ? $this->listed($workspace, $row) : null, $rows)));
    }

    /** A call as the feed lists it: its listed fields now; its transcript, recordings and parts on its next read. */
    private function listed(Workspace $workspace, array $row): ?Call
    {
        $id = $row['id'] ?? null;
        if (! is_string($id)) {
            return null;
        }
        $columns = $this->manifest->columns(self::RESOURCE, $row);
        $call = $this->row($workspace, $id);
        $updated = $columns['telenow_updated_at'] ?? null;
        if ($call->exists && is_string($updated) && $call->telenow_updated_at === $updated) {
            return $call; // listed again, unchanged
        }
        $call->forceFill($columns + [
            'agent_id' => $this->agentId($workspace, $row['agent_id'] ?? null),
            'detail_state' => 'pending',
            'reads' => 0,
            'next_read_at' => now(),
        ])->save();

        return $call;
    }

    private function row(Workspace $workspace, string $telenowId): Call
    {
        return Call::query()->where('telenow_id', $telenowId)->first()
            ?? (new Call)->forceFill(['workspace_id' => $workspace->id, 'telenow_id' => $telenowId]);
    }

    /**
     * Over, and nothing the console shows still pending. Telenow says a recording is `pending` for as long as there is none, and never finds one missing on an agent that records
     * nothing (its integrity worker leaves those out), so such an agent has no recording to wait for.
     */
    private function final(array $columns, ?string $agentId): bool
    {
        $parts = config('kit.calls.wait_for');
        if ($agentId !== null && (Agent::query()->whereKey($agentId)->value('settings')['recording'] ?? true) === false) {
            $parts = array_diff($parts, ['recording']);
        }

        return $this->arrived($columns, $parts);
    }

    /** Over (config('kit.calls.ended_statuses')), and none of these parts still pending. */
    private function arrived(array $columns, array $parts): bool
    {
        if (! in_array($columns['status'] ?? null, config('kit.calls.ended_statuses'), true)) {
            return false;
        }
        foreach ($parts as $part) {
            if (($columns['completeness'][$part] ?? null) === 'pending') {
                return false;
            }
        }

        return true;
    }

    private function agentId(Workspace $workspace, mixed $telenowAgentId): ?string
    {
        return is_string($telenowAgentId)
            ? Agent::query()->where('workspace_id', $workspace->id)->where('telenow_id', $telenowAgentId)->value('id')
            : null;
    }

    /** The manifest's extra reads, by Telenow operationId. */
    private function extra(string $operationId, string $key, string $org, string $callId): array
    {
        return match ($operationId) {
            'getCallAnalysis' => $this->telenow->callAnalysis($key, $org, $callId),
            default => throw new \LogicException("No reader for {$operationId}"),
        };
    }

    /** @return array{0: string, 1: string} the account's own key and its sub-account id */
    private function account(Workspace $workspace): array
    {
        $key = $workspace->telenow_key;
        $org = $workspace->telenow_sub_account_id;
        if (! is_string($key) || $key === '' || ! is_string($org)) {
            throw new TelenowError(0, __('calls.account_not_ready'));
        }

        return [$key, $org];
    }

    private function wait(int $reads): int
    {
        return (int) min(config('kit.calls.reread_base_seconds') * (2 ** max(0, $reads - 1)), config('kit.calls.reread_max_seconds'));
    }
}
