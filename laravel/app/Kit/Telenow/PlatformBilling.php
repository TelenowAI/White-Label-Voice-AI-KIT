<?php

namespace App\Kit\Telenow;

use App\Kit\Email\Emails;
use App\Kit\Email\Senders;
use App\Kit\Settings\Settings;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * The operator's own account at Telenow, as money (Admin → Platform billing; contract/kit-api.yaml getPlatformBilling, setPlatformBalanceAlert): what it
 * may still spend (Telenow's spend state, as `kit:telenow-status` last read it: App\Kit\Console\ServicePause), the level at which Telenow calls its
 * balance low (setSpendAlert, with the operator's key), and Telenow's invoices to it (listInvoices: a postpaid account's only).
 *
 * changed(): the email to the operator's staff whose role may handle money (config('kit.admin.abilities.money')) when the account's state
 * changes for the worse — runs `low` (at or below the level) or stops (out of credit, at its cap, suspended) — once per change, as Telenow sends
 * its own `billing.spend_state_changed`; the first state ever read sends nothing. Not while the platform cannot send email.
 *
 * Telenow's words never reach a customer: these are the operator's pages and emails (white label, SELF_HOSTED_KIT_PLAN.md §9).
 */
final class PlatformBilling
{
    public const LOW = 'platform_balance_low';

    public const STOPPED = 'platform_stopped';

    public function __construct(
        private readonly Settings $settings,
        private readonly TelenowClient $telenow,
        private readonly Emails $emails,
        private readonly Senders $senders,
    ) {}

    /** @return array{state: ?array, checkedAt: ?string} Telenow's spend state for the operator's account, as last read */
    public function state(): array
    {
        $state = $this->settings->get('telenow.billing');

        return ['state' => is_array($state) ? $state : null, 'checkedAt' => $this->settings->get('telenow.status_checked_at')];
    }

    /** Set (a USD amount, as text) or clear (null, empty) the level at which Telenow calls the balance low. Answers the state it gives now. */
    public function setAlert(mixed $usd): array
    {
        $c = config('kit.platform_billing');
        $value = $usd === null || $usd === '' ? null : $usd;
        if ($value !== null && (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]{0,'.($c['alert_max_digits'] - 1).'})(\.[0-9]{1,2})?$/', $value))) {
            throw ValidationException::withMessages(['thresholdUsd' => [__('platform_billing.alert_shape', ['digits' => $c['alert_max_digits']])]]);
        }
        [$key, $org] = $this->account();
        try {
            $state = $this->telenow->setSpendAlert($key, $org, $value === null ? null : (float) $value);
        } catch (TelenowError $e) {
            if ($e->status === 400) {
                throw ValidationException::withMessages(['thresholdUsd' => [__('platform_billing.alert_refused')]]);
            }
            throw new PlatformBillingRefused(503, __('platform_billing.unavailable'));
        }
        if ($state !== []) {
            // What the page shows at once; the next state read compares with it, so setting a level already crossed sends no email.
            $this->settings->put('telenow.billing', array_intersect_key($state, array_flip(config('kit.telenow.spend_state_fields'))));
            $this->settings->put('telenow.status_checked_at', now()->toIso8601String()); // a fresh read too (found in the browser check)
        }

        return $this->state();
    }

    /** @return list<array> Telenow's invoices to the operator, newest period first (whatever order Telenow gives), each with its lines and what its total holds beyond them (tax) */
    public function invoices(): array
    {
        [$key, $org] = $this->account();
        try {
            $rows = $this->telenow->invoices($key, $org);
        } catch (TelenowError) {
            throw new PlatformBillingRefused(503, __('platform_billing.unavailable'));
        }

        $invoices = array_map(function (array $row) {
            $inv = is_array($row['invoice'] ?? null) ? $row['invoice'] : [];
            $lines = array_values(array_filter((array) ($row['lines'] ?? []), 'is_array'));
            $total = $this->decimal($inv['totalBilled'] ?? null);
            $sum = array_reduce($lines, fn (BigDecimal $s, array $l) => $s->plus($this->decimal($l['amountBilled'] ?? null) ?? BigDecimal::zero()), BigDecimal::zero());
            $rest = $total !== null && $lines !== [] ? $total->minus($sum) : null;

            return [
                'id' => (string) ($inv['id'] ?? ''),
                'periodStart' => $inv['periodStart'] ?? null,
                'periodEnd' => $inv['periodEnd'] ?? null,
                'status' => in_array($inv['status'] ?? null, config('kit.platform_billing.invoice_statuses'), true) ? $inv['status'] : 'other',
                'issuedAt' => $inv['issuedAt'] ?? null,
                'dueAt' => $inv['dueAt'] ?? null,
                'paidAt' => $inv['paidAt'] ?? null,
                'currency' => (string) ($inv['currency'] ?? ''),
                'total' => $total === null ? null : (string) $total->toScale(2, RoundingMode::HalfUp),
                'totalUsd' => ($u = $this->decimal($inv['totalUsd'] ?? null)) === null ? null : (string) $u->toScale(2, RoundingMode::HalfUp),
                'lines' => array_map(fn (array $l) => [
                    'kind' => (string) ($l['lineKind'] ?? ''),
                    'description' => (string) ($l['description'] ?? ''),
                    'amount' => ($a = $this->decimal($l['amountBilled'] ?? null)) === null ? null : (string) $a->toScale(2, RoundingMode::HalfUp),
                ], $lines),
                'rest' => $rest !== null && $rest->isPositive() ? (string) $rest->toScale(2, RoundingMode::HalfUp) : null,
            ];
        }, $rows);
        usort($invoices, fn (array $a, array $b) => strcmp((string) $b['periodStart'], (string) $a['periodStart']));

        return $invoices;
    }

    /** After a state read: the email for a change for the worse (ok → low, ok or low → stopped). Answers the kind of email sent, or null. */
    public function changed(?array $before, array $after): ?string
    {
        $was = $before['state'] ?? null;
        $now = $after['state'] ?? null;
        $kind = match (true) {
            $was === null || $was === $now => null,
            $now === 'stopped' => self::STOPPED,
            $now === 'low' && $was === 'ok' => self::LOW,
            default => null,
        };
        if ($kind === null || ! $this->emails->on()) {
            return null;
        }
        $reasons = (array) __('platform_billing.stopped_by');
        $vars = [
            'balance' => $this->usd($after['balanceUsd'] ?? null),
            'level' => $this->usd($after['lowThresholdUsd'] ?? null),
            'reason' => $reasons[$after['stoppedBy'] ?? ''] ?? $reasons['other'],
        ];
        $staff = User::query()->whereIn('role', config('kit.admin.abilities.money'))->whereNotNull('email')->orderBy('id')->get();
        foreach ($staff as $user) {
            $this->emails->send($kind, $user->email, $vars + ['name' => $user->name], $this->senders->link('admin/platform-billing'));
        }

        return $kind;
    }

    /** @return array{0: string, 1: string} the operator's key and its account at Telenow */
    private function account(): array
    {
        $key = $this->settings->get('telenow.api_key');
        $org = $this->settings->get('telenow.account')['org_id'] ?? null;
        if (! is_string($key) || $key === '' || ! is_string($org)) {
            throw new PlatformBillingRefused(409, __('platform_billing.not_connected'));
        }

        return [$key, $org];
    }

    private function decimal(mixed $v): ?BigDecimal
    {
        return is_string($v) && preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/', $v) ? BigDecimal::of($v) : null;
    }

    private function usd(mixed $v): string
    {
        $d = $this->decimal($v);

        return $d === null ? '—' : $d->toScale(2, RoundingMode::HalfUp).' USD';
    }
}
