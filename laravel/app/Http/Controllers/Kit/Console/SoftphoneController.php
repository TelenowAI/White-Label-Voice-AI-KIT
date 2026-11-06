<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Softphone\BrowserCalls;
use App\Kit\Softphone\SoftphoneRefused;
use App\Models\Call;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Console → Phone (contract/kit-api.yaml getPhone, placeBrowserCall, hangUpBrowserCall, transferBrowserCall): what the page offers (whether calls may be placed, the
 * account's numbers to call from, whether its calls need consent), placing a call from the browser, ending it and handing it to another number (App\Kit\Softphone).
 * Every member may place one; ending or transferring it is for the member who placed it, or one who manages the account. A call of another account is 404.
 */
final class SoftphoneController
{
    public function __construct(private readonly BrowserCalls $calls) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->calls->page($this->membership($request)->workspace));
    }

    public function place(Request $request): JsonResponse
    {
        return $this->refusals(fn () => response()->json($this->calls->place($this->membership($request), $request->all()), 201));
    }

    public function hangUp(Request $request, string $id): JsonResponse|Response
    {
        return $this->onCall($request, $id, function (Call $call) use ($request) {
            $this->calls->hangUp($this->membership($request), $call);

            return response()->noContent();
        });
    }

    public function transfer(Request $request, string $id): JsonResponse|Response
    {
        return $this->onCall($request, $id, function (Call $call) use ($request) {
            $this->calls->transfer($this->membership($request), $call, $request->all());

            return response()->noContent();
        });
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function onCall(Request $request, string $id, \Closure $act): JsonResponse|Response
    {
        $call = Str::isUuid($id) ? Call::query()->with('workspace')->where('workspace_id', $this->membership($request)->workspace_id)->find($id) : null;
        if ($call === null) {
            return response()->json(['message' => __('calls.not_found')], 404);
        }

        return $this->refusals(fn () => $act($call));
    }

    private function refusals(\Closure $act): JsonResponse|Response
    {
        try {
            return $act();
        } catch (SoftphoneRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
