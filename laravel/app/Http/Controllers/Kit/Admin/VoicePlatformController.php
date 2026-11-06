<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Events\Inbox;
use App\Kit\Events\TelenowEvent;
use App\Kit\Health\HealthReport;
use App\Kit\Health\Reconciliation;
use App\Kit\Outbox\Outbox;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin → Voice platform (contract/kit-api.yaml getVoicePlatformHealth, retryVoicePlatformWrite, retryVoicePlatformEvent,
 * reconcileCustomer): the health report, and what staff can do from it — send a refused write again, apply a failed
 * event again, check one customer's money now. Each answers the report as it stands after.
 *
 * Not here: the checks themselves (App\Kit\Health).
 */
final class VoicePlatformController
{
    public function __construct(private readonly HealthReport $report) {}

    public function show(): JsonResponse
    {
        return response()->json($this->report->build());
    }

    public function retryWrite(string $id, Outbox $outbox): JsonResponse
    {
        $write = filter_var($id, FILTER_VALIDATE_INT) !== false ? DB::table('telenow_outbox')->where('id', $id)->whereNull('done_at')->whereNotNull('failed_at')->first() : null;
        if ($write === null) {
            return response()->json(['message' => __('health.write_not_failed')], 404);
        }
        $outbox->retry($write->resource, $write->kit_id);

        return $this->show();
    }

    public function retryEvent(string $id, Inbox $inbox): JsonResponse
    {
        $event = filter_var($id, FILTER_VALIDATE_INT) !== false ? TelenowEvent::query()->whereKey($id)->whereNull('applied_at')->whereNotNull('failed_at')->first() : null;
        if ($event === null) {
            return response()->json(['message' => __('health.event_not_failed')], 404);
        }
        $inbox->retry($event);

        return $this->show();
    }

    public function reconcile(string $id, Reconciliation $reconciliation): JsonResponse
    {
        $workspace = Str::isUuid($id) ? Workspace::query()->find($id) : null;
        if ($workspace === null || $workspace->telenow_sub_account_id === null) {
            return response()->json(['message' => __('health.customer_not_ready')], 404);
        }
        $reconciliation->check($workspace);

        return $this->show();
    }
}
