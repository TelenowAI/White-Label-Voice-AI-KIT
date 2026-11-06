<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Oidc\OidcProviders;
use App\Kit\Oidc\OidcRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Http\Response;

/**
 * Admin → Sign-in providers (contract/kit-api.yaml getSsoSettings, addSsoProvider, updateSsoProvider, deleteSsoProvider,
 * orderSsoProviders): the OpenID Connect providers customers may sign in with, in the order the sign-in page shows them
 * — a client secret is never answered and is kept when left empty — the presets, and the address to register at each
 * provider. Adding or changing one reads its issuer first.
 *
 * Not here: signing in (App\Kit\Oidc\OidcSignIn, OidcController).
 */
final class SsoController
{
    public function __construct(private readonly OidcProviders $providers) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function add(Request $request): JsonResponse
    {
        return $this->refusals(function () use ($request) {
            $this->providers->add($request->all());

            return response()->json($this->payload(), 201);
        });
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->refusals(function () use ($request, $id) {
            $this->providers->update($id, $request->all());

            return response()->json($this->payload());
        });
    }

    public function delete(string $id): JsonResponse|Response
    {
        return $this->refusals(function () use ($id) {
            $this->providers->remove($id);

            return response()->noContent();
        });
    }

    public function order(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['present', 'array'], 'ids.*' => ['string']]);

        return $this->refusals(function () use ($request) {
            $this->providers->order($request->input('ids'));

            return response()->json($this->payload());
        });
    }

    private function refusals(\Closure $act): JsonResponse|Response
    {
        try {
            return $act();
        } catch (OidcRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    private function payload(): array
    {
        return [
            'redirectUri' => $this->providers->redirectUri(),
            'maxProviders' => config('kit.oidc.max_providers'),
            'presets' => array_map(fn (string $id) => ['value' => $id, 'label' => __("oidc.presets.{$id}"), 'issuer' => config("kit.oidc.presets.{$id}.issuer"), 'help' => Lang::has("oidc.preset_help.{$id}") ? __("oidc.preset_help.{$id}") : null],
                array_keys(config('kit.oidc.presets'))),
            'providers' => array_map(fn (array $p) => [
                'id' => $p['id'], 'preset' => $p['preset'], 'name' => $p['name'], 'issuer' => $this->providers->issuer($p), 'clientId' => $p['clientId'],
                'secretSet' => $p['clientSecret'] !== '', 'enabled' => $p['enabled'],
            ], $this->providers->all()),
        ];
    }
}
