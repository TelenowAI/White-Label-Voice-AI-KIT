<?php

namespace App\Kit\Payments;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * Amounts as gateways take them: a currency's decimal places — config('kit.payments.currency_decimals') when the
 * operator set them, else the currency's ISO 4217 minor units as ICU knows them (ext-intl), else
 * config('kit.payments.default_decimals') — and the whole number of minor units (paise, cents) most gateways want.
 * Used by Payments, PaymentSettings and the drivers.
 */
final class Money
{
    public static function decimals(string $currency): int
    {
        $set = config('kit.payments.currency_decimals');
        if (isset($set[$currency])) {
            return (int) $set[$currency];
        }
        if (class_exists(\NumberFormatter::class)) {
            return (int) (new \NumberFormatter("en@currency={$currency}", \NumberFormatter::CURRENCY))->getAttribute(\NumberFormatter::MAX_FRACTION_DIGITS);
        }

        return (int) config('kit.payments.default_decimals');
    }

    /** `$amount` written with exactly the currency's places ("500" → "500.00"). */
    public static function exact(string $amount, string $currency): string
    {
        return (string) BigDecimal::of($amount)->toScale(self::decimals($currency));
    }

    /**
     * A number a gateway answered (500, "500.5", XOF "1000.00") with the currency's places. Places that are not zeros are
     * kept: that is a different amount, and it must read as one rather than be rounded to what was asked.
     */
    public static function written(string $amount, string $currency): string
    {
        $value = BigDecimal::of($amount);
        $exact = $value->toScale(self::decimals($currency), RoundingMode::Down);

        return (string) ($exact->isEqualTo($value) ? $exact : $value);
    }

    /** "500.00" USD → 50000. The amount must already fit the currency's places. */
    public static function toMinor(string $amount, string $currency): int
    {
        return BigDecimal::of($amount)->toScale(self::decimals($currency))->getUnscaledValue()->toInt();
    }

    /** 50000 USD → "500.00". */
    public static function fromMinor(int|string $minor, string $currency): string
    {
        return (string) BigDecimal::ofUnscaledValue(BigInteger::of($minor), self::decimals($currency));
    }
}
