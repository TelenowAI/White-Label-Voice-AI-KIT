<?php

namespace App\Kit\Email;

use App\Kit\Setup\Field;

/**
 * One way of sending email (SELF_HOSTED_KIT_PLAN.md §4.7): it declares its own form, so the wizard's Email step and
 * Admin → Email draw it with no change, and it sends one message with the config the operator saved. Drivers are
 * listed in config('kit.email.drivers'); the texts for a driver live in lang/<locale>/emails.php under its id.
 *
 * Not here: which senders are on and in what order (Senders), retrying (Emails).
 */
interface EmailDriver
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

    /** Send one message, or throw EmailFailed. */
    public function send(array $config, EmailMessage $message): void;
}
