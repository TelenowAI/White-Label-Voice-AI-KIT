<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Calls\CallFilters;
use App\Kit\Calls\CallsExport;
use App\Kit\Calls\ListenIn;
use App\Kit\Calls\AgentNoteRefused;
use App\Kit\Calls\AgentNotes;
use App\Kit\Calls\ListenRefused;
use App\Kit\Calls\LiveCalls;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Call;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Console → Calls (contract/kit-api.yaml listCalls, getCall, getCallRecording, getLiveCalls, listenToLiveCall): the current account's calls from the
 * kit's own copy (App\Kit\Calls), newest first, optionally one agent's, one person's by their number however it is written, or those
 * the member placed from the browser; a call with its transcript, recordings and analysis; a recording's audio, streamed through the
 * kit from Telenow with the account's own key (§3.8 b); the calls on now (App\Kit\Calls\LiveCalls); and listening in to one, with who has
 * (App\Kit\Calls\ListenIn). A call of another account is
 * 404. No Telenow id, no Telenow charge, and nothing of the analysis but what it found.
 */
final class CallsController
{
    /** The analysis fields a customer sees. */
    private const ANALYSIS = ['summary', 'sentiment', 'disposition', 'actionItems', 'customData', 'qa'];

    public function __construct(private readonly TelenowClient $telenow, private readonly LiveCalls $live, private readonly ListenIn $listen, private readonly AgentNotes $notes) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = config('kit.calls.per_page');
        $page = max(1, (int) $request->query('page', 1));
        $membership = $this->membership($request);
        $query = Call::query()->with(['agent', 'placer', 'requester'])->where('workspace_id', $membership->workspace_id)
            ->orderByDesc('started_at')->orderByDesc('id');
        CallFilters::apply($query, $request->query());
        if ($request->query('placedBy') === 'me') {
            $query->where('placed_by', $membership->user_id);
        }

        return response()->json([
            'calls' => $query->clone()->forPage($page, $perPage)->get()->map(fn (Call $c) => $this->summary($c, $membership->user_id))->all(),
            'total' => $query->count(), 'page' => $page, 'perPage' => $perPage,
            'outcomes' => Call::query()->where('workspace_id', $membership->workspace_id)->whereNotNull('outcome')->groupBy('outcome')
                ->orderByRaw('count(*) desc')->orderBy('outcome')->limit(config('kit.calls.outcomes_max'))->pluck('outcome')->all(),
        ]);
    }

    /** The same calls as a file (contract exportCalls). */
    public function export(Request $request, CallsExport $export): StreamedResponse
    {
        return $export->download($this->membership($request)->workspace, $request->query());
    }

    public function live(Request $request): JsonResponse
    {
        try {
            return response()->json($this->live->now($this->membership($request)));
        } catch (TelenowError) {
            return response()->json(['message' => __('calls.live_unavailable')], 503);
        }
    }

    /** Start listening in: the address the browser opens, its one-time ticket in it. */
    public function listen(Request $request, string $id): JsonResponse
    {
        $call = $this->find($request, $id);
        if ($call === null) {
            return $this->notFound();
        }
        try {
            return response()->json(['wsUrl' => $this->listen->start($this->membership($request), $call)]);
        } catch (ListenRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    /** Tell the agent on a live call something (AgentNotes); answers the note as kept. */
    public function tellAgent(Request $request, string $id): JsonResponse
    {
        $call = $this->find($request, $id);
        if ($call === null) {
            return $this->notFound();
        }
        try {
            return response()->json(['note' => $this->notes->tell($this->membership($request), $call, $request->all())], 202);
        } catch (AgentNoteRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $call = $this->find($request, $id);

        return $call === null ? $this->notFound() : response()->json($this->detail($call, $this->membership($request)->user_id) + [
            'canListen' => $this->listen->mayListen($this->membership($request), $call),
            'listens' => $this->listen->listeners($call),
            'canTellAgent' => $this->notes->mayTell($this->membership($request), $call),
            'agentNotes' => $this->notes->of($call),
        ]);
    }

    public function recording(Request $request, string $id, string $index): JsonResponse|StreamedResponse
    {
        $call = $this->find($request, $id);
        $recording = $call?->recordings[$index] ?? null;
        // A closed customer's key is forgotten, so the platform can no longer give its recordings.
        if ($call === null || ! is_string($recording['id'] ?? null) || ! is_string($call->workspace->telenow_key)) {
            return response()->json(['message' => __('calls.no_recording')], 404);
        }
        $workspace = $call->workspace;
        try {
            $audio = $this->telenow->recording($workspace->telenow_key, $workspace->telenow_sub_account_id, $recording['id']);
        } catch (TelenowError $e) {
            return response()->json(['message' => __($e->status === 404 ? 'calls.no_recording' : 'calls.recording_unavailable')], $e->status === 404 ? 404 : 503);
        }

        return response()->stream(function () use ($audio) {
            while (! $audio['stream']->eof()) {
                echo $audio['stream']->read(config('kit.calls.stream_chunk_bytes'));
                flush();
            }
        }, 200, ['Content-Type' => $audio['type'], 'Content-Disposition' => 'inline', 'Cache-Control' => 'private, no-store']);
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function find(Request $request, string $id): ?Call
    {
        return Str::isUuid($id)
            ? Call::query()->with(['agent', 'workspace', 'placer', 'requester'])->where('workspace_id', $this->membership($request)->workspace_id)->find($id)
            : null;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => __('calls.not_found')], 404);
    }

    private function summary(Call $c, mixed $userId): array
    {
        return [
            'id' => $c->id,
            'agent' => $c->agent ? ['id' => $c->agent->id, 'name' => $c->agent->name] : null,
            'placedBy' => $c->placed_by === null ? null : ['name' => $c->placer?->name, 'me' => (int) $c->placed_by === (int) $userId],
            'requestedBy' => $c->requested_by === null ? null : ['name' => $c->requester?->name, 'me' => (int) $c->requested_by === (int) $userId],
            'status' => $c->status,
            'outcome' => $c->outcome,
            'direction' => $c->direction,
            'channel' => $c->channel,
            'from' => $c->from_number,
            'to' => $c->to_number,
            'startedAt' => $c->started_at?->toIso8601String(),
            'endedAt' => $c->ended_at?->toIso8601String(),
            'durationSec' => $c->duration_sec,
            'complete' => $c->detail_state !== 'pending',
            'charge' => $c->charge_state === 'charged' ? ['amount' => $c->charge_amount, 'currency' => $c->charge_currency] : null,
            'wrapup' => $c->wrapup,
        ];
    }

    private function detail(Call $c, mixed $userId): array
    {
        return $this->summary($c, $userId) + [
            'answeredBy' => $c->answered_by,
            'transcript' => array_map(fn (array $t) => ['role' => $t['role'] ?? null, 'text' => $t['text'] ?? '', 'at' => $t['at'] ?? null], $c->transcript ?? []),
            'recordings' => array_map(fn (array $r, int $i) => ['index' => $i, 'durationSec' => $r['duration_sec'] ?? null, 'createdAt' => $r['created_at'] ?? null],
                $c->recordings ?? [], array_keys($c->recordings ?? [])),
            'analysis' => is_array($c->analysis) ? array_combine(self::ANALYSIS, array_map(fn (string $k) => $c->analysis[$k] ?? null, self::ANALYSIS)) : null,
        ];
    }
}
