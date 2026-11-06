<?php

namespace App\Kit\Payments\Drivers;

use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * A gateway's short-lived access token (PhonePe, PayPal), kept encrypted in the cache until just before it runs out, under
 * a key that changes with the credentials, so new keys never use an old token. Asking fresh is how keys are tried.
 *
 * Not here: how a gateway makes one (the driver's `$ask`, which answers the token and the seconds it lasts).
 */
final class GatewayToken
{
    /** @param \Closure(): array{0: string, 1: int} $ask */
    public static function get(PaymentGateway $gateway, \Closure $ask, bool $fresh = false): string
    {
        $key = 'kit.payments.token.'.hash('sha256', json_encode([$gateway->id, $gateway->mode, $gateway->config]));
        $kept = $fresh ? null : Cache::get($key);
        if (is_string($kept)) {
            return Crypt::decryptString($kept);
        }
        [$token, $seconds] = $ask();
        $lasts = $seconds - (int) config('kit.payments.token_margin_seconds');
        if ($lasts > 0) {
            Cache::put($key, Crypt::encryptString($token), $lasts);
        }

        return $token;
    }
}
