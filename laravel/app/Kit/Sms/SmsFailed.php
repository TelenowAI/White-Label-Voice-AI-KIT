<?php

namespace App\Kit\Sms;

use RuntimeException;

/** A sender could not send a text, in its provider's words — for staff (Admin → SMS). Thrown by the drivers. */
final class SmsFailed extends RuntimeException {}
