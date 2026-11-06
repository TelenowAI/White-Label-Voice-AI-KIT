<?php

namespace App\Kit\Outbox;

use App\Kit\Telenow\TelenowError;

/**
 * Sends one kind of write to Telenow and records the outcome on the kit row it changes. Registered per
 * '<resource>.<operation>' in config('kit.outbox.handlers'); called by Outbox, which decides whether a refusal is
 * tried again or given up.
 */
interface OutboxHandler
{
    /** Send the write and store what Telenow answered on the kit row. Throws TelenowError when Telenow did not take it. */
    public function send(OutboxEntry $entry): void;

    /** It will be tried again (`$error` is why not yet). */
    public function waiting(OutboxEntry $entry, TelenowError $error): void;

    /** It was given up (`$error` is Telenow's reason, or the last one). */
    public function failed(OutboxEntry $entry, TelenowError $error): void;
}
