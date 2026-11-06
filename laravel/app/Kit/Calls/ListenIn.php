<?php

namespace App\Kit\Calls;

use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Call;
use App\Models\CallListen;
use App\Models\WorkspaceMember;

/**
 * A manager listening in to one of the account's live calls: they hear both sides and cannot be heard. Telenow does the listening (`listenToCall`, with the account's own key): a one-time ticket good for 30 s,
 * and the socket that streams the call's sound, which the console opens itself, as it does a test call's (recorded, contract_k10listen.py). The kit answers only that socket's address.
 *
 * Only the account's managers may (config('kit.team.managers'), as Telenow lets only an account's owner or admin), and not for calls Telenow has no sound for (config('kit.calls.listen_unheard_channels')).
 * Each time someone starts listening is kept (`call_listens`) and shown on the call's page: who listened is the team's business. Listening opens no phone leg, so it costs nothing and needs no yes from the operator.
 *
 * Not here: which calls are on (LiveCalls), playing the sound (console calls/ListenIn.tsx).
 */
final class ListenIn
{
    public function __construct(private readonly TelenowClient $telenow) {}

    public function manages(WorkspaceMember $member): bool
    {
        return in_array($member->role, config('kit.team.managers'), true);
    }

    /** Whether this member may listen in to this call now: they manage the account, the call is on, and Telenow has its sound. */
    public function mayListen(WorkspaceMember $member, Call $call): bool
    {
        return $this->manages($member) && ! in_array($call->status, config('kit.calls.ended_statuses'), true)
            && ! in_array($call->channel, config('kit.calls.listen_unheard_channels'), true);
    }

    /** @return string the address the browser listens on, its ticket in it */
    public function start(WorkspaceMember $member, Call $call): string
    {
        if (! $this->manages($member)) {
            throw new ListenRefused(403, __('calls.listen_only_managers'));
        }
        if (in_array($call->channel, config('kit.calls.listen_unheard_channels'), true)) {
            throw new ListenRefused(409, __('calls.listen_not_heard'));
        }
        if (in_array($call->status, config('kit.calls.ended_statuses'), true)) {
            throw new ListenRefused(409, __('calls.listen_call_over'));
        }
        $workspace = $call->workspace;
        if (! is_string($workspace->telenow_key) || ! is_string($workspace->telenow_sub_account_id)) {
            throw new ListenRefused(409, __('calls.account_not_ready'));
        }
        try {
            $ticket = $this->telenow->listenToCall($workspace->telenow_key, $workspace->telenow_sub_account_id, $call->telenow_id);
        } catch (TelenowError $e) {
            if (in_array($e->status, [404, 409], true)) {
                // Over, or never Telenow's to hear: read it again soon, so the kit's copy says so.
                if ($call->detail_state === 'pending') {
                    $call->forceFill(['next_read_at' => now()])->save();
                }
                throw new ListenRefused(409, __('calls.listen_call_over'));
            }
            throw new ListenRefused(503, __('calls.listen_unavailable'));
        }
        $url = $ticket['wsUrl'] ?? null;
        if (! is_string($url) || preg_match('#^wss?://#', $url) !== 1) {
            throw new ListenRefused(503, __('calls.listen_unavailable'));
        }
        $row = new CallListen;
        $row->forceFill(['workspace_id' => $call->workspace_id, 'call_id' => $call->id, 'user_id' => $member->user_id])->save();

        return $url;
    }

    /** @return list<array{by: ?string, at: string}> who listened in to a call, newest first, at most config('kit.calls.listens_shown') */
    public function listeners(Call $call): array
    {
        return CallListen::query()->with('listener')->where('call_id', $call->id)->orderByDesc('created_at')->orderByDesc('id')
            ->limit(config('kit.calls.listens_shown'))->get()
            ->map(fn (CallListen $l) => ['by' => $l->listener?->name, 'at' => $l->created_at->toIso8601String()])->all();
    }
}
