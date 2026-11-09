<?php

namespace App\Http\Controllers\Kit;

use App\Models\Agent;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * An agent's logo as an image (contract/kit-api.yaml getAgentLogo; App\Kit\Agents\AgentLogos): anyone may read it — the agent's chat box on a
 * customer's website shows it — but only the agent's current image, by the name it is kept under, is ever served. Its name is its content's, so it
 * is cached for a year; it is sent as an image that runs nothing (`nosniff`, a policy that allows nothing).
 */
final class AgentLogoController
{
    public function show(string $agent, string $file): Response
    {
        $row = Str::isUuid($agent) ? Agent::query()->whereNull('deleted_at')->find($agent, ['id', 'logo']) : null;
        $disk = Storage::disk(config('kit.agents.logo.disk'));
        if ($row === null || ! is_string($row->logo) || basename($row->logo) !== $file || ! $disk->exists($row->logo)) {
            return response()->json(['message' => __('agents.logo.not_found')], 404);
        }
        $type = array_search(pathinfo($file, PATHINFO_EXTENSION), config('kit.agents.logo.types'), true);

        return response((string) $disk->get($row->logo), 200, [
            'Content-Type' => is_string($type) ? $type : 'application/octet-stream',
            'Cache-Control' => 'public, max-age='.config('kit.agents.logo.cache_seconds').', immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
