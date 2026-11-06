<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Calls\CallWork;
use App\Kit\Calls\CallWorkRefused;
use App\Models\Call;
use App\Models\CallFollowup;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Console → Calls, what members write on a call (contract/kit-api.yaml getCallWork, addCallNote, setCallWrapup, addCallFollowUp), and Console → Follow-ups
 * (listFollowUps, setFollowUp): a member's own, to do or done (App\Kit\Calls\CallWork). Every member may write on any of the account's calls. A call or follow-up of
 * another account is 404.
 */
final class CallWorkController
{
    public function __construct(private readonly CallWork $work) {}

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->onCall($request, $id, fn (Call $call) => response()->json($this->work->of($call, $this->membership($request))));
    }

    public function addNote(Request $request, string $id): JsonResponse
    {
        return $this->onCall($request, $id, function (Call $call) use ($request) {
            $this->work->addNote($call, $request->user(), $request->all());

            return response()->json($this->work->of($call, $this->membership($request)), 201);
        });
    }

    public function setWrapup(Request $request, string $id): JsonResponse
    {
        return $this->onCall($request, $id, function (Call $call) use ($request) {
            $this->work->setWrapup($call, $request->user(), $request->all());

            return response()->json($this->work->of($call->fresh(), $this->membership($request)));
        });
    }

    public function addFollowUp(Request $request, string $id): JsonResponse
    {
        return $this->onCall($request, $id, function (Call $call) use ($request) {
            $this->work->addFollowUp($call, $request->user(), $request->all());

            return response()->json($this->work->of($call, $this->membership($request)), 201);
        });
    }

    public function followUps(Request $request): JsonResponse
    {
        $membership = $this->membership($request);
        $found = $this->work->mine($membership, $request->boolean('done'), max(1, (int) $request->query('page', 1)));

        return response()->json([
            'followUps' => array_map(fn (CallFollowup $f) => $this->work->followUp($f, $membership), $found->items()),
            'total' => $found->total(), 'page' => $found->currentPage(), 'perPage' => $found->perPage(),
        ]);
    }

    public function setFollowUp(Request $request, string $id): JsonResponse
    {
        $membership = $this->membership($request);
        $followUp = Str::isUuid($id) ? CallFollowup::query()->with(['owner', 'finisher', 'call'])->where('workspace_id', $membership->workspace_id)->find($id) : null;
        if ($followUp === null) {
            return response()->json(['message' => __('calls.no_followup')], 404);
        }
        $this->work->setDone($followUp, $request->user(), $request->all());

        return response()->json($this->work->followUp($followUp->fresh(['owner', 'finisher', 'call']), $membership));
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function onCall(Request $request, string $id, \Closure $act): JsonResponse
    {
        $call = Str::isUuid($id) ? Call::query()->with('workspace')->where('workspace_id', $this->membership($request)->workspace_id)->find($id) : null;
        if ($call === null) {
            return response()->json(['message' => __('calls.not_found')], 404);
        }
        try {
            return $act($call);
        } catch (CallWorkRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
