<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Email\Senders;
use App\Kit\Payments\Payments;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * GET or POST /api/kit/payments/{paymentId}/return (contract/kit-api.yaml returnFromGateway, returnFromGatewayByPost):
 * where a gateway sends the payer back. Some gateways come back with a cross-site POST, which carries no session
 * cookie, so this needs none: it only makes the kit ask the gateway how the payment went (Payments::returned — what the
 * return itself says is never trusted), then sends the browser on to the page that shows it — the customer's wallet,
 * or Admin → Payments for a staff test — at the site address the operator confirmed.
 */
final class PaymentReturnController
{
    public function __invoke(Request $request, string $id, Payments $payments, Senders $senders): Response|JsonResponse
    {
        $payment = Str::isUuid($id) ? Payment::query()->with('gateway')->find($id) : null;
        if ($payment === null) {
            return response()->json(['message' => __('payments.no_payment')], 404);
        }
        $payment = $payments->returned($payment, $request->all());
        $page = $payment->isTest() ? 'admin/payments' : 'console/wallet';

        return response('', 303, ['Location' => $senders->link("{$page}?payment={$payment->id}")]);
    }
}
