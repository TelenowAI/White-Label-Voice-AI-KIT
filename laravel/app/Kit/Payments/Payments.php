<?php

namespace App\Kit\Payments;

use App\Kit\Console\ServicePause;
use App\Kit\Customers\Customers;
use App\Kit\Email\Emails;
use App\Kit\Email\Senders;
use App\Kit\Invoices\CustomerInvoices;
use App\Kit\Pricing\Pricing;
use App\Kit\Wallet\Wallet;
use App\Models\CustomerInvoice;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Workspace;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Paying the operator (SELF_HOSTED_KIT_PLAN.md §4.6 a, the money rules):
 * - **Starting.** A customer tops up through a gateway it is offered (Gateways::offered), an amount within the
 *   operator's rules (PaymentSettings), while the wallet is in force and its account is not paused for a reason in
 *   config('kit.payments.blocked_when'). Staff can make a test payment through a gateway in test mode: it credits
 *   no one.
 * - **Learning how it went.** Only from the gateway: a verified webhook, the gateway asked when the payer comes back,
 *   or asked again by `kit:payments` while it is open. A bank transfer is confirmed by staff, with the amount that
 *   arrived.
 * - **Crediting once.** A payment the gateway says is paid, for the amount and currency asked, credits the wallet
 *   once (the entry's reference is the payment), from whatever state it was in — money taken is money credited. Any
 *   other amount or currency waits for staff (`review`).
 * - **Its invoice.** Issued once the payment is credited (App\Kit\Invoices\CustomerInvoices), and the payer's email links to it.
 * - **After.** A refund (staff's, or one made at the gateway) and a dispute (as the operator's dispute rule says) write
 *   negative entries, each once by the gateway's own id; a dispute won gives a held amount back, and so does a refund
 *   that later fails at the bank, since the money went back to the operator and not to the payer.
 *
 * Every wallet entry is idempotent (its reference names the payment and the gateway's id). A credit is written before
 * the payment is marked paid, so a payment never ends up paid without it; a refund is counted on the payment first and
 * comes off the wallet after, also when it is delivered again. No gateway or Telenow call is made while a row is
 * locked.
 *
 * Not here: the gateways themselves (Gateways, the drivers), the HTTP around it (Console\PaymentsController,
 * Admin\PaymentsController, PaymentReturnController, PaymentWebhookController).
 */
final class Payments
{
    public function __construct(
        private readonly Gateways $gateways,
        private readonly PaymentSettings $settings,
        private readonly Wallet $wallet,
        private readonly Pricing $pricing,
        private readonly ServicePause $pause,
        private readonly Customers $customers,
        private readonly Emails $emails,
        private readonly Senders $senders,
        private readonly CustomerInvoices $invoices,
    ) {}

    /**
     * A customer's top-up: checked, opened, and where the payer goes.
     *
     * @return array{0: Payment, 1: Checkout}
     */
    public function start(Workspace $workspace, User $by, array $input): array
    {
        $currency = $this->pricing->settings()['currency'];
        if (! $this->pricing->inForce() || $currency === null) {
            throw new PaymentRefused(409, __('wallet.not_in_force'));
        }
        $paused = $this->pause->for($workspace);
        if ($paused !== null && in_array($paused['reason'], config('kit.payments.blocked_when'), true)) {
            throw new PaymentRefused(409, $paused['message']);
        }
        $offered = $this->gateways->offered($workspace);
        if ($offered->isEmpty()) {
            throw new PaymentRefused(409, __('payments.none_offered'));
        }
        $values = Validator::make($input, [
            'gatewayId' => ['required', 'string', Rule::in($offered->pluck('id')->all())],
            'amount' => ['required', $this->amountRule($currency)],
        ], [], $this->names())->validate();

        $gateway = $offered->firstWhere('id', $values['gatewayId']);

        return $this->open($gateway, $workspace, $by, Money::exact($values['amount'], $currency), $currency, $this->payerDetails($gateway, $by, $input));
    }

    /**
     * A staff member's test of an online gateway in test mode: a real checkout, return and webhook, crediting no one.
     *
     * @return array{0: Payment, 1: Checkout}
     */
    public function startTest(PaymentGateway $gateway, User $by, array $input): array
    {
        if (! $this->gateways->driver($gateway->driver)->online() || $gateway->mode !== PaymentGateway::TEST) {
            throw new PaymentRefused(409, __('payments.test_needs_test_mode'));
        }
        $currency = $this->pricing->settings()['currency'];
        if (! $this->gateways->takes($gateway, $currency)) {
            throw new PaymentRefused(409, __('payments.currency_not_taken'));
        }
        $values = Validator::make($input, ['amount' => ['required', $this->amountRule($currency)]], [], $this->names())->validate();

        return $this->open($gateway, null, $by, Money::exact($values['amount'], $currency), $currency, $this->payerDetails($gateway, $by, $input));
    }

    /** The payer is back from the gateway: ask it how the payment went (a payment no longer open is left as it is). */
    public function returned(Payment $payment, array $input): Payment
    {
        $gateway = $payment->gateway;
        $driver = $this->gateways->driverOf($gateway);
        if ($payment->status !== Payment::OPEN || $driver === null || ! $driver->online()) {
            return $payment;
        }
        try {
            $event = $driver->returned($gateway, $payment, $input);
        } catch (PaymentFailed) {
            return $payment; // kit:payments asks again
        }

        return $event === null ? $payment : ($this->apply($gateway, $event) ?? $payment);
    }

    /**
     * A gateway's webhook: its events once the driver has checked the signature, each kept (encrypted) and applied.
     * Answers the HTTP status: 204 taken, 401 the signature does not check out.
     *
     * @param  array<string, string>  $headers  lower-cased names
     */
    public function receive(PaymentGateway $gateway, string $body, array $headers): int
    {
        try {
            $events = $this->gateways->driver($gateway->driver)->webhook($gateway, $body, $headers);
        } catch (PaymentFailed) {
            return 401;
        }
        $gateway->forceFill(['last_event_at' => now()])->save();
        foreach ($events as $event) {
            if ($event->eventId !== null && PaymentEvent::query()->where('gateway_id', $gateway->id)->where('event_ref', $event->eventId)->exists()) {
                continue; // delivered again
            }
            $payment = $this->apply($gateway, $event);
            $kept = new PaymentEvent;
            $kept->forceFill(['gateway_id' => $gateway->id, 'payment_id' => $payment?->id, 'kind' => $event->kind, 'event_ref' => $event->eventId, 'payload' => $body])->save();
        }

        return 204;
    }

    /** Apply what a gateway says happened. Answers the payment it was about, or null when the kit has none. */
    public function apply(PaymentGateway $gateway, GatewayEvent $event, ?User $by = null): ?Payment
    {
        $payment = $this->paymentFor($gateway, $event);
        if ($payment === null) {
            return null;
        }
        $event = $this->withExactAmount($event, $payment);
        match ($event->kind) {
            GatewayEvent::PAID => $this->paid($payment, $event->amount ?? $payment->amount, $event->currency ?? $payment->currency, null, false),
            GatewayEvent::FAILED => $this->settle($payment, fn (Payment $p) => $p->status === Payment::OPEN ? ['status' => Payment::FAILED] : []),
            GatewayEvent::REFUNDED => $this->refunded($payment, $event, $by),
            GatewayEvent::REFUND_FAILED => $this->refundFailed($payment, $event),
            GatewayEvent::DISPUTE_OPENED, GatewayEvent::DISPUTE_WON, GatewayEvent::DISPUTE_LOST => $this->disputed($payment, $event),
            default => null, // pending: still open
        };

        return $this->settle($payment, fn (Payment $p) => ['checked_at' => now()]
            + ($p->gateway_ref === null && $event->gatewayRef !== null ? ['gateway_ref' => $event->gatewayRef] : []));
    }

    /**
     * Staff say the money arrived: a bank transfer (open, or given up on — it arrived after all), or a payment waiting
     * for review. Credits the amount staff enter.
     */
    public function confirm(Payment $payment, User $by, array $input): Payment
    {
        if (! $this->canConfirm($payment)) {
            throw new PaymentRefused(409, __('payments.cannot_confirm'));
        }
        $values = Validator::make($input, ['amount' => ['required', $this->shapeRule($payment->currency)]], [], $this->names())->validate();
        $this->paid($payment, Money::exact($values['amount'], $payment->currency), $payment->currency, $by, true);

        return $payment->refresh();
    }

    /** Whether staff may confirm it: waiting for review, or a bank transfer not yet paid. */
    public function canConfirm(Payment $payment): bool
    {
        $driver = $this->gateways->driverOf($payment->gateway);

        return $payment->status === Payment::REVIEW || ($driver !== null && ! $driver->online()
            && in_array($payment->status, [Payment::OPEN, Payment::EXPIRED, Payment::CANCELED], true));
    }

    /** Whether staff may refund it: the money was received, through a gateway still installed. */
    public function canRefund(Payment $payment): bool
    {
        return in_array($payment->status, [Payment::PAID, Payment::REVIEW], true) && $this->gateways->driverOf($payment->gateway) !== null;
    }

    /** Staff give up on an open payment (a bank transfer that will not come). */
    public function cancel(Payment $payment, User $by): Payment
    {
        $done = false;
        $payment = $this->settle($payment, function (Payment $p) use ($by, &$done) {
            if ($p->status !== Payment::OPEN) {
                return [];
            }
            $done = true;

            return ['status' => Payment::CANCELED, 'decided_by' => $by->id];
        });
        if (! $done) {
            throw new PaymentRefused(409, __('payments.cannot_cancel'));
        }

        return $payment;
    }

    /**
     * Refund some or all of a payment through its gateway (a bank transfer: staff have sent the money back). The
     * request's `reference` makes sending it twice refund once, also at the same moment: not every gateway can refuse the
     * same refund twice, so a payment sends one refund at a time.
     */
    public function refund(Payment $payment, User $by, array $input): Payment
    {
        if (! $this->canRefund($payment)) {
            throw new PaymentRefused(409, __('payments.cannot_refund'));
        }
        $values = Validator::make($input, ['amount' => ['required', $this->shapeRule($payment->currency)], 'reference' => ['required', 'uuid']],
            [], $this->names())->validate();
        $lock = Cache::lock("kit.payments.refund.{$payment->id}", (int) config('kit.payments.refund_lock_seconds'));
        if (! $lock->get()) {
            throw new PaymentRefused(409, __('payments.refund_in_progress'));
        }
        try {
            return $this->sendRefund($payment->refresh(), $by, $values);
        } finally {
            $lock->release();
        }
    }

    /** @param  array{amount: string, reference: string}  $values */
    private function sendRefund(Payment $payment, User $by, array $values): Payment
    {
        if (isset($payment->details['refundRequests'][$values['reference']])) {
            return $payment; // sent twice
        }
        $gateway = $payment->gateway;
        $driver = $this->gateways->driverOf($gateway);
        $amount = Money::exact($values['amount'], $payment->currency);
        $left = BigDecimal::of($payment->received ?? $payment->amount)->minus($payment->refunded);
        if (BigDecimal::of($amount)->isGreaterThan($left)) {
            throw new PaymentRefused(422, __('payments.refund_too_much', ['left' => (string) $left, 'currency' => $payment->currency]));
        }
        if (! $driver->partialRefunds() && ! BigDecimal::of($amount)->isEqualTo($left)) {
            throw new PaymentRefused(422, __('payments.refund_whole', ['left' => (string) $left, 'currency' => $payment->currency]));
        }
        try {
            $event = $driver->refund($gateway, $payment, $amount, $values['reference']);
        } catch (PaymentFailed $e) {
            throw new PaymentRefused(502, __('payments.refund_failed', ['reason' => Str::limit($e->getMessage(), config('kit.payments.error_max_length'))]));
        }
        $event = $this->withExactAmount($event, $payment);
        $this->refunded($payment, $event, $by);

        return $this->settle($payment, function (Payment $p) use ($values, $event) {
            $details = $p->details ?? [];
            $details['refundRequests'][$values['reference']] = $event->ref;

            return ['details' => $details];
        });
    }

    /** Ask the gateway how an open payment stands (kit:payments). */
    public function check(Payment $payment): Payment
    {
        $gateway = $payment->gateway;
        try {
            $event = $this->gateways->driverOf($gateway)?->fetch($gateway, $payment);
        } catch (PaymentFailed) {
            $event = null;
        }

        return $event === null ? $this->settle($payment, fn () => ['checked_at' => now()]) : ($this->apply($gateway, $event) ?? $payment);
    }

    /**
     * `kit:payments`: an open online payment is asked about once it is older than its driver's checkAfterMinutes, at
     * most every config('kit.payments.check_every_minutes'), the least recently asked first, up to
     * config('kit.payments.checks_per_run'); an open payment past its expiry is given up (an online one asked once
     * more first); events past config('kit.payments.events_keep_days') are deleted.
     *
     * @return array{checked: int, expired: int, purged: int}
     */
    public function run(): array
    {
        $budget = (int) config('kit.payments.checks_per_run');
        $checked = 0;
        $byDriver = PaymentGateway::query()->get()->filter(fn (PaymentGateway $g) => in_array($g->driver, $this->gateways->driverIds(), true)
            && $this->gateways->driver($g->driver)->online())->groupBy('driver');
        foreach ($byDriver as $driverId => $gateways) {
            if ($checked >= $budget) {
                break;
            }
            $due = Payment::query()->where('status', Payment::OPEN)->whereIn('gateway_id', $gateways->pluck('id'))
                ->where('created_at', '<=', now()->subMinutes($this->gateways->driver($driverId)->checkAfterMinutes()))
                ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<=', now()->subMinutes(config('kit.payments.check_every_minutes'))))
                ->orderByRaw('checked_at is not null')->orderBy('checked_at')->orderBy('created_at')
                ->limit($budget - $checked)->with('gateway')->get();
            foreach ($due as $payment) {
                $this->check($payment);
                $checked++;
            }
        }
        $expired = 0;
        $over = Payment::query()->where('status', Payment::OPEN)->where('expires_at', '<=', now())->orderBy('expires_at')->limit($budget)->with('gateway')->get();
        foreach ($over as $payment) {
            if ($this->gateways->driverOf($payment->gateway)?->online()) {
                $payment = $this->check($payment);
            }
            $this->settle($payment, function (Payment $p) use (&$expired) {
                if ($p->status !== Payment::OPEN) {
                    return [];
                }
                $expired++;

                return ['status' => Payment::EXPIRED];
            });
        }
        $purged = PaymentEvent::query()->where('created_at', '<', now()->subDays(config('kit.payments.events_keep_days')))->delete();

        return ['checked' => $checked, 'expired' => $expired, 'purged' => $purged];
    }

    /** What the gateway needs of this person that their account does not have, as they gave it just now, checked. */
    private function payerDetails(PaymentGateway $gateway, User $by, array $input): array
    {
        $rules = in_array('email', $this->gateways->asks($gateway, $by), true) ? ['payer.email' => ['required', 'string', 'email', 'max:255']] : [];

        return Validator::make($input, $rules, [], ['payer.email' => __('payments.fields.payerEmail')])->validate()['payer'] ?? [];
    }

    /**
     * @param  array<string, string>  $payer  kept only until the checkout has begun
     * @return array{0: Payment, 1: Checkout}
     */
    private function open(PaymentGateway $gateway, ?Workspace $workspace, User $by, string $amount, string $currency, array $payer): array
    {
        $driver = $this->gateways->driver($gateway->driver);
        $payment = new Payment;
        $payment->forceFill([
            'workspace_id' => $workspace?->id, 'gateway_id' => $gateway->id, 'user_id' => $by->id, 'amount' => $amount, 'currency' => $currency,
            'status' => Payment::OPEN, 'details' => $payer === [] ? null : ['payer' => $payer],
            'expires_at' => $driver->online() ? now()->addHours(config('kit.payments.expire_hours')) : now()->addDays(config('kit.payments.manual.expire_days')),
        ])->save();
        try {
            $checkout = $driver->checkout($gateway, $payment, $this->gateways->returnUrl($payment), $this->gateways->notifyUrl($gateway));
        } catch (PaymentFailed $e) {
            $payment->forceFill(['status' => Payment::FAILED, 'details' => ['error' => Str::limit($e->getMessage(), config('kit.payments.error_max_length'))]])->save();
            throw new PaymentRefused(503, __('payments.gateway_down'));
        }
        $payment->forceFill(['gateway_ref' => $checkout->gatewayRef,
            'details' => $checkout->instructions === null ? null : ['instructions' => $checkout->instructions]])->save();

        return [$payment, $checkout];
    }

    /** Paid: the wallet credited (once), then the payment marked paid — or, not what was asked, left for review. */
    private function paid(Payment $payment, string $amount, string $currency, ?User $by, bool $staff): void
    {
        if ($payment->status === Payment::PAID) {
            $this->refundsAfterCredit($payment);

            return;
        }
        if (! $staff && ($currency !== $payment->currency || ! BigDecimal::of($amount)->isEqualTo($payment->amount))) {
            $this->settle($payment, function (Payment $p) use ($amount, $currency) {
                if (in_array($p->status, [Payment::PAID, Payment::REVIEW], true)) {
                    return [];
                }

                return ['status' => Payment::REVIEW, 'received' => $amount, 'details' => [...($p->details ?? []), 'receivedCurrency' => $currency]];
            });

            return;
        }
        if (! $payment->isTest()) {
            $this->wallet->forPayment($payment->workspace, WalletEntry::CREDIT, BigDecimal::of($amount), $payment->currency, "payment:{$payment->id}",
                ['payment' => $payment->id], $by);
        }
        $changed = false;
        $this->settle($payment, function (Payment $p) use ($amount, $by, &$changed) {
            if ($p->status === Payment::PAID) {
                return [];
            }
            $changed = true;

            return ['status' => Payment::PAID, 'received' => $amount, 'paid_at' => now(), 'decided_by' => $by?->id];
        });
        $this->refundsAfterCredit($payment);
        if ($changed && ! $payment->isTest()) {
            $payment->refresh();
            $this->tellPayer($payment, $this->invoice($payment));
        }
    }

    /** The payment's invoice, issued now; a failure never stops the payment (`kit:payments` issues it later: CustomerInvoices::catchUp). */
    private function invoice(Payment $payment): ?CustomerInvoice
    {
        try {
            return $this->invoices->issue($payment);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * A refund counted while the payment was not yet known to be paid comes off once it is (each once by its id).
     * Run after the payment is marked paid, so a refund counted at the same time is either seen here or saw it paid.
     */
    private function refundsAfterCredit(Payment $payment): void
    {
        if ($payment->isTest()) {
            return;
        }
        foreach ($payment->details['refunds'] ?? [] as $ref => $refunded) {
            $this->wallet->forPayment($payment->workspace, WalletEntry::REFUND, BigDecimal::of($refunded)->negated(), $payment->currency,
                "refund:{$payment->id}:{$ref}", ['payment' => $payment->id, 'refund' => $ref], null);
        }
    }

    /** A refund: off the wallet when the payment was credited, and counted on the payment — each once by its id. */
    private function refunded(Payment $payment, GatewayEvent $event, ?User $by): void
    {
        $ref = $event->ref ?? $event->eventId;
        if ($ref === null) {
            return; // nothing to count it once by
        }
        $amount = $event->amount ?? (string) BigDecimal::of($payment->received ?? $payment->amount)->minus($payment->refunded);
        $credited = false;
        $this->settle($payment, function (Payment $p) use ($ref, &$amount, &$credited) {
            // Whether it was credited is read under the lock, so a payment marked paid at the same time sees this refund.
            $credited = $p->status === Payment::PAID;
            $details = $p->details ?? [];
            if (isset($details['refundsFailed'][$ref])) {
                $credited = false; // told it failed first: it never counts

                return [];
            }
            if (isset($details['refunds'][$ref])) {
                $amount = $details['refunds'][$ref];

                return [];
            }
            $details['refunds'][$ref] = $amount;

            return ['details' => $details, 'refunded' => (string) BigDecimal::of($p->refunded)->plus($amount)];
        });
        if ($credited && ! $payment->isTest()) {
            $this->wallet->forPayment($payment->workspace, WalletEntry::REFUND, BigDecimal::of($amount)->negated(), $payment->currency,
                "refund:{$payment->id}:{$ref}", ['payment' => $payment->id, 'refund' => $ref], $by);
        }
    }

    /** A refund that failed after it was counted: the payment can be refunded again, and the amount goes back on the wallet, once. */
    private function refundFailed(Payment $payment, GatewayEvent $event): void
    {
        $ref = $event->ref ?? $event->eventId;
        if ($ref === null) {
            return;
        }
        $amount = null;
        $credited = false;
        $this->settle($payment, function (Payment $p) use ($ref, $event, &$amount, &$credited) {
            $details = $p->details ?? [];
            if (isset($details['refundsFailed'][$ref])) {
                return [];
            }
            $amount = $details['refunds'][$ref] ?? null;
            $credited = $p->status === Payment::PAID;
            $details['refundsFailed'][$ref] = $amount ?? $event->amount ?? '0';
            unset($details['refunds'][$ref]);

            return ['details' => $details] + ($amount === null ? [] : ['refunded' => (string) BigDecimal::of($p->refunded)->minus($amount)]);
        });
        if ($amount !== null && $credited && ! $payment->isTest()) {
            $this->wallet->forPayment($payment->workspace, WalletEntry::REFUND, BigDecimal::of($amount), $payment->currency,
                "refund-failed:{$payment->id}:{$ref}", ['payment' => $payment->id, 'refund' => $ref], null);
        }
    }

    /**
     * A dispute, by the operator's rule (PaymentSettings): opened — held (and the account suspended) or left; lost —
     * the amount comes off if it was not held; won — a held amount comes back.
     */
    private function disputed(Payment $payment, GatewayEvent $event): void
    {
        $ref = $event->ref ?? $event->eventId;
        if ($ref === null) {
            return;
        }
        $known = $payment->details['disputes'][$ref] ?? null;
        $amount = $known['amount'] ?? $event->amount ?? $payment->received ?? $payment->amount;
        $held = (bool) ($known['held'] ?? false);
        $state = match ($event->kind) {
            GatewayEvent::DISPUTE_OPENED => 'open',
            GatewayEvent::DISPUTE_WON => 'won',
            default => 'lost',
        };
        if ($payment->status === Payment::PAID && ! $payment->isTest()) {
            $hold = fn () => $this->wallet->forPayment($payment->workspace, WalletEntry::HOLD, BigDecimal::of($amount)->negated(), $payment->currency,
                "dispute:{$payment->id}:{$ref}", ['payment' => $payment->id, 'dispute' => $ref], null);
            $action = $this->settings->current()['disputeAction'];
            if ($state === 'open' && $known === null && $action !== 'nothing') {
                $hold();
                $held = true;
                if ($action === 'suspend') {
                    $this->customers->suspend($payment->workspace, __('payments.dispute_suspend_reason', ['payment' => $payment->id]));
                }
            } elseif ($state === 'lost' && ! $held) {
                $hold();
                $held = true;
            } elseif ($state === 'won' && $held && ($known['state'] ?? null) !== 'won') {
                $this->wallet->forPayment($payment->workspace, WalletEntry::RELEASE, BigDecimal::of($amount), $payment->currency,
                    "dispute-won:{$payment->id}:{$ref}", ['payment' => $payment->id, 'dispute' => $ref], null);
                $held = false;
            }
        }
        $this->settle($payment, function (Payment $p) use ($ref, $state, $amount, $held) {
            $details = $p->details ?? [];
            $details['disputes'][$ref] = ['state' => $state, 'amount' => $amount, 'held' => $held];

            return ['details' => $details, 'dispute' => $state];
        });
    }

    /** Change a payment with its row locked: `$change` sees it as it is now and answers the columns to set. */
    private function settle(Payment $payment, Closure $change): Payment
    {
        $settled = DB::transaction(function () use ($payment, $change) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $values = $change($locked);
            if ($values !== []) {
                $locked->forceFill($values)->save();
            }

            return $locked;
        });
        $payment->setRawAttributes($settled->getAttributes(), true);

        return $payment;
    }

    /** The event's amount as money is kept here, whatever shape the gateway wrote it in. */
    private function withExactAmount(GatewayEvent $event, Payment $payment): GatewayEvent
    {
        return $event->amount === null ? $event : new GatewayEvent($event->kind, $event->paymentId, $event->gatewayRef,
            Money::written($event->amount, $event->currency ?? $payment->currency), $event->currency, $event->ref, $event->eventId);
    }

    private function paymentFor(PaymentGateway $gateway, GatewayEvent $event): ?Payment
    {
        $query = Payment::query()->where('gateway_id', $gateway->id);
        if ($event->paymentId !== null && Str::isUuid($event->paymentId)) {
            $found = (clone $query)->find($event->paymentId);
            if ($found !== null) {
                return $found;
            }
        }

        return $event->gatewayRef === null ? null : $query->where('gateway_ref', $event->gatewayRef)->first();
    }

    private function tellPayer(Payment $payment, ?CustomerInvoice $invoice): void
    {
        $user = $payment->user;
        // Someone who signed up with their phone has no email to tell.
        if ($user?->email === null || ! $this->emails->on()) {
            return;
        }
        $this->emails->send('payment_received', $user->email, [
            'name' => $user->name, 'amount' => "{$payment->received} {$payment->currency}", 'account' => $payment->workspace->name,
        ], $this->senders->link($invoice === null ? 'console/wallet' : "console/invoices/{$invoice->id}"));
    }

    /** A top-up amount: shaped for the currency and within the operator's rules. */
    private function amountRule(string $currency): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($currency) {
            if (($problem = $this->settings->amountProblem($value, $currency)) !== null) {
                $fail($problem);
            }
        };
    }

    /** An amount staff enter: shaped for the currency (no top-up rules). */
    private function shapeRule(string $currency): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($currency) {
            if (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/', $value)
                || BigDecimal::of($value)->getScale() > Money::decimals($currency) || ! BigDecimal::of($value)->isPositive()) {
                $fail(__('payments.amount_shape', ['places' => Money::decimals($currency)]));
            }
        };
    }

    private function names(): array
    {
        return ['gatewayId' => __('payments.fields.gatewayId'), 'amount' => __('payments.fields.amount'), 'reference' => __('payments.fields.reference')];
    }
}
