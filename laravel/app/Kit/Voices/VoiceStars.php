<?php

namespace App\Kit\Voices;

use App\Kit\Catalog\Catalog;
use App\Kit\Catalog\CatalogVoices;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The voices a member starred in an account (Console → Voices; the agent's voice picker shows them first): the kit's own, one list each. Telenow keeps one list of stars for
 * every key of an account (its stars belong to the key's creator, one for a whole sub-account), so they would not be a member's (recorded).
 *
 * Only an offered provider's voice can be starred, at most config('kit.voice_stars.per_member'); unstarring one that is not starred is fine. Not here: the voices (CatalogVoices),
 * the page (Console\VoicesController).
 */
final class VoiceStars
{
    public function __construct(private readonly Catalog $catalog, private readonly CatalogVoices $voices) {}

    /** @return list<array{0: string, 1: string}> the member's stars, as [provider id, voice id] */
    public function of(WorkspaceMember $member): array
    {
        return DB::table('voice_stars')->where('workspace_id', $member->workspace_id)->where('user_id', $member->user_id)->orderBy('id')
            ->get(['provider_id', 'voice_id'])->map(fn ($r) => [$r->provider_id, $r->voice_id])->all();
    }

    public function star(WorkspaceMember $member, array $input): void
    {
        [$provider, $voice] = $this->named($input);
        if ($this->voices->find($provider['id'], $voice) === null) {
            throw ValidationException::withMessages(['voice' => [__('voices.unknown_voice')]]);
        }
        $mine = DB::table('voice_stars')->where('workspace_id', $member->workspace_id)->where('user_id', $member->user_id);
        if ((clone $mine)->where('provider_id', $provider['id'])->where('voice_id', $voice)->exists()) {
            return;
        }
        if ((clone $mine)->count() >= config('kit.voice_stars.per_member')) {
            throw ValidationException::withMessages(['voice' => [__('voices.stars_full', ['max' => config('kit.voice_stars.per_member')])]]);
        }
        DB::table('voice_stars')->insertOrIgnore(['workspace_id' => $member->workspace_id, 'user_id' => $member->user_id, 'provider_id' => $provider['id'], 'voice_id' => $voice,
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function unstar(WorkspaceMember $member, array $input): void
    {
        [$provider, $voice] = $this->named($input);
        DB::table('voice_stars')->where('workspace_id', $member->workspace_id)->where('user_id', $member->user_id)->where('provider_id', $provider['id'])->where('voice_id', $voice)->delete();
    }

    /** @return array{0: array, 1: string} the offered provider and the voice id a request names */
    private function named(array $input): array
    {
        $values = Validator::make($input, ['provider' => ['required', 'string'], 'voice' => ['required', 'string', 'max:255']], [],
            ['provider' => __('voices.fields.provider'), 'voice' => __('voices.fields.voice')])->validate();
        $provider = $this->catalog->offeredByKey('tts', $values['provider']);
        if ($provider === null) {
            throw ValidationException::withMessages(['provider' => [__('catalog.not_offered')]]);
        }

        return [$provider, $values['voice']];
    }
}
