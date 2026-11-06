<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Console\ServicePause;
use App\Kit\Payments\Gateways;
use App\Kit\Payments\Money;
use App\Kit\Payments\PaymentRefused;
use App\Kit\Payments\Payments;
use App\Kit\Payments\PaymentSettings;
use App\Kit\Pricing\Pricing;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Console → Wallet → Add credit (contract/kit-api.yaml getTopUp, startTopUp, getPayment): the ways to pay this
 * account is offered, the operator's top-up rules, its recent payments; starting one (where the payer goes next); and
 * one payment, which the console reads again while it waits. Any member may top up.
 *
 * A customer sees the gateway's name the operator gave it, the payment's state, and a bank transfer's instructions and
 * reference — never the gateway's own ids or errors.
 *
 * Not here: the money rules (App\Kit\Payments\Payments), the gateway's return and events (PaymentReturnController,
 * PaymentWebhookController).
 */
final class PaymentsController
{
    public function __construct(
        private readonly Payments $payments,
        private readonly Gateways $gateways,
        private readonly PaymentSettings $settings,
        private readonly Pricing $pricing,
        private readonly ServicePause $pause,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->membership($request)->workspace;
        $currency = $this->pricing->settings()['currency'];
        $paused = $this->pause->for($workspace);
        $rules = $this->settings->current();

        return response()->json([
            'inForce' => $this->pricing->inForce(),
            'currency' => $currency,
            'decimals' => $currency === null ? (int) config('kit.payments.default_decimals') : Money::decimals($currency),
            'minAmount' => $rules['minAmount'],
            'maxAmount' => $rules['maxAmount'],
            'paused' => $paused !== null && in_array($paused['reason'], config('kit.payments.blocked_when'), true) ? $paused['message'] : null,
            'gateways' => $this->gateways->offered($workspace)->map(fn (PaymentGateway $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'online' => $this->gateways->driver($g->driver)->online(),
                'methods' => array_map(fn (string $m) => __("payments.methods.{$m}"), $g->methods ?? []),
                'asks' => $this->gateways->asks($g, $request->user()),
            ])->all(),
            'payments' => Payment::query()->where('workspace_id', $workspace->id)->with('gateway')->orderByDesc('created_at')
                ->limit(config('kit.payments.recent'))->get()->map($this->present(...))->all(),
            'pollSeconds' => config('kit.payments.poll_seconds'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $membership = $this->membership($request);
        try {
            [$payment, $checkout] = $this->payments->start($membership->workspace, $request->user(), $request->all());
        } catch (PaymentRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['payment' => $this->present($payment->load('gateway')), 'checkout' => $checkout->toArray()], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $workspace = $this->membership($request)->workspace;
        $payment = Str::isUuid($id) ? Payment::query()->where('workspace_id', $workspace->id)->with('gateway')->find($id) : null;
        if ($payment === null) {
            return response()->json(['message' => __('payments.no_payment')], 404);
        }

        return response()->json($this->present($payment));
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function present(Payment $p): array
    {
        $driver = $this->gateways->driverOf($p->gateway);
        $manual = $driver !== null && ! $driver->online();

        return [
            'id' => $p->id,
            'amount' => $p->amount,
            'currency' => $p->currency,
            'status' => $p->status,
            'gatewayName' => $p->gateway->name,
            'refunded' => $p->refunded,
            'instructions' => $manual && $p->status === Payment::OPEN ? ($p->details['instructions'] ?? null) : null,
            'reference' => $manual ? $p->gateway_ref : null,
            'createdAt' => $p->created_at->toIso8601String(),
            'paidAt' => $p->paid_at?->toIso8601String(),
        ];
    }
}
