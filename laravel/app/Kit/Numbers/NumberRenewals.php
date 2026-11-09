<?php

namespace App\Kit\Numbers;

use App\Kit\Email\Emails;
use App\Kit\Email\Senders;
use App\Kit\Payments\Money;
use App\Kit\Pricing\Pricing;
use App\Models\PhoneNumber;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Whether a customer's balance covers their numbers' next rent (console → Numbers; contract/kit-api.yaml getNumberRenewals), and the email that says
 * when it will not. The kit's own reckoning: a customer's numbers renew on the operator's account at Telenow (a sub-account's renewal risk is always
 * "billed to parent", recorded), and the customer pays the kit — each month's rent is charged to their wallet from Telenow's ledger at their plan's
 * price, whatever the balance (App\Kit\Wallet\FeeCharges), and their calls stop once it reaches zero (App\Kit\Wallet\SpendLimit).
 *
 * - of(): the account's numbers in renewal order, each with its next rent on the plan (from its rent in USD) and whether the balance covers it once
 *   every renewal before it is charged too (the shortfall is the running total, as Telenow reckons its own: topping up one number's gap alone
 *   would still lose a later one) — `short`; else `soon` within config('kit.numbers.renewal_warning_days'), else `ok`. Rent shows only while
 *   charging is on (Pricing::inForce), and only then can a number be short.
 * - warn(): one email to the account's members in config('kit.wallet.alert_roles') who have an email, for the short numbers renewing within those
 *   days that no email has named for that renewal yet; each such renewal is named once. Not while charging is off, for an account that is not
 *   active, or when the platform cannot send email. Called by `kit:numbers` after each account's numbers are read.
 */
final class NumberRenewals
{
    public function __construct(
        private readonly Numbers $numbers,
        private readonly Pricing $pricing,
        private readonly Emails $emails,
        private readonly Senders $senders,
    ) {}

    /** @return array{inForce: bool, balance: ?array{amount: string, currency: string}, soonDays: int, numbers: list<array{number: PhoneNumber, rent: ?array, status: string, shortBy: ?array}>} */
    public function of(Workspace $workspace): array
    {
        $inForce = $this->pricing->inForce();
        $soon = now()->addDays((int) config('kit.numbers.renewal_warning_days'));
        $balance = BigDecimal::of($workspace->balance ?? '0');
        $total = BigDecimal::zero();
        $rows = [];
        $held = PhoneNumber::query()->with('agent')->where('workspace_id', $workspace->id)->whereNull('released_at')->whereNotNull('telenow_id')
            ->whereNotNull('next_renewal_at')->orderBy('next_renewal_at')->orderBy('id')->get();
        foreach ($held as $number) {
            $rent = $inForce ? $this->numbers->price($workspace, 'rental', $number->rent_usd) : null;
            $short = null;
            if ($rent !== null) {
                $total = $total->plus($rent['amount']);
                if ($total->isGreaterThan($balance)) {
                    $short = ['amount' => (string) $total->minus($balance), 'currency' => $rent['currency']];
                }
            }
            $rows[] = ['number' => $number, 'rent' => $rent, 'status' => $short !== null ? 'short' : ($number->next_renewal_at <= $soon ? 'soon' : 'ok'), 'shortBy' => $short];
        }
        $currency = $this->pricing->settings()['currency'] ?? null;

        return [
            'inForce' => $inForce,
            'balance' => $inForce && is_string($currency) ? ['amount' => (string) $balance, 'currency' => $currency] : null,
            'soonDays' => (int) config('kit.numbers.renewal_warning_days'),
            'numbers' => $rows,
        ];
    }

    /** Email the account about short renewals due soon that no email has named yet. Answers how many renewals it named. */
    public function warn(Workspace $workspace): int
    {
        if ($workspace->status !== Workspace::ACTIVE || ! $this->pricing->inForce() || ! $this->emails->on()) {
            return 0;
        }
        $soon = now()->addDays((int) config('kit.numbers.renewal_warning_days'));
        $due = array_values(array_filter($this->of($workspace)['numbers'], fn (array $r) => $r['status'] === 'short' && $r['number']->next_renewal_at <= $soon
            && ! ($r['number']->renewal_warned_for?->equalTo($r['number']->next_renewal_at) ?? false)));
        if ($due === []) {
            return 0;
        }
        $currency = $due[0]['shortBy']['currency'];
        $need = array_reduce($due, fn (BigDecimal $most, array $r) => BigDecimal::of($r['shortBy']['amount'])->isGreaterThan($most) ? BigDecimal::of($r['shortBy']['amount']) : $most, BigDecimal::zero());
        $vars = [
            'account' => $workspace->name,
            'numbers' => implode(', ', array_map(fn (array $r) => '+'.preg_replace('/\D/', '', $r['number']->e164), $due)),
            'date' => $due[0]['number']->next_renewal_at->toDateString(),
            'short' => $this->money((string) $need, $currency),
            'balance' => $this->money((string) ($workspace->balance ?? '0'), $currency),
        ];
        $members = WorkspaceMember::query()->with('user')->where('workspace_id', $workspace->id)
            ->whereIn('role', config('kit.wallet.alert_roles'))->orderBy('id')->get();
        foreach ($members as $member) {
            if ($member->user?->email !== null) {
                $this->emails->send('number_rent_short', $member->user->email, $vars + ['name' => $member->user->name], $this->senders->link('console/numbers'));
            }
        }
        foreach ($due as $r) {
            $r['number']->forceFill(['renewal_warned_for' => $r['number']->next_renewal_at])->save();
        }

        return count($due);
    }

    private function money(string $amount, string $currency): string
    {
        return BigDecimal::of($amount)->toScale(Money::decimals($currency), RoundingMode::HalfUp).' '.$currency;
    }
}
