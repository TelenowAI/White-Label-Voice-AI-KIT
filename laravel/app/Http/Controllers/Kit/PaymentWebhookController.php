<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Payments\Gateways;
use App\Kit\Payments\Payments;
use App\Models\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * POST /api/kit/payments/webhooks/{gatewayId} (contract/kit-api.yaml receivePaymentEvent): a gateway's events. No
 * session or CSRF: the gateway's signature, checked by its driver, is the proof (401 when it does not check out). A
 * removed gateway still lands here, so a late refund or dispute is not lost. Taken is a plain 200: Paystack and Flutterwave
 * count anything else, a 204 too, as failed and send it again.
 */
final class PaymentWebhookController
{
    public function __invoke(Request $request, string $id, Payments $payments, Gateways $gateways): Response|JsonResponse
    {
        $gateway = Str::isUuid($id) ? PaymentGateway::query()->find($id) : null;
        if ($gateway === null || ! in_array($gateway->driver, $gateways->driverIds(), true)) {
            return response()->json(['message' => __('payments.no_gateway')], 404);
        }
        $headers = array_map(fn (array $values) => (string) ($values[0] ?? ''), $request->headers->all());
        $status = $payments->receive($gateway, $request->getContent(), $headers);

        return $status === 401 ? response()->json(['message' => __('payments.signature')], 401) : response('');
    }
}
