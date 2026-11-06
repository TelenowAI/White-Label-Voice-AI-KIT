<?php

namespace App\Kit\Email;

use RuntimeException;

/**
 * A sender could not send: its message is the driver's own reason (the server's answer, a refused connection), for
 * the operator's eyes only — Admin → Email and the wizard. Never shown to a customer.
 */
final class EmailFailed extends RuntimeException {}
