<?php

namespace App\Kit\Voices;

use App\Kit\Audit\Audit;
use App\Kit\Catalog\Catalog;
use App\Kit\Console\ServicePause;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Agent;
use App\Models\OwnVoice;
use App\Models\OwnVoiceClone;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * An account's own voices (Console → Voices → Your voices): a person who agreed to have their voice cloned, made at Telenow as a voice profile with the account's own key, a sample of
 * them speaking, and clones of it into the operator's offered voice providers that can clone, which the account's agents may then speak with (named as OwnVoiceNames says).
 *
 * - Who: the account's managers make, clone, remove and delete them; every member sees them and may put a ready one on an agent. Off until the operator allows cloning, and at most
 *   so many clones an account (Cloning); none made while the service is paused for the account. Removing and deleting are always allowed: it is the person's voice.
 * - The person: always named, with how they relate to the account, and a member confirming the account holds their signed release; Telenow keeps that as its consent record, and the
 *   kit keeps who confirmed it and when, after the voice is deleted too. Telenow refuses "self" from a key (recorded), so a member's own voice is named like anyone's.
 * - The sample: forwarded to Telenow from the request and never kept here. Telenow reads it (ffmpeg, at least 3 s, up to 25 MB) and refuses what it cannot use (recorded).
 * - Cloning: once a provider for each voice. Telenow makes a second clone on the same provider without removing the first, which it then keeps to no account and lets anyone speak
 *   (recorded), so the kit asks to remove the first. One account's cloning is one at a time.
 * - A voice an agent speaks with cannot be removed from that provider, nor deleted: Telenow would quietly speak the provider's default voice instead (recorded).
 *
 * Not here: naming them on an agent or in a preview (OwnVoiceNames), the operator's switch (Cloning), the page (Console\OwnVoicesController).
 */
final class OwnVoices
{
    public const SOURCES = ['upload', 'record'];

    public function __construct(private readonly TelenowClient $telenow, private readonly Catalog $catalog, private readonly Cloning $cloning, private readonly ServicePause $pause, private readonly Audit $audit) {}

    public function manages(WorkspaceMember $member): bool
    {
        return in_array($member->role, config('kit.team.managers'), true);
    }

    /** The page: the account's voices, and, for a manager, what they may make. */
    public function page(WorkspaceMember $member): array
    {
        $workspace = $member->workspace;
        $manages = $this->manages($member);
        $paused = $this->pause->for($workspace);
        $limits = config('kit.own_voices');

        return [
            'allowed' => $this->cloning->allowed(),
            'manages' => $manages,
            'paused' => $paused['message'] ?? null,
            'clones' => ['used' => $this->clonesOf($workspace), 'limit' => $this->cloning->perAccount()],
            'targets' => $manages && $this->cloning->allowed() && $paused === null ? $this->targets($workspace) : [],
            'relations' => $limits['relations'],
            'releaseVersion' => $limits['release_version'],
            'limits' => [
                'nameMaxLength' => $limits['name_max_length'], 'personMaxLength' => $limits['person_max_length'], 'languageMaxLength' => $limits['language_max_length'],
                'scriptMaxLength' => $limits['script_max_length'], 'sampleMaxBytes' => self::sampleMaxBytes(), 'sampleMinSeconds' => $limits['sample_min_seconds'],
                'sampleTypes' => $limits['sample_types'],
            ],
            'voices' => $this->present($this->voicesOf($workspace), $manages),
        ];
    }

    /** One voice as the page shows it. */
    public function one(WorkspaceMember $member, OwnVoice $voice): array
    {
        return $this->present(collect([$voice->fresh(['clones', 'confirmer'])]), $this->manages($member))[0];
    }

    /** The account's voice by the kit's id, not deleted, or null. */
    public function find(Workspace $workspace, string $id): ?OwnVoice
    {
        return OwnVoice::query()->where('workspace_id', $workspace->id)->whereNull('deleted_at')->whereKey($id)->first();
    }

    public function create(WorkspaceMember $member, array $input): OwnVoice
    {
        $workspace = $this->mayMake($member);
        $limits = config('kit.own_voices');
        $values = Validator::make($input, [
            'name' => ['required', 'string', 'max:'.$limits['name_max_length']],
            'personName' => ['required', 'string', 'max:'.$limits['person_max_length']],
            'relation' => ['required', Rule::in($limits['relations'])],
            'confirmed' => ['accepted'],
            'language' => ['nullable', 'string', 'max:'.$limits['language_max_length']],
            'script' => ['nullable', 'string', 'max:'.$limits['script_max_length']],
            'source' => ['required', Rule::in(self::SOURCES)],
        ], ['confirmed.accepted' => __('own_voices.confirm_release')], $this->attributes())->validate();

        return $this->locked($workspace, function () use ($member, $workspace, $values, $limits) {
            if ($this->voicesOf($workspace)->count() >= $this->cloning->perAccount()) {
                throw new OwnVoiceRefused(409, __('own_voices.voices_full', ['limit' => $this->cloning->perAccount()]));
            }
            $body = array_filter([
                'name' => $values['name'], 'consent' => true, 'subject_relation' => $values['relation'], 'subject_name' => $values['personName'], 'subject_confirmed' => true,
                'source_language' => $values['language'] ?? null, 'sample_script' => $values['script'] ?? null, 'source_kind' => $values['source'],
                'consent_text_version' => $limits['release_version'], 'retention_days' => $limits['retention_days'],
            ], fn ($v) => $v !== null && $v !== '');
            try {
                $profile = $this->telenow->createVoiceProfile($workspace->telenow_key, $workspace->telenow_sub_account_id, $body);
            } catch (TelenowError $e) {
                throw $this->refused($e);
            }
            if (! is_string($profile['id'] ?? null)) {
                throw new OwnVoiceRefused(503, __('own_voices.unavailable'));
            }
            $voice = (new OwnVoice)->forceFill([
                'workspace_id' => $workspace->id, 'telenow_id' => $profile['id'], 'name' => $values['name'], 'person_name' => $values['personName'], 'relation' => $values['relation'],
                'language' => $values['language'] ?? null, 'script' => $values['script'] ?? null, 'source' => $values['source'], 'release_version' => $limits['release_version'],
                'confirmed_by' => $member->user_id, 'confirmed_at' => now(),
            ]);
            $voice->save();
            $this->audit->record($member->user, 'own_voices.created', 'own_voice', $voice->id, ['relation' => $voice->relation, 'releaseVersion' => $voice->release_version]);

            return $voice;
        });
    }

    /** The person speaking: the file forwarded to Telenow as it came, and not kept. */
    public function sample(WorkspaceMember $member, OwnVoice $voice, array $input): OwnVoice
    {
        $workspace = $this->mayMake($member);
        $values = Validator::make($input, [
            'file' => ['required', 'file', 'max:'.intdiv(self::sampleMaxBytes(), 1024), 'extensions:'.implode(',', config('kit.own_voices.sample_types'))],
            'seconds' => ['nullable', 'numeric', 'min:0'],
        ], [], $this->attributes())->validate();
        /** @var UploadedFile $file */
        $file = $values['file'];
        $stream = fopen($file->getRealPath(), 'r');
        $fields = array_filter(['seconds' => $values['seconds'] ?? null, 'kind' => $voice->source, 'script' => $voice->script], fn ($v) => $v !== null && $v !== '');
        try {
            $profile = $this->telenow->uploadVoiceSample($workspace->telenow_key, $workspace->telenow_sub_account_id, $voice->telenow_id, $stream, 'sample.'.strtolower($file->getClientOriginalExtension()),
                str_starts_with((string) $file->getClientMimeType(), 'audio/') ? $file->getClientMimeType() : 'application/octet-stream', $fields);
        } catch (TelenowError $e) {
            throw match (true) {
                $e->status === 413 => ValidationException::withMessages(['file' => [__('own_voices.sample_too_big', ['mb' => intdiv(self::sampleMaxBytes(), 1024 * 1024)])]]),
                $e->status === 400 => ValidationException::withMessages(['file' => [__('own_voices.sample_unusable', ['seconds' => config('kit.own_voices.sample_min_seconds')])]]),
                default => $this->refused($e),
            };
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        // Telenow keeps the length as a 32-bit float (12.2 comes back 12.199999809265, recorded in a browser): kept to the hundredth.
        $seconds = $profile['sample_seconds'] ?? ($values['seconds'] ?? null);
        $voice->forceFill(['sample_seconds' => is_numeric($seconds) ? round((float) $seconds, 2) : null, 'sample_type' => $profile['sample_content_type'] ?? null, 'sample_added_at' => now()])->save();
        $this->audit->record($member->user, 'own_voices.sample_added', 'own_voice', $voice->id);

        return $voice;
    }

    /** The voice cloned into one offered provider that can clone, on Telenow's keys. */
    public function cloneTo(WorkspaceMember $member, OwnVoice $voice, array $input): OwnVoice
    {
        $workspace = $this->mayMake($member);
        $values = Validator::make($input, ['provider' => ['required', 'string']], [], $this->attributes())->validate();
        $provider = $this->catalog->offeredByKey('tts', $values['provider']);
        if ($provider === null || ! $this->canClone($workspace, $provider['id'])) {
            throw ValidationException::withMessages(['provider' => [__('own_voices.cannot_clone_there')]]);
        }
        if ($voice->sample_added_at === null) {
            throw new OwnVoiceRefused(409, __('own_voices.needs_sample'));
        }

        return $this->locked($workspace, function () use ($member, $workspace, $voice, $provider) {
            if ($voice->clones()->where('provider_id', $provider['id'])->exists()) {
                throw new OwnVoiceRefused(409, __('own_voices.already_cloned', ['provider' => $provider['displayName']]));
            }
            if ($this->clonesOf($workspace) >= $this->cloning->perAccount()) {
                throw new OwnVoiceRefused(409, __('own_voices.clones_full', ['limit' => $this->cloning->perAccount()]));
            }
            try {
                $clone = $this->telenow->cloneVoice($workspace->telenow_key, $workspace->telenow_sub_account_id, $voice->telenow_id, $provider['id']);
            } catch (TelenowError $e) {
                throw $e->status === 400 ? ValidationException::withMessages(['provider' => [__('own_voices.cannot_clone_there')]]) : $this->refused($e);
            }
            (new OwnVoiceClone)->forceFill(['own_voice_id' => $voice->id, 'provider_id' => $provider['id'], 'telenow_id' => $clone['id'] ?? null,
                'status' => is_string($clone['status'] ?? null) ? $clone['status'] : OwnVoiceClone::READY, 'cloned_by' => $member->user_id])->save();
            $this->audit->record($member->user, 'own_voices.cloned', 'own_voice', $voice->id, ['provider' => $provider['id']]);

            return $voice;
        });
    }

    /** The voice's clone on one provider taken away, at the provider too; not while an agent speaks with it there. */
    public function removeClone(WorkspaceMember $member, OwnVoice $voice, string $providerKey): OwnVoice
    {
        $workspace = $this->managed($member);
        $clone = $voice->clones()->get()->first(fn (OwnVoiceClone $c) => Catalog::publicKey('tts', $c->provider_id) === $providerKey);
        if ($clone === null) {
            throw new OwnVoiceRefused(404, __('own_voices.no_such_clone'));
        }
        $this->notInUse($voice, $clone->provider_id);
        try {
            $this->telenow->removeVoiceClone($workspace->telenow_key, $workspace->telenow_sub_account_id, $voice->telenow_id, $clone->provider_id);
        } catch (TelenowError $e) {
            if ($e->status !== 404) {
                throw $this->refused($e);
            }
        }
        $clone->delete();
        $this->audit->record($member->user, 'own_voices.clone_removed', 'own_voice', $voice->id, ['provider' => $clone->provider_id]);

        return $voice;
    }

    /** The voice, its sample and its clones deleted at Telenow (and at the providers); the kit keeps the record of who confirmed the release. Not while an agent speaks with it. */
    public function delete(WorkspaceMember $member, OwnVoice $voice): void
    {
        $workspace = $this->managed($member);
        $this->notInUse($voice, null);
        $answer = [];
        try {
            $answer = $this->telenow->deleteVoiceProfile($workspace->telenow_key, $workspace->telenow_sub_account_id, $voice->telenow_id);
        } catch (TelenowError $e) {
            if ($e->status !== 404) {
                throw $this->refused($e);
            }
        }
        $voice->forceFill(['deleted_at' => now(), 'deleted_by' => $member->user_id])->save();
        $voice->clones()->delete();
        $this->audit->record($member->user, 'own_voices.deleted', 'own_voice', $voice->id, ['providerCopiesLeft' => count((array) ($answer['vendorDeleteFailures'] ?? []))]);
    }

    /** The offered voice providers that can clone for this account, as Telenow says (checkVoiceCloning), each read again after a while. @return list<array{key: string, name: string}> */
    public function targets(Workspace $workspace): array
    {
        if (! $this->hasKey($workspace)) {
            return [];
        }
        $targets = [];
        foreach ($this->catalog->offered('tts') as $p) {
            if ($this->canClone($workspace, $p['id'])) {
                $targets[] = ['key' => $p['key'], 'name' => $p['displayName']];
            }
        }

        return $targets;
    }

    /** The account's agents that speak with this voice (on this provider, when one is named). @return Collection<int, Agent> */
    public function agentsUsing(OwnVoice $voice, ?string $providerId): Collection
    {
        $tts = config('kit.catalog.kinds.tts');

        return Agent::query()->where('workspace_id', $voice->workspace_id)->whereNull('deleted_at')->where($tts['voice'], OwnVoiceNames::telenowName($voice))
            ->when($providerId !== null, fn ($q) => $q->where($tts['provider'], $providerId))->orderBy('name')->get();
    }

    /** The largest sample the kit takes: Telenow's, or less where PHP takes less. */
    public static function sampleMaxBytes(): int
    {
        return min((int) config('kit.own_voices.sample_max_bytes'), UploadedFile::getMaxFilesize());
    }

    private function canClone(Workspace $workspace, string $providerId): bool
    {
        $key = "kit:clone-target:{$workspace->id}:{$providerId}";
        $known = Cache::get($key);
        if (is_bool($known)) {
            return $known;
        }
        try {
            $can = ($this->telenow->checkVoiceCloning($workspace->telenow_key, $workspace->telenow_sub_account_id, $providerId)['supported'] ?? false) === true;
        } catch (TelenowError $e) {
            if ($e->status < 400 || $e->status >= 500 || $e->status === 429) {
                return false; // not known: asked again next time
            }
            $can = false;
        }
        Cache::put($key, $can, config('kit.own_voices.targets_cache_seconds'));

        return $can;
    }

    private function notInUse(OwnVoice $voice, ?string $providerId): void
    {
        $using = $this->agentsUsing($voice, $providerId);
        if ($using->isNotEmpty()) {
            throw new OwnVoiceRefused(409, __('own_voices.in_use', ['agents' => $using->pluck('name')->implode(', ')]));
        }
    }

    /** The account a manager may make voices for now. */
    private function mayMake(WorkspaceMember $member): Workspace
    {
        $workspace = $this->managed($member);
        if (! $this->cloning->allowed()) {
            throw new OwnVoiceRefused(409, __('own_voices.not_allowed'));
        }
        $paused = $this->pause->for($workspace);
        if ($paused !== null) {
            throw new OwnVoiceRefused(409, $paused['message']);
        }

        return $workspace;
    }

    private function managed(WorkspaceMember $member): Workspace
    {
        if (! $this->manages($member)) {
            throw new OwnVoiceRefused(403, __('own_voices.managers_only'));
        }
        if (! $this->hasKey($member->workspace)) {
            throw new OwnVoiceRefused(409, __('agents.account_not_ready'));
        }

        return $member->workspace;
    }

    private function hasKey(Workspace $workspace): bool
    {
        return is_string($workspace->telenow_key) && $workspace->telenow_key !== '' && is_string($workspace->telenow_sub_account_id) && $workspace->telenow_sub_account_id !== '';
    }

    /** One account's voices made or cloned one at a time, so its limits hold and no voice is cloned twice into one provider. */
    private function locked(Workspace $workspace, \Closure $do): mixed
    {
        $lock = Cache::lock("kit:own-voices:{$workspace->id}", (int) config('kit.telenow.timeout_seconds') * 2);
        if (! $lock->get()) {
            throw new OwnVoiceRefused(409, __('own_voices.busy'));
        }
        try {
            return $do();
        } finally {
            $lock->release();
        }
    }

    private function refused(TelenowError $e): OwnVoiceRefused
    {
        return match (true) {
            $e->status === 404 => new OwnVoiceRefused(409, __('own_voices.gone')),
            $e->status >= 400 && $e->status < 500 => new OwnVoiceRefused(409, __('own_voices.refused')),
            default => new OwnVoiceRefused(503, __('own_voices.unavailable')),
        };
    }

    /** @return Collection<int, OwnVoice> */
    private function voicesOf(Workspace $workspace): Collection
    {
        return OwnVoice::query()->where('workspace_id', $workspace->id)->whereNull('deleted_at')->with(['clones', 'confirmer'])->orderBy('created_at')->get();
    }

    private function clonesOf(Workspace $workspace): int
    {
        return OwnVoiceClone::query()->whereHas('voice', fn ($q) => $q->where('workspace_id', $workspace->id)->whereNull('deleted_at'))->count();
    }

    /** @param Collection<int, OwnVoice> $voices */
    private function present(Collection $voices, bool $manages): array
    {
        $tts = config('kit.catalog.kinds.tts');
        $names = $voices->map(fn (OwnVoice $v) => OwnVoiceNames::telenowName($v))->all();
        $using = Agent::query()->where('workspace_id', $voices->first()?->workspace_id)->whereNull('deleted_at')->whereIn($tts['voice'], $names)->orderBy('name')->get()->groupBy($tts['voice']);
        $offered = collect($this->catalog->offered('tts'))->keyBy('id');

        return $voices->map(fn (OwnVoice $v) => [
            'id' => $v->id,
            'voice' => OwnVoiceNames::kitName($v),
            'name' => $v->name,
            'personName' => $manages ? $v->person_name : null,
            'relation' => $manages ? $v->relation : null,
            'language' => $v->language,
            'source' => $v->source,
            'hasSample' => $v->sample_added_at !== null,
            'sampleSeconds' => $v->sample_seconds,
            'confirmedBy' => $manages ? $v->confirmer?->name : null,
            'confirmedAt' => $manages ? $v->confirmed_at?->toIso8601String() : null,
            'releaseVersion' => $manages ? $v->release_version : null,
            'createdAt' => $v->created_at?->toIso8601String(),
            'clones' => $v->clones->sortBy('created_at')->map(fn (OwnVoiceClone $c) => [
                'provider' => Catalog::publicKey('tts', $c->provider_id),
                'providerName' => $offered[$c->provider_id]['displayName'] ?? null,
                'status' => $c->status,
            ])->values()->all(),
            'usedBy' => ($using[OwnVoiceNames::telenowName($v)] ?? collect())->map(fn (Agent $a) => ['id' => $a->id, 'name' => $a->name])->values()->all(),
        ])->values()->all();
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return array_map(fn (string $k) => __("own_voices.fields.{$k}"), array_combine(['name', 'personName', 'relation', 'confirmed', 'language', 'script', 'source', 'file', 'seconds', 'provider'],
            ['name', 'personName', 'relation', 'confirmed', 'language', 'script', 'source', 'file', 'seconds', 'provider']));
    }
}
