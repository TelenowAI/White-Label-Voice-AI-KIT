<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Reports\CostReport;
use App\Kit\Reports\Dashboard;
use App\Kit\Reports\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Dashboard and Admin → Cost vs price (contract/kit-api.yaml getAdminDashboard, getCostReport), over the days
 * `from`–`to` asked (default: this month so far). Not here: the figures (App\Kit\Reports).
 */
final class ReportsController
{
    public function dashboard(Request $request, Dashboard $dashboard): JsonResponse
    {
        return response()->json($dashboard->build(Period::of($request->query())));
    }

    public function costs(Request $request, CostReport $report): JsonResponse
    {
        return response()->json($report->build(Period::of($request->query()), $request->query()) + [
            'groups' => array_map(fn (string $g) => ['value' => $g, 'label' => __("reports.groups.{$g}")], config('kit.reports.groups')),
        ]);
    }
}
