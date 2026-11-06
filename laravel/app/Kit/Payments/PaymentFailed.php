<?php

namespace App\Kit\Payments;

use RuntimeException;

/**
 * A gateway refused or did not answer, in its own words — for staff (Admin → Payments), never shown to a customer.
 * Thrown by the drivers; a webhook whose signature does not check out throws it too.
 */
final class PaymentFailed extends RuntimeException {}
