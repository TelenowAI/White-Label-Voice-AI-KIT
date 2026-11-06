<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Payments\Gateways;
use App\Kit\Payments\PaymentRefused;
use App\Kit\Payments\Payments;
use App\Kit\Payments\PaymentSettings;
use App\Kit\Pricing\Pricing;
use App\Kit\Setup\Field;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PriceList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Admin → Payments (contract/kit-api.yaml getPayments, savePaymentSettings, addPaymentGateway, updatePaymentGateway,
 * removePaymentGateway, orderPaymentGateways, testPaymentGateway, confirmPayment, cancelPayment, refundPayment):
 * - the operator's top-up rules and dispute rule;
 * - the ways to pay, in the order customers see them: each drawn from its driver's own fields (a secret never
 *   answered, kept when left empty), its mode, payment methods, plans, whether it takes the kit's currency, and for an
 *   online one its webhook address, the events to tick and when the last verified one arrived; a test payment
 *   through one in test mode;
 * - the payments waiting for staff (bank transfers, and payments to review) and the recent ones, each with what staff
 *   may do: confirm with the amount that arrived, cancel, refund some or all.
 * Staff see the gateway's own reference and errors; customers never do.
 *
 * Not here: the wizard's Payments step (PaymentsStep), the money rules (App\Kit\Payments\Payments).
 */
final class PaymentsController
{
    public function __construct(
        private readonly Gateways $gateways,
        private readonly Payments $payments,
        private readonly PaymentSettings $settings,
        private readonly Pricing $pricing,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->settings->save($request->all());

        return response()->json($this->payload());
    }

    public function addGateway(Request $request): JsonResponse
    {
        return $this->refusals(function () use ($request) {
            $this->gateways->add($request->all());

            return response()->json($this->payload(), 201);
        });
    }

    public function updateGateway(Request $request, string $id): JsonResponse
    {
        return $this->refusals(function () use ($request, $id) {
            $this->gateways->update($this->gateway($id), $request->all());

            return response()->json($this->payload());
        });
    }

    public function removeGateway(string $id): JsonResponse|Response
    {
        return $this->refusals(function () use ($id) {
            $this->gateways->remove($this->gateway($id));

            return response()->noContent();
        });
    }

    public function orderGateways(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['present', 'array'], 'ids.*' => ['string']]);

        return $this->refusals(function () use ($request) {
            $this->gateways->order($request->input('ids'));

            return response()->json($this->payload());
        });
    }

    /** A test payment through a gateway in test mode: where the staff member goes to pay it. */
    public function testGateway(Request $request, string $id): JsonResponse
    {
        return $this->refusals(function () use ($request, $id) {
            [$payment, $checkout] = $this->payments->startTest($this->gateway($id), $request->user(), $request->all());

            return response()->json(['payment' => $this->present($payment->load('gateway', 'workspace')), 'checkout' => $checkout->toArray()], 201);
        });
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        return $this->refusals(function () use ($request, $id) {
            $this->payments->confirm($this->payment($id), $request->user(), $request->all());

            return response()->json($this->payload());
        });
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->refusals(function () use ($request, $id) {
            $this->payments->cancel($this->payment($id), $request->user());

            return response()->json($this->payload());
        });
    }

    public function refund(Request $request, string $id): JsonResponse
    {
        return $this->refusals(function () use ($request, $id) {
            $this->payments->refund($this->payment($id), $request->user(), $request->all());

            return response()->json($this->payload());
        });
    }

    private function gateway(string $id): PaymentGateway
    {
        return $this->gateways->find($id) ?? throw new PaymentRefused(404, __('payments.no_gateway'));
    }

    private function payment(string $id): Payment
    {
        return (Str::isUuid($id) ? Payment::query()->with('gateway', 'workspace')->find($id) : null)
            ?? throw new PaymentRefused(404, __('payments.no_payment'));
    }

    private function refusals(\Closure $act): JsonResponse|Response
    {
        try {
            return $act();
        } catch (PaymentRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    private function payload(): array
    {
        $currency = $this->pricing->settings()['currency'];
        $fields = fn (array $fs) => array_map(fn (Field $f) => $f->toArray(), $fs);
        $options = fn (string $group, array $ids) => array_map(fn (string $id) => ['value' => $id, 'label' => __("payments.{$group}.{$id}")], $ids);
        $offline = PaymentGateway::query()->get()->filter(fn (PaymentGateway $g) => in_array($g->driver, $this->gateways->driverIds(), true)
            && ! $this->gateways->driver($g->driver)->online())->pluck('id')->all();
        $payments = Payment::query()->with('gateway', 'workspace');

        return [
            'currency' => $currency,
            'inForce' => $this->pricing->inForce(),
            'settings' => $this->settings->current(),
            'disputeActions' => $options('dispute_actions', config('kit.payments.dispute_actions')),
            'maxGateways' => config('kit.payments.max_gateways'),
            'plans' => PriceList::query()->orderBy('id')->get()->map(fn (PriceList $l) => ['id' => $l->id, 'name' => $l->name])->all(),
            'drivers' => array_map(function (string $id) use ($fields, $options) {
                $driver = $this->gateways->driver($id);

                return [
                    'id' => $id, 'name' => __("payments.drivers.{$id}.name"), 'online' => $driver->online(),
                    'fields' => $fields($driver->fields([])),
                    'methods' => $options('methods', $driver->methods()),
                    'modes' => $driver->online() ? $options('modes', [PaymentGateway::TEST, PaymentGateway::LIVE]) : [],
                    'partialRefunds' => $driver?->partialRefunds() ?? false,
                ];
            }, $this->gateways->driverIds()),
            'gateways' => $this->gateways->listed()->map(function (PaymentGateway $g) use ($fields, $currency) {
                $driver = $this->gateways->driver($g->driver);

                return [
                    'id' => $g->id, 'driver' => $g->driver, 'driverName' => __("payments.drivers.{$g->driver}.name"), 'name' => $g->name,
                    'mode' => $g->mode, 'online' => $driver->online(), 'enabled' => $g->enabled, 'methods' => $g->methods ?? [], 'plans' => $g->plans,
                    'takesCurrency' => $this->gateways->takes($g, $currency),
                    'asks' => $this->gateways->asks($g, request()->user()),
                    'webhookUrl' => $driver->online() ? $this->gateways->notifyUrl($g) : null,
                    'webhookEvents' => $driver->webhookEvents(),
                    'lastEventAt' => $g->last_event_at?->toIso8601String(),
                    'fields' => $fields($driver->fields($g->config ?? [])),
                ];
            })->all(),
            'waiting' => (clone $payments)->where(fn ($q) => $q->where('status', Payment::REVIEW)
                ->orWhere(fn ($q) => $q->where('status', Payment::OPEN)->whereIn('gateway_id', $offline)))
                ->orderBy('created_at')->limit(config('kit.payments.recent'))->get()->map($this->present(...))->all(),
            'recent' => (clone $payments)->orderByDesc('created_at')->limit(config('kit.payments.recent'))->get()->map($this->present(...))->all(),
        ];
    }

    private function present(Payment $p): array
    {
        $driver = $this->gateways->driverOf($p->gateway);

        return [
            'id' => $p->id,
            'customer' => $p->workspace === null ? null : ['id' => $p->workspace->id, 'name' => $p->workspace->name],
            'gatewayName' => $p->gateway->name,
            'amount' => $p->amount,
            'currency' => $p->currency,
            'status' => $p->status,
            'received' => $p->received,
            'receivedCurrency' => $p->details['receivedCurrency'] ?? null,
            'refunded' => $p->refunded,
            'refundFailed' => ! empty($p->details['refundsFailed']),
            'dispute' => $p->dispute,
            'reference' => $p->gateway_ref,
            'error' => $p->details['error'] ?? null,
            'createdAt' => $p->created_at->toIso8601String(),
            'paidAt' => $p->paid_at?->toIso8601String(),
            'canConfirm' => $this->payments->canConfirm($p),
            'canCancel' => $p->status === Payment::OPEN,
            'canRefund' => $this->payments->canRefund($p),
            'partialRefunds' => $driver?->partialRefunds() ?? false,
        ];
    }
}
