<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Invoices\CustomerInvoices;
use App\Models\CustomerInvoice;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Console → Wallet → Invoices (contract/kit-api.yaml listAccountInvoices, getAccountInvoice, getBillingDetails, setBillingDetails): the account's
 * invoices, one per payment, newest first, a page at a time, and one as its printable page (of any account the person belongs to: the payment
 * email's link names no account) — every member may, as they see the wallet; the account's billing details
 * printed on the next invoices — every member sees them, its managers change them. Not here: App\Kit\Invoices\CustomerInvoices.
 */
final class InvoicesController
{
    public function __construct(private readonly CustomerInvoices $invoices) {}

    public function index(Request $request): JsonResponse
    {
        $m = $this->membership($request);

        return response()->json($this->invoices->page($m->workspace, $request->query('page')) + [
            'billingDetails' => $this->invoices->billingDetails($m->workspace),
            'canEdit' => $this->manages($m),
        ]);
    }

    /** One of the invoices of any account the person belongs to: the payment email's link names no account, and they may have another one open. */
    public function show(Request $request, string $id): JsonResponse
    {
        // Theirs, and the one open (staff looking at a customer's console belong to none).
        $accounts = WorkspaceMember::query()->where('user_id', $request->user()->id)->pluck('workspace_id')->push($this->membership($request)->workspace_id);
        $invoice = Str::isUuid($id) ? CustomerInvoice::query()->with('payment.gateway')->whereIn('workspace_id', $accounts)->find($id) : null;

        return $invoice === null
            ? response()->json(['message' => __('invoices.not_found')], 404)
            : response()->json($this->invoices->present($invoice));
    }

    public function billingDetails(Request $request): JsonResponse
    {
        $m = $this->membership($request);

        return response()->json($this->invoices->billingDetails($m->workspace) + ['canEdit' => $this->manages($m)]);
    }

    public function setBillingDetails(Request $request): JsonResponse
    {
        $m = $this->membership($request);
        if (! $this->manages($m)) {
            return response()->json(['message' => __('invoices.managers_only')], 403);
        }

        return response()->json($this->invoices->setBillingDetails($m->workspace, $request->all()) + ['canEdit' => true]);
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function manages(WorkspaceMember $m): bool
    {
        return in_array($m->role, config('kit.team.managers'), true);
    }
}
