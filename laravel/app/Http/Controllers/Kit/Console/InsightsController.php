<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Reports\Insights;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Console → Insights (contract/kit-api.yaml getInsights): how the current account's calls went over a period, for every member, as the calls are. */
final class InsightsController
{
    public function __invoke(Request $request, Insights $insights): JsonResponse
    {
        /** @var WorkspaceMember $membership */
        $membership = $request->attributes->get('kit.membership');

        return response()->json($insights->of($membership->workspace, $request->query()));
    }
}
