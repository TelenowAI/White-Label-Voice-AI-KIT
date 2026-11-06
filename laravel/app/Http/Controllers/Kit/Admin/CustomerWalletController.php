<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Audit\Audit;
use App\Kit\Pricing\Pricing;
use App\Kit\Wallet\SpendLimit;
use App\Kit\Wallet\Wallet;
use App\Models\Call;
use App\Models\PriceList;
use App\Models\PhoneNumber;
use App\Models\WalletEntry;
use App\Models\Workspace;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Admin → a customer → Wallet (contract/kit-api.yaml getCustomerWallet, addCustomerCredit, setCustomerPlan): its balance,
 * its plan, the spend limit last set at Telenow, its calls not yet charged, and its entries — each charge beside what
 * Telenow charged for the call and the margin (the operator's cost versus price, §4.6 c); adding credit by hand
 * (a bank transfer, say: the manual gateway); moving it to another plan.
 */
final class CustomerWalletController
{
    public function __construct(private readonly Wallet $wallet, private readonly Pricing $pricing, private readonly SpendLimit $spendLimit, private readonly Audit $audit) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $workspace = $this->find($id);
        if ($workspace === null) {
            return $this->notFound();
        }
        $perPage = config('kit.wallet.entries_per_page');
        $page = max(1, (int) $request->query('page', 1));
        $entries = WalletEntry::query()->where('workspace_id', $workspace->id)->orderByDesc('id');
        $uncharged = Call::query()->where('workspace_id', $workspace->id)->whereIn('charge_state', ['pending', 'refused'])
            ->selectRaw('charge_state, count(*) as n')->groupBy('charge_state')->pluck('n', 'charge_state');

        return response()->json([
            'currency' => $this->pricing->settings()['currency'],
            'balance' => $workspace->balance,
            'plan' => $workspace->price_list_id,
            'effectivePlan' => $this->pricing->planOf($workspace),
            'inForce' => $this->pricing->inForce(),
            'spendLimitUsd' => $workspace->spend_limit_usd,
            'spendLimitAt' => $workspace->spend_limit_at?->toIso8601String(),
            'calls' => ['waiting' => (int) ($uncharged['pending'] ?? 0), 'unpriced' => (int) ($uncharged['refused'] ?? 0)],
            'entries' => $entries->clone()->forPage($page, $perPage)->get()->map($this->entry(...))->all(),
            'total' => $entries->count(), 'page' => $page, 'perPage' => $perPage,
        ]);
    }

    public function credit(Request $request, string $id): JsonResponse
    {
        $workspace = $this->find($id);
        if ($workspace === null) {
            return $this->notFound();
        }
        if (! $this->pricing->inForce()) {
            return response()->json(['message' => __('wallet.not_in_force')], 409);
        }
        $values = $request->validate([
            'amount' => ['required', 'string', 'regex:/^(0|[1-9][0-9]*)(\.[0-9]{1,8})?$/', 'not_regex:/^0(\.0+)?$/'],
            'note' => ['nullable', 'string', 'max:200'],
            'reference' => ['required', 'uuid'],
        ], ['amount.*' => __('wallet.amount')]);
        $entry = $this->wallet->credit($workspace, $values['amount'], $this->pricing->settings()['currency'], $values['note'] ?? null, $request->user(), $values['reference']);
        if ($entry->wasRecentlyCreated) {
            $this->audit->record($request->user(), 'customer.credited', Audit::CUSTOMER, $workspace->id,
                ['amount' => $entry->amount, 'currency' => $entry->currency, 'note' => $entry->note]);
        }

        return response()->json($this->entry($entry), $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function setPlan(Request $request, string $id): JsonResponse
    {
        $workspace = $this->find($id);
        if ($workspace === null) {
            return $this->notFound();
        }
        $values = $request->validate(['priceListId' => ['present', 'nullable', 'string']]);
        if ($values['priceListId'] !== null && ! PriceList::query()->whereKey($values['priceListId'])->exists()) {
            return response()->json(['message' => __('pricing.unknown_plan'), 'errors' => ['priceListId' => [__('pricing.unknown_plan')]]], 422);
        }
        $before = $workspace->price_list_id;
        $workspace->forceFill(['price_list_id' => $values['priceListId']])->save();
        $this->audit->record($request->user(), 'customer.plan_changed', Audit::CUSTOMER, $workspace->id, ['from' => $before, 'to' => $values['priceListId']]);
        $this->spendLimit->request($workspace); // its prices, so what its balance allows, may have changed

        return $this->show($request, $id);
    }

    private function find(string $id): ?Workspace
    {
        return Str::isUuid($id) ? Workspace::query()->find($id) : null;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => __('customers.not_found')], 404);
    }

    private function entry(WalletEntry $e): array
    {
        $cost = $e->details['telenow']['totalUsd'] ?? null;
        $margin = null;
        if (is_string($cost) && is_string($e->details['usdRate'] ?? null)) {
            $paid = BigDecimal::of($e->amount)->negated();
            $margin = (string) $paid->minus(BigDecimal::of($cost)->multipliedBy($e->details['usdRate'])->toScale($paid->getScale(), RoundingMode::HalfUp));
        }

        return [
            'id' => $e->id,
            'kind' => $e->kind,
            'amount' => $e->amount,
            'currency' => $e->currency,
            'balanceAfter' => $e->balance_after,
            'note' => $e->note,
            'callId' => $e->details['call'] ?? null,
                'numberId' => isset($e->details['fee']['number']) ? PhoneNumber::query()->where('telenow_id', $e->details['fee']['number'])->value('id') : null,
            'paymentId' => $e->details['payment'] ?? null,
            'telenowCostUsd' => $cost,
            'margin' => $margin,
            'createdAt' => $e->created_at->toIso8601String(),
        ];
    }
}
