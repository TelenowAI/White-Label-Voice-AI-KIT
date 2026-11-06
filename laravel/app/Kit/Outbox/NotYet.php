<?php

namespace App\Kit\Outbox;

use RuntimeException;

/**
 * Thrown by an outbox handler for a write whose time has not come: nothing was refused and nothing failed, something else
 * has to happen first (a closing customer's numbers released, its last calls charged). The message says what, for staff.
 * The outbox tries again after config('kit.outbox.not_yet_seconds'); the try is not counted, so waiting never gives a
 * write up. Thrown by App\Kit\Customers\Telenow\CloseAtTelenow; handled by Outbox.
 */
final class NotYet extends RuntimeException {}
