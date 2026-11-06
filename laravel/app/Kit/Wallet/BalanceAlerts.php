<?php

namespace App\Kit\Wallet;

use App\Kit\Email\Emails;
use App\Kit\Email\Senders;
use App\Kit\Payments\Money;
use App\Kit\Pricing\Pricing;
use App\Kit\Settings\Settings;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * A customer's balance emails (SELF_HOSTED_KIT_PLAN.md §4.2 Emails: low balance):
 * - `balance_low` once the balance falls to the account's warning level: its own (Console → Wallet), else the
 *   operator's default (Admin → Pricing; setting `wallet.low_balance_at`). A level of zero, or none, sends none.
 * - `balance_empty` once it falls to zero or below, when its calls stop (SpendLimit).
 *
 * Each goes once, to the account's members in config('kit.wallet.alert_roles') who have an email (one who signed up with
 * a phone has none). It is armed again once the balance is
 * back above its level (a top-up; above zero for the out-of-credit one). A balance that falls past both at once gets
 * only the out-of-credit email. Only an entry that lowers the balance sends one (a charge, a refund, a disputed amount
 * held): a top-up never does, even one that leaves the balance at or below a level (owner 10-31) — the next fall does.
 * Nothing is sent while pricing is not in force, for an account that is not active (suspended, closing, closed), or
 * when the platform cannot send email.
 *
 * Wallet calls decide() inside each entry's transaction with the account's row locked, so two charges at once send one
 * email, and send() once that is committed.
 *
 * Not here: the words (lang/<locale>/emails.php), the stop itself (SpendLimit).
 */
final class BalanceAlerts
{
    public const LOW = 'balance_low';

    public const EMPTY = 'balance_empty';

    private const DEFAULT = 'wallet.low_balance_at';

    private const AMOUNT = '/^(0|[1-9][0-9]*)(\.[0-9]+)?$/';

    public function __construct(
        private readonly Settings $settings,
        private readonly Pricing $pricing,
        private readonly Emails $emails,
        private readonly Senders $senders,
    ) {}

    /** The operator's default warning level, or null for none. */
    public function defaultLevel(): ?string
    {
        $level = $this->settings->get(self::DEFAULT);

        return is_string($level) ? $level : null;
    }

    /** Set the operator's default (null or empty: none). Throws a ValidationException naming `lowBalanceAt`. */
    public function setDefault(mixed $level): void
    {
        $this->settings->put(self::DEFAULT, $this->checked($level));
    }

    /** Set the account's own level (null or empty: the operator's default). Throws a ValidationException naming `lowBalanceAt`. */
    public function setOwn(Workspace $workspace, mixed $level): void
    {
        $workspace->forceFill(['low_balance_at' => $this->checked($level)])->save();
    }

    /** Whether this member may choose the account's level (and gets its balance emails). */
    public function mayChoose(WorkspaceMember $membership): bool
    {
        return $membership->exists && in_array($membership->role, config('kit.wallet.alert_roles'), true);
    }

    /** The account's warning level: its own, else the default; null for none (zero is none). */
    public function levelOf(Workspace $workspace): ?BigDecimal
    {
        $level = $workspace->low_balance_at ?? $this->defaultLevel();
        if ($level === null) {
            return null;
        }
        $level = BigDecimal::of($level);

        return $level->isPositive() ? $level : null;
    }

    /**
     * Inside an entry's transaction: set the locked account's email marks for its new balance (Wallet saves them with
     * it), and answer the email to send once committed, if any. `$fell`: whether the entry lowered the balance.
     */
    public function decide(Workspace $locked, BigDecimal $balance, bool $fell): ?string
    {
        $level = $this->levelOf($locked);
        $empty = ! $balance->isPositive();
        if (! $empty && ($level === null || $balance->isGreaterThan($level))) {
            $locked->forceFill(['low_balance_warned_at' => null, 'out_of_credit_warned_at' => null]);

            return null;
        }
        if (! $empty) {
            $locked->forceFill(['out_of_credit_warned_at' => null]);
        }
        $kind = $empty
            ? ($locked->out_of_credit_warned_at === null ? self::EMPTY : null)
            : ($locked->low_balance_warned_at === null ? self::LOW : null);
        if ($kind === null || ! $fell || $locked->status !== Workspace::ACTIVE || ! $this->pricing->inForce()) {
            return null;
        }
        $now = now();
        $locked->forceFill($empty
            ? ['out_of_credit_warned_at' => $now, 'low_balance_warned_at' => $locked->low_balance_warned_at ?? $now]
            : ['low_balance_warned_at' => $now]);

        return $kind;
    }

    /** Send the email decide() answered, with the balance it was decided at, to each member who gets it. */
    public function send(Workspace $workspace, string $kind, string $balance): void
    {
        if (! $this->emails->on()) {
            return;
        }
        $currency = (string) $this->pricing->settings()['currency'];
        $level = $this->levelOf($workspace);
        $vars = [
            'account' => $workspace->name,
            'balance' => $this->money($balance, $currency),
            'level' => $level === null ? '' : $this->money((string) $level, $currency),
        ];
        $members = WorkspaceMember::query()->with('user')->where('workspace_id', $workspace->id)
            ->whereIn('role', config('kit.wallet.alert_roles'))->orderBy('id')->get();
        foreach ($members as $member) {
            // A member who signed up with their phone has no email (owner 11-01: balance emails go to those who do).
            if ($member->user?->email !== null) {
                $this->emails->send($kind, $member->user->email, $vars + ['name' => $member->user->name], $this->senders->link('console/wallet'));
            }
        }
    }

    /** A level as typed: zero or more, with at most the currency's places; empty is none. */
    private function checked(mixed $level): ?string
    {
        if ($level === null || $level === '') {
            return null;
        }
        $currency = $this->pricing->settings()['currency'];
        $places = $currency === null ? (int) config('kit.payments.default_decimals') : Money::decimals($currency);
        if (! is_string($level) || ! preg_match(self::AMOUNT, $level) || BigDecimal::of($level)->getScale() > $places) {
            throw ValidationException::withMessages(['lowBalanceAt' => [__('wallet.level_shape', ['places' => $places])]]);
        }

        return $level;
    }

    private function money(string $amount, string $currency): string
    {
        return BigDecimal::of($amount)->toScale(Money::decimals($currency), RoundingMode::HalfUp).' '.$currency;
    }
}
