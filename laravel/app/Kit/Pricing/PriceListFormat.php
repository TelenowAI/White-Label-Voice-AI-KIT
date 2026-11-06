<?php

namespace App\Kit\Pricing;

/**
 * Whether a price list an operator saves is in the pricing spec's format (contract/pricing/price-list.schema.json):
 * what is wrong with it, in words an operator can act on, or nothing. Held to the schema itself by the shared cases in
 * contract/pricing/list-cases.json (tests/Unit/PriceListFormatTest.php): this check and the schema agree on each.
 *
 * Takes the list as App\Kit\Support\Json::decode gives it, so `{}` (an object) is never mistaken for `[]` (a list).
 * Not here: whether the plans it extends exist (Pricing), pricing itself (PricingEngine).
 */
final class PriceListFormat
{
    private const ID = '/^[a-z0-9][a-z0-9-]*$/';

    private const NON_NEGATIVE = '/^(0|[1-9][0-9]*)(\.[0-9]+)?$/';

    private const MARKUP = '/^(-(100(\.0+)?|[1-9]?[0-9](\.[0-9]+)?)|(0|[1-9][0-9]*)(\.[0-9]+)?)$/';

    private const FIELDS = ['id', 'extends', 'currency', 'decimals', 'rounding', 'billingIncrementSeconds', 'rules'];

    private const REQUIRED = ['id', 'currency', 'decimals', 'rounding', 'billingIncrementSeconds', 'rules'];

    private const ROUNDING = ['half-up', 'half-even', 'up', 'down'];

    private const ITEMS = ['call', 'component', 'fee'];

    /** @return list<string> */
    public static function problems(mixed $list): array
    {
        if (! self::isObject($list)) {
            return ['A price list is an object.'];
        }
        $list = (array) $list;
        $p = [];
        foreach (array_diff(array_keys($list), self::FIELDS) as $field) {
            $p[] = "`{$field}` is not a field of a price list.";
        }
        foreach (self::REQUIRED as $field) {
            if (! array_key_exists($field, $list)) {
                $p[] = "`{$field}` is missing.";
            }
        }
        if (array_key_exists('id', $list) && ! self::matches($list['id'], self::ID)) {
            $p[] = '`id` is lower-case letters, digits and dashes.';
        }
        if (array_key_exists('extends', $list) && ! self::matches($list['extends'], self::ID)) {
            $p[] = '`extends` is the id of another list.';
        }
        if (array_key_exists('currency', $list) && ! self::matches($list['currency'], '/^[A-Z]{3}$/')) {
            $p[] = '`currency` is a three-letter ISO 4217 code, such as USD.';
        }
        if (array_key_exists('decimals', $list) && ! (is_int($list['decimals']) && $list['decimals'] >= 0 && $list['decimals'] <= 8)) {
            $p[] = '`decimals` is a whole number from 0 to 8.';
        }
        if (array_key_exists('rounding', $list) && ! in_array($list['rounding'], self::ROUNDING, true)) {
            $p[] = '`rounding` is one of '.implode(', ', self::ROUNDING).'.';
        }
        if (array_key_exists('billingIncrementSeconds', $list) && ! (is_int($list['billingIncrementSeconds']) && $list['billingIncrementSeconds'] >= 1)) {
            $p[] = '`billingIncrementSeconds` is a whole number of seconds, at least 1.';
        }
        if (array_key_exists('rules', $list)) {
            if (! is_array($list['rules']) || ! array_is_list($list['rules'])) {
                $p[] = '`rules` is a list.';
            } else {
                foreach ($list['rules'] as $i => $rule) {
                    foreach (self::rule($rule) as $problem) {
                        $p[] = 'Rule '.($i + 1).": {$problem}";
                    }
                }
            }
        }

        return $p;
    }

    /** @return list<string> */
    private static function rule(mixed $rule): array
    {
        if (! self::isObject($rule)) {
            return ['a rule is an object with `id`, `match` and `price`.'];
        }
        $rule = (array) $rule;
        $p = [];
        foreach (array_diff(array_keys($rule), ['id', 'match', 'price']) as $field) {
            $p[] = "`{$field}` is not a field of a rule.";
        }
        if (! self::matches($rule['id'] ?? null, self::ID)) {
            $p[] = '`id` is lower-case letters, digits and dashes.';
        }
        $match = $rule['match'] ?? null;
        $item = self::isObject($match) ? (((array) $match)['item'] ?? null) : null;
        if (! in_array($item, self::ITEMS, true)) {
            $p[] = '`match.item` is one of '.implode(', ', self::ITEMS).'.';
        }
        foreach (self::isObject($match) ? (array) $match : [] as $key => $value) {
            if ($key !== 'item' && ! (is_string($value) || (is_array($value) && array_is_list($value) && $value !== [] && array_filter($value, 'is_string') === $value))) {
                $p[] = "`match.{$key}` is a value, or a list of values.";
            }
        }
        $methods = $item === 'fee' ? ['markupPercent', 'fixed'] : ['markupPercent', 'perMinute'];
        $price = self::isObject($rule['price'] ?? null) ? (array) $rule['price'] : [];
        if (count($price) !== 1 || ! in_array(array_key_first($price), $methods, true)) {
            $p[] = '`price` is one of '.implode(', ', $methods).($item === null ? '' : " for a {$item}").'.';
        } elseif (! self::matches(reset($price), array_key_first($price) === 'markupPercent' ? self::MARKUP : self::NON_NEGATIVE)) {
            $p[] = array_key_first($price) === 'markupPercent'
                ? '`price.markupPercent` is a percentage written as text, not below -100.'
                : '`price.'.array_key_first($price).'` is an amount written as text, such as "1.50".';
        }

        return $p;
    }

    private static function isObject(mixed $value): bool
    {
        return $value instanceof \stdClass || (is_array($value) && $value !== [] && ! array_is_list($value));
    }

    private static function matches(mixed $value, string $pattern): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }
}
