<?php

namespace App\Kit\Sms;

use App\Kit\Setup\Field;

/**
 * One way of sending a text (SELF_HOSTED_KIT_PLAN.md §4.7): it declares its own form, so Admin → SMS draws it with no
 * change, and it sends one text with the config the operator saved. Drivers are listed in config('kit.sms.drivers');
 * the texts for a driver live in lang/<locale>/sms.php under its id.
 *
 * Not here: which senders are on and in what order (SmsSenders), the kit's texts (Sms).
 */
interface SmsDriver
{
    /**
     * Its form, the fields named without a prefix. A secret's value is never filled in; `secretSet` says one is saved.
     *
     * @return list<Field>
     */
    public function fields(array $config): array;

    /** @return array<string, list<mixed>> Laravel validation rules for its fields */
    public function rules(): array;

    /** @return list<string> its secret fields: left empty on an edit, the saved value is kept */
    public function secrets(): array;

    /** A short description for the admin's list of senders, with no secret in it. */
    public function label(array $config): string;

    /** How it reaches people: `sms` or `whatsapp`, so the pages can say where to look for a code. */
    public function channel(): string;

    /** Send one text, or throw SmsFailed in the provider's words. */
    public function send(array $config, SmsMessage $message): void;
}
