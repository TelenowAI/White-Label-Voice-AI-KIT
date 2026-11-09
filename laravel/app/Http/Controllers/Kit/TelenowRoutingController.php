<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Routing\RoutingConnection;
use App\Kit\Routing\RoutingMatch;
use App\Models\PhoneNumber;
use App\Models\RoutingRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/kit/telenow/routing/{token} (contract/kit-api.yaml answerRoutingRequest): Telenow asking, on a call to one of an account's routed
 * numbers, who takes it. Answered at once from the account's rules (RoutingMatch): `{}` for the number's own agent, else the rule's answer. The
 * caller hears nothing until this answers, and past config('kit.routing.timeout_ms') Telenow gives the call to the number's own agent anyway.
 * No session or CSRF: the timed signature is the proof (RoutingConnection::verify); anything not signed for this account is refused (401).
 * Telenow's request also names its carrier and the carrier's call id: neither is kept.
 */
final class TelenowRoutingController
{
    public function __invoke(Request $request, string $token, RoutingConnection $connection): JsonResponse
    {
        $body = $request->getContent();
        $endpoint = $connection->verify($token, $request->header('X-Telenow-Signature-V2'), $body);
        if ($endpoint === null) {
            return response()->json(['message' => __('routing.not_signed')], 401);
        }
        $call = json_decode($body, true);
        $digits = is_array($call) ? (string) preg_replace('/\D/', '', (string) ($call['to_number'] ?? '')) : '';
        $number = $digits === '' ? null : PhoneNumber::query()->where('workspace_id', $endpoint->workspace_id)->whereNull('released_at')
            ->whereIn('e164', [$digits, '+'.$digits])->first();
        if ($number === null) {
            return response()->json(RoutingMatch::answer(null));
        }
        $rules = RoutingRule::query()->with('agent')->where('workspace_id', $endpoint->workspace_id)->where('enabled', true)
            ->orderBy('position')->orderBy('created_at')->get();
        $caller = is_string($call['caller_number'] ?? null) ? $call['caller_number'] : null;
        $forwarded = is_string($call['forwarded_from'] ?? null) ? $call['forwarded_from'] : null;

        return response()->json(RoutingMatch::answer(RoutingMatch::first($rules, $number->id, $caller, $forwarded, now())));
    }
}
