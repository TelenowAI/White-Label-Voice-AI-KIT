<?php

namespace App\Kit\Sms;

/**
 * One text to send: the number in E.164 (PhoneNumber), the words and, when it carries a one-time code, the code by itself
 * (WhatsApp sends a code through a template, not as words). Made by Sms for a driver.
 */
final class SmsMessage
{
    public function __construct(public readonly string $to, public readonly string $text, public readonly ?string $code = null) {}
}
