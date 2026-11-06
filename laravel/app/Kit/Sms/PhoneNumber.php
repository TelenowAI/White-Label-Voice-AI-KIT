<?php

namespace App\Kit\Sms;

/**
 * Phone numbers as the kit keeps them: E.164 (a `+`, the country code, the number), the shape config('kit.phone.pattern')
 * allows. People may type spaces, dashes, dots and brackets, and `00` for the `+`; nothing else is guessed (no country
 * is assumed). Used wherever a number comes in: a sign-in code, a person's own number, a test text.
 */
final class PhoneNumber
{
    /** The number in E.164, or null when it is not one. */
    public static function normalize(mixed $input): ?string
    {
        if (! is_string($input)) {
            return null;
        }
        $number = preg_replace('/[\s\-.()]/', '', $input);
        if (str_starts_with($number, '00')) {
            $number = '+'.substr($number, 2);
        }

        return preg_match(config('kit.phone.pattern'), $number) ? $number : null;
    }
}
