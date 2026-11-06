<?php

namespace App\Kit\Events;

/**
 * Applies one kind of Telenow event to the kit's database (config('kit.events.handlers') names one per event).
 * It throws to have the event tried again later (Telenow not reachable, say); an event that is not for the kit (the
 * operator's own call, an account the kit does not know) is simply left alone.
 */
interface EventHandler
{
    /** @param array<string, mixed> $body the event as Telenow sent it */
    public function apply(array $body): void;
}
