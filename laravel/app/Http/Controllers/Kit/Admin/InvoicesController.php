<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Invoices\CustomerInvoices;
use App\Models\CustomerInvoice;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Admin → Invoices (contract/kit-api.yaml getInvoiceSettings, setInvoiceSettings — ability `money`) and a customer's invoices (listCustomerInvoices,
 * getCustomerInvoice — ability `customers_read`): the operator's details, tax and number prefix printed on the invoices issued from then on, and a
 * customer's invoices as they see them. Not here: App\Kit\Invoices\CustomerInvoices.
 */
final class InvoicesController
{
    public function __construct(private readonly CustomerInvoices $invoices) {}

    public function settings(): JsonResponse
    {
        return response()->json($this->invoices->settings());
    }

    public function saveSettings(Request $request): JsonResponse
    {
        return response()->json($this->invoices->saveSettings($request->all()));
    }

    public function customer(Request $request, string $id): JsonResponse
    {
        $workspace = Str::isUuid($id) ? Workspace::query()->find($id) : null;
        if ($workspace === null) {
            return response()->json(['message' => __('customers.not_found')], 404);
        }

        return response()->json($this->invoices->page($workspace, $request->query('page')) + ['billingDetails' => $this->invoices->billingDetails($workspace)]);
    }

    public function show(string $id): JsonResponse
    {
        $invoice = Str::isUuid($id) ? CustomerInvoice::query()->with('payment.gateway')->find($id) : null;

        return $invoice === null
            ? response()->json(['message' => __('invoices.not_found')], 404)
            : response()->json($this->invoices->present($invoice) + ['customerId' => $invoice->workspace_id]);
    }
}
