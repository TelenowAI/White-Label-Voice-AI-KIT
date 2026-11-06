<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Events\Inbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

use function Illuminate\Support\defer;

/**
 * POST /api/kit/telenow/events (contract/kit-api.yaml receiveTelenowEvent): Telenow's deliveries to the kit's events
 * endpoint. Stored and answered at once (Inbox::receive); applied right after the answer has gone (and by
 * `kit:events` for anything left). No session or CSRF: the signature is the proof.
 */
final class TelenowEventsController
{
    public function __invoke(Request $request, Inbox $inbox): Response|JsonResponse
    {
        $status = $inbox->receive(
            $request->getContent(),
            $request->header('X-VoiceAI-Signature'),
            $request->header('X-VoiceAI-Delivery'),
            $request->header('X-VoiceAI-Event'),
        );
        if ($status >= 300) {
            return response()->json(['message' => __("events.refused.{$status}")], $status);
        }
        defer(fn () => $inbox->applyDue());

        return response()->noContent();
    }
}
