<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Pricing\Pricing;
use App\Kit\Wallet\BalanceAlerts;
use App\Models\PhoneNumber;
use App\Models\WalletEntry;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Console → Wallet (contract/kit-api.yaml getWallet, setWalletAlert): the current account's balance and its entries,
 * newest first — credits, the charge of each call and number fee, and each payment's top-up, refund and disputed
 * amount held or given back. Never what the voice platform charged the operator. Also the balance its low-balance
 * email comes at (App\Kit\Wallet\BalanceAlerts): its own, which the roles that get the email may change, else the
 * operator's.
 */
final class WalletController
{
    public function __construct(private readonly Pricing $pricing, private readonly BalanceAlerts $alerts) {}

    public function show(Request $request): JsonResponse
    {
        /** @var WorkspaceMember $membership */
        $membership = $request->attributes->get('kit.membership');

        return response()->json($this->wallet($membership, max(1, (int) $request->query('page', 1))));
    }

    /** The account's own warning level (`lowBalanceAt`: empty or null for the operator's). Answers the wallet's first page. */
    public function alert(Request $request): JsonResponse
    {
        /** @var WorkspaceMember $membership */
        $membership = $request->attributes->get('kit.membership');
        if (! $this->alerts->mayChoose($membership)) {
            return response()->json(['message' => __('wallet.not_allowed')], 403);
        }
        $this->alerts->setOwn($membership->workspace, $request->input('lowBalanceAt'));

        return response()->json($this->wallet($membership, 1));
    }

    private function wallet(WorkspaceMember $membership, int $page): array
    {
        $workspace = $membership->workspace;
        $perPage = config('kit.wallet.entries_per_page');
        $entries = WalletEntry::query()->where('workspace_id', $workspace->id)->orderByDesc('id');

        return [
            'currency' => $this->pricing->settings()['currency'],
            'balance' => $workspace->balance,
            'inForce' => $this->pricing->inForce(),
            'entries' => $entries->clone()->forPage($page, $perPage)->get()->map(fn (WalletEntry $e) => [
                'id' => $e->id,
                'kind' => $e->kind,
                'amount' => $e->amount,
                'currency' => $e->currency,
                'balanceAfter' => $e->balance_after,
                'note' => $e->note,
                'callId' => $e->details['call'] ?? null,
                'numberId' => isset($e->details['fee']['number']) ? PhoneNumber::query()->where('telenow_id', $e->details['fee']['number'])->value('id') : null,
                'paymentId' => $e->details['payment'] ?? null,
                'createdAt' => $e->created_at->toIso8601String(),
            ])->all(),
            'total' => $entries->count(), 'page' => $page, 'perPage' => $perPage,
            'alert' => [
                'lowBalanceAt' => $workspace->low_balance_at,
                'defaultAt' => $this->alerts->defaultLevel(),
                'mayChange' => $this->alerts->mayChoose($membership),
            ],
        ];
    }
}
