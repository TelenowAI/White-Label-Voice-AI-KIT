<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Audit\Audit;
use App\Kit\Console\ViewAs;
use App\Kit\Customers\CustomerRefused;
use App\Kit\Customers\Customers;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Admin → Customers (contract/kit-api.yaml listCustomers, createCustomer, getCustomer, suspendCustomer,
 * resumeCustomer, retryCustomer, setCustomerLimits, closeCustomer, viewAsCustomer). Reads come from the kit's DB only
 * (§3.8 b); every change goes through Customers, and suspending, resuming, limits, closing and viewing as are in the
 * audit log. A customer's Telenow key never appears in an answer. Staff only (EnsureStaff, each route's ability),
 * after the install (EnsureInstalled).
 */
final class CustomersController
{
    public function __construct(private readonly Customers $customers, private readonly Audit $audit) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = config('kit.admin.per_page');
        $page = max(1, (int) $request->query('page', 1));
        $query = Workspace::query()->orderByDesc('created_at')->orderByDesc('id');

        return response()->json([
            'customers' => $query->clone()->forPage($page, $perPage)->get()->map($this->present(...))->all(),
            'total' => $query->count(),
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $values = $request->validate(['name' => ['required', 'string', 'max:'.config('kit.customers.name_max_length')]]);

        return response()->json($this->present($this->customers->create($values['name'])), 201);
    }

    public function show(string $id): JsonResponse
    {
        $workspace = $this->find($id);

        return $workspace ? response()->json($this->present($workspace)) : $this->notFound();
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $values = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->act($id, fn (Workspace $w) => $this->audited($request, 'customer.suspended', $this->customers->suspend($w, $values['reason'] ?? null),
            ['reason' => $values['reason'] ?? null]));
    }

    public function resume(Request $request, string $id): JsonResponse
    {
        return $this->act($id, fn (Workspace $w) => $this->audited($request, 'customer.resumed', $this->customers->resume($w)));
    }

    public function limits(Request $request, string $id): JsonResponse
    {
        return $this->act($id, fn (Workspace $w) => $this->audited($request, 'customer.limits_changed', $this->customers->setLimits($w, $request->all()),
            ['maxConcurrentCalls' => $request->input('maxConcurrentCalls'), 'monthlyCapUsd' => $request->input('monthlyCapUsd')]));
    }

    /** Close for good. The customer's exact name must be typed to confirm. */
    public function close(Request $request, string $id): JsonResponse
    {
        $workspace = $this->find($id);
        if ($workspace !== null && $request->input('confirm') !== $workspace->name) {
            return response()->json(['message' => __('customers.confirm_close'), 'errors' => ['confirm' => [__('customers.confirm_close')]]], 422);
        }

        return $this->act($id, fn (Workspace $w) => $this->audited($request, 'customer.closing', $this->customers->close($w)));
    }

    /** Look at the console as this customer, read-only, for a while (App\Kit\Console\ViewAs). */
    public function viewAs(Request $request, string $id, ViewAs $viewAs): JsonResponse
    {
        $workspace = $this->find($id);
        if ($workspace === null) {
            return $this->notFound();
        }

        return response()->json(['viewingAs' => $viewAs->start($request, $workspace, $request->user())]);
    }

    private function audited(Request $request, string $action, Workspace $workspace, array $details = []): Workspace
    {
        $this->audit->record($request->user(), $action, Audit::CUSTOMER, $workspace->id, $details);

        return $workspace;
    }

    public function retry(string $id): JsonResponse
    {
        return $this->act($id, fn (Workspace $w) => $this->customers->retry($w));
    }

    private function act(string $id, \Closure $change): JsonResponse
    {
        $workspace = $this->find($id);
        if (! $workspace) {
            return $this->notFound();
        }
        try {
            return response()->json($this->present($change($workspace)));
        } catch (CustomerRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    private function find(string $id): ?Workspace
    {
        return Str::isUuid($id) ? Workspace::query()->find($id) : null;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => __('customers.not_found')], 404);
    }

    private function present(Workspace $w): array
    {
        return [
            'id' => $w->id,
            'name' => $w->name,
            'status' => $w->status,
            'syncState' => $w->sync_state,
            'syncError' => $w->sync_error,
            'telenowSubAccountId' => $w->telenow_sub_account_id,
            'balance' => $w->balance,
            'priceListId' => $w->price_list_id,
            'maxConcurrentCalls' => $w->max_concurrent_calls,
            'monthlyCapUsd' => $w->monthly_cap_usd,
            'closeRequestedAt' => $w->close_requested_at?->toIso8601String(),
            'closedAt' => $w->closed_at?->toIso8601String(),
            'createdAt' => $w->created_at->toIso8601String(),
            'updatedAt' => $w->updated_at->toIso8601String(),
        ];
    }
}
