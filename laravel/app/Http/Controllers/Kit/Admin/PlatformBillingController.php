<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Telenow\PlatformBilling;
use App\Kit\Telenow\PlatformBillingRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Platform billing (contract/kit-api.yaml getPlatformBilling, setPlatformBalanceAlert; ability `money`): the operator's own account at the
 * voice platform — what it may still spend, the level at which its balance counts as low (the staff are emailed when it falls to it), and the
 * platform's invoices to it, read live (a failed read leaves the rest of the page: `invoices` null with `invoicesError`). Not here: App\Kit\Telenow\PlatformBilling.
 */
final class PlatformBillingController
{
    public function __construct(private readonly PlatformBilling $billing) {}

    public function show(): JsonResponse
    {
        try {
            $invoices = $this->billing->invoices();
            $error = null;
        } catch (PlatformBillingRefused $e) {
            [$invoices, $error] = [null, $e->getMessage()];
        }

        return response()->json($this->billing->state() + ['invoices' => $invoices, 'invoicesError' => $error]);
    }

    public function alert(Request $request): JsonResponse
    {
        $request->validate(['thresholdUsd' => ['present', 'nullable']]);
        try {
            return response()->json($this->billing->setAlert($request->input('thresholdUsd')));
        } catch (PlatformBillingRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
