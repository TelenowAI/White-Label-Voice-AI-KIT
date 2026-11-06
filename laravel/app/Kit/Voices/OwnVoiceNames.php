<?php

namespace App\Kit\Voices;

use App\Models\Agent;
use App\Models\OwnVoice;
use App\Models\OwnVoiceClone;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * How an own voice is named on an agent. Customers name one `own:<the kit's id>` (config('kit.own_voices.kit_prefix')); Telenow names it `profile:<its profile id>`
 * (`telenow_prefix`), which it resolves to the clone on the agent's provider inside the account only (recorded). The provider's own id for a clone is never used: Telenow
 * lets any account hear a clone by that id, and keeps it to no account once a clone is made again (recorded, contract_k12voices.py).
 *
 * A voice can be named only while it is the account's, not deleted, and cloned ready on that provider. Used by App\Kit\Catalog\Catalog (an agent's voice setup) and
 * App\Kit\Catalog\VoicePreview (hearing one). Not here: making, cloning and deleting them (OwnVoices).
 */
final class OwnVoiceNames
{
    /** Whether a voice as a customer names it is one of the account's own. */
    public static function isOwn(mixed $voice): bool
    {
        return is_string($voice) && str_starts_with($voice, config('kit.own_voices.kit_prefix'));
    }

    /** How a customer names this voice. */
    public static function kitName(OwnVoice $voice): string
    {
        return config('kit.own_voices.kit_prefix').$voice->id;
    }

    /** How Telenow names this voice on an agent and in a preview. */
    public static function telenowName(OwnVoice $voice): string
    {
        return config('kit.own_voices.telenow_prefix').$voice->telenow_id;
    }

    /** The account's own voice a customer named, cloned ready on this provider (Telenow's id), or null. */
    public function ready(Workspace $workspace, string $providerId, mixed $voice): ?OwnVoice
    {
        if (! self::isOwn($voice)) {
            return null;
        }

        return $this->usable($workspace->id, $providerId)->whereKey(substr($voice, strlen(config('kit.own_voices.kit_prefix'))))->first();
    }

    /** The own voice an agent's voice column holds as Telenow names it, cloned ready on the agent's provider, or null. */
    public function onAgent(Agent $agent, ?string $providerId, mixed $column): ?OwnVoice
    {
        $prefix = config('kit.own_voices.telenow_prefix');
        if (! is_string($providerId) || ! is_string($column) || ! str_starts_with($column, $prefix)) {
            return null;
        }

        return $this->usable($agent->workspace_id, $providerId)->where('telenow_id', substr($column, strlen($prefix)))->first();
    }

    private function usable(string $workspaceId, string $providerId): Builder
    {
        return OwnVoice::query()->where('workspace_id', $workspaceId)->whereNull('deleted_at')->whereNotNull('telenow_id')
            ->whereHas('clones', fn (Builder $q) => $q->where('provider_id', $providerId)->where('status', OwnVoiceClone::READY));
    }
}
