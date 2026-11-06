<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Voices\OwnVoiceRefused;
use App\Kit\Voices\OwnVoices;
use App\Models\OwnVoice;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Console → Voices → Your voices (contract/kit-api.yaml listOwnVoices, createOwnVoice, uploadOwnVoiceSample, cloneOwnVoice, removeOwnVoiceClone, deleteOwnVoice): the account's own
 * voices and what a manager may do with them (App\Kit\Voices\OwnVoices). Every member reads them; the account's managers change them. Another account's voice is 404.
 * Hearing one is previewVoice with its `own:` name (VoicesController); putting one on an agent is the agent's voice setup.
 */
final class OwnVoicesController
{
    public function __construct(private readonly OwnVoices $voices) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->voices->page($this->membership($request)));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->refusals(fn () => response()->json($this->voices->one($this->membership($request), $this->voices->create($this->membership($request), $request->all())), 201));
    }

    public function sample(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (OwnVoice $v) => response()->json($this->voices->one($this->membership($request), $this->voices->sample($this->membership($request), $v, $request->all()))));
    }

    public function clone(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (OwnVoice $v) => response()->json($this->voices->one($this->membership($request), $this->voices->cloneTo($this->membership($request), $v, $request->all()))));
    }

    public function removeClone(Request $request, string $id, string $provider): JsonResponse
    {
        return $this->with($request, $id, fn (OwnVoice $v) => response()->json($this->voices->one($this->membership($request), $this->voices->removeClone($this->membership($request), $v, $provider))));
    }

    public function destroy(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, function (OwnVoice $v) use ($request) {
            $this->voices->delete($this->membership($request), $v);

            return response()->noContent();
        });
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function with(Request $request, string $id, \Closure $act): JsonResponse|Response
    {
        $voice = Str::isUuid($id) ? $this->voices->find($this->membership($request)->workspace, $id) : null;
        if ($voice === null) {
            return response()->json(['message' => __('own_voices.not_found')], 404);
        }

        return $this->refusals(fn () => $act($voice));
    }

    private function refusals(\Closure $act): JsonResponse|Response
    {
        try {
            return $act();
        } catch (OwnVoiceRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
