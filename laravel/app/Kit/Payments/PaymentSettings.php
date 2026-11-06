<?php

namespace App\Kit\Payments;

use App\Kit\Pricing\Pricing;
use App\Kit\Settings\Settings;
use Brick\Math\BigDecimal;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;

/**
 * The operator's rules for topping up (SELF_HOSTED_KIT_PLAN.md §4.6 a), kept in settings: the smallest and largest
 * top-up (none: any amount above zero) in the kit's currency, and what a dispute does to the customer — one of
 * config('kit.payments.dispute_actions'): `nothing` (the money comes off the wallet only if the dispute is lost),
 * `hold` (the disputed amount comes off at once and comes back if the dispute is won), `suspend` (held, and the
 * account suspended until staff resume it).
 *
 * Not here: applying them (Payments).
 */
final class PaymentSettings
{
    public const MIN = 'payments.min_amount';

    public const MAX = 'payments.max_amount';

    public const DISPUTES = 'payments.dispute_action';

    private const AMOUNT = '/^(0|[1-9][0-9]*)(\.[0-9]+)?$/';

    public function __construct(private readonly Settings $settings, private readonly Pricing $pricing) {}

    /** @return array{minAmount: ?string, maxAmount: ?string, disputeAction: string} */
    public function current(): array
    {
        $action = $this->settings->get(self::DISPUTES);

        return [
            'minAmount' => $this->settings->get(self::MIN),
            'maxAmount' => $this->settings->get(self::MAX),
            'disputeAction' => in_array($action, config('kit.payments.dispute_actions'), true) ? $action : config('kit.payments.dispute_action'),
        ];
    }

    /** Save the rules. Throws a ValidationException naming what is wrong. */
    public function save(array $input): array
    {
        $currency = $this->pricing->settings()['currency'];
        $values = Validator::make($input, [
            'minAmount' => ['present', 'nullable', 'string'],
            'maxAmount' => ['present', 'nullable', 'string'],
            'disputeAction' => ['required', 'string', Rule::in(config('kit.payments.dispute_actions'))],
        ], [], ['minAmount' => __('payments.fields.minAmount'), 'maxAmount' => __('payments.fields.maxAmount'), 'disputeAction' => __('payments.fields.disputeAction')])->validate();
        $errors = [];
        foreach (['minAmount', 'maxAmount'] as $key) {
            $v = $values[$key] ?? null;
            if ($v !== null && $v !== '' && ($problem = $this->shape($v, $currency)) !== null) {
                $errors[$key][] = $problem;
            }
        }
        $min = ($values['minAmount'] ?? '') === '' ? null : $values['minAmount'];
        $max = ($values['maxAmount'] ?? '') === '' ? null : $values['maxAmount'];
        if ($errors === [] && $min !== null && $max !== null && BigDecimal::of($max)->isLessThan($min)) {
            $errors['maxAmount'][] = __('payments.max_below_min');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $this->settings->put(self::MIN, $min);
        $this->settings->put(self::MAX, $max);
        $this->settings->put(self::DISPUTES, $values['disputeAction']);

        return $this->current();
    }

    /** What is wrong with a top-up of `$amount` in `$currency`, or null. */
    public function amountProblem(mixed $amount, string $currency): ?string
    {
        if (! is_string($amount) || ($problem = $this->shape($amount, $currency)) !== null) {
            return $problem ?? __('payments.amount_shape', ['places' => Money::decimals($currency)]);
        }
        ['minAmount' => $min, 'maxAmount' => $max] = $this->current();
        $value = BigDecimal::of($amount);
        if ($min !== null && $value->isLessThan($min)) {
            return __('payments.amount_min', ['min' => Money::exact($min, $currency), 'currency' => $currency]);
        }
        if ($max !== null && $value->isGreaterThan($max)) {
            return __('payments.amount_max', ['max' => Money::exact($max, $currency), 'currency' => $currency]);
        }

        return null;
    }

    /** An amount above zero with at most the currency's places, or why not. */
    private function shape(string $amount, ?string $currency): ?string
    {
        $places = $currency === null ? (int) config('kit.payments.default_decimals') : Money::decimals($currency);
        if (! preg_match(self::AMOUNT, $amount) || BigDecimal::of($amount)->getScale() > $places || ! BigDecimal::of($amount)->isPositive()) {
            return __('payments.amount_shape', ['places' => $places]);
        }

        return null;
    }
}
