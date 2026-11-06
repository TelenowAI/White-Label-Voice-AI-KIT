<?php

namespace App\Kit\Catalog;

use App\Kit\Console\ServicePause;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Kit\Voices\OwnVoiceNames;
use App\Models\Agent;
use App\Models\Workspace;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Hearing a voice before choosing it (`previewVoice`, a realtime voice `previewRealtimeVoice`) and hearing an agent (`previewAgentVoice`), with the customer's own
 * key: a short WAV clip made at Telenow on demand (SELF_HOSTED_KIT_PLAN.md §3.8 b), never stored. A clip is paid at
 * Telenow, so none is asked for while the service is paused for the account (ServicePause); only an offered voice can be
 * heard (or a voice of the account's own cloned on that provider, sent as Telenow names it: App\Kit\Voices\OwnVoiceNames). Telenow's refusals (its throttle, its spend check, a vendor it cannot reach — whose message names the vendor's
 * address) come back in the kit's words.
 *
 * Not here: which voices are offered (Catalog), the pages (VoicesController, AgentsController).
 */
final class VoicePreview
{
    public function __construct(private readonly TelenowClient $telenow, private readonly ServicePause $pause, private readonly Catalog $catalog, private readonly OwnVoiceNames $ownVoices, private readonly RealtimeCatalog $realtime) {}

    /** @return array{type: string, bytes: string} */
    public function voice(Workspace $workspace, array $input): array
    {
        $values = Validator::make($input, [
            'provider' => ['required', 'string'],
            'voice' => ['required', 'string'],
            'text' => ['nullable', 'string', 'max:'.config('kit.catalog.preview_text_max')],
        ], [], ['provider' => __('voices.fields.provider'), 'voice' => __('voices.fields.voice'), 'text' => __('voices.fields.text')])->validate();
        $provider = $this->catalog->offeredByKey('tts', $values['provider']);
        if ($provider !== null && OwnVoiceNames::isOwn($values['voice'])) {
            $own = $this->ownVoices->ready($workspace, $provider['id'], $values['voice']);
            $sent = $own === null ? null : OwnVoiceNames::telenowName($own);
        } else {
            $sent = $provider === null ? null : $this->catalog->voice($provider, $values['voice'])?->voice_id;
        }
        if ($sent === null) {
            throw ValidationException::withMessages(['voice' => [__('voices.unknown_voice')]]);
        }
        $key = $this->ready($workspace);

        return $this->clip(fn () => $this->telenow->previewVoice($key, $provider['id'], ['text' => $this->text($values), 'voice' => $sent]));
    }

    /**
     * A realtime voice (`previewRealtimeVoice`): a provider by the kit's key, one of its offered models and, optionally, one of that model's voices (none: the voice the
     * model recommends). Telenow opens one short realtime session for it, and answers 404 while its platform runs no realtime voices (recorded).
     *
     * @return array{type: string, bytes: string}
     */
    public function realtime(Workspace $workspace, array $input): array
    {
        $values = Validator::make($input, [
            'provider' => ['required', 'string'],
            'model' => ['required', 'string'],
            'voice' => ['nullable', 'string'],
            'text' => ['nullable', 'string', 'max:'.config('kit.catalog.preview_text_max')],
        ], [], ['provider' => __('voices.fields.provider'), 'voice' => __('voices.fields.voice'), 'text' => __('voices.fields.text')])->validate();
        $pick = $this->realtime->choice(['provider' => $values['provider'], 'model' => $values['model'], 'voice' => $values['voice'] ?? null], '');
        $voices = collect(collect($this->realtime->offered())->first(fn (array $p) => $p['id'] === $pick['provider'])['curatedModels'])
            ->first(fn (array $m) => $m['id'] === $pick['model'])['voices'];
        $voice = $pick['voice'] ?? (collect($voices)->first(fn (array $v) => ($v['recommended'] ?? false) === true) ?? $voices[0] ?? ['id' => null])['id'];
        if ($voice === null) {
            throw ValidationException::withMessages(['voice' => [__('voices.unknown_voice')]]);
        }
        $key = $this->ready($workspace);

        return $this->clip(fn () => $this->telenow->previewRealtimeVoice($key, $pick['provider'], ['model' => $pick['model'], 'voice' => $voice, 'text' => $this->text($values)]),
            [404 => __('catalog.realtime_off')]);
    }

    /** @return array{type: string, bytes: string} */
    public function agent(Agent $agent, array $input): array
    {
        $values = Validator::make($input, ['text' => ['nullable', 'string', 'max:'.config('kit.catalog.preview_text_max')]],
            [], ['text' => __('voices.fields.text')])->validate();
        if ($agent->telenow_id === null) {
            throw new CatalogRefused(409, __('agents.not_ready'));
        }
        $key = $this->ready($agent->workspace);

        return $this->clip(fn () => $this->telenow->previewAgentVoice($key, $agent->telenow_id, ['text' => $this->text($values)]));
    }

    private function text(array $values): string
    {
        $text = trim((string) ($values['text'] ?? ''));

        return $text !== '' ? $text : __('voices.preview_text');
    }

    private function ready(Workspace $workspace): string
    {
        $paused = $this->pause->for($workspace);
        if ($paused !== null) {
            throw new CatalogRefused(409, $paused['message']);
        }
        if (! is_string($workspace->telenow_key) || $workspace->telenow_key === '') {
            throw new CatalogRefused(409, __('agents.account_not_ready'));
        }

        return $workspace->telenow_key;
    }

    /** @param  array<int, string>  $said  Telenow's statuses that mean something of their own here, and the kit's words for each */
    private function clip(\Closure $send, array $said = []): array
    {
        try {
            return $send();
        } catch (TelenowError $e) {
            throw match (true) {
                isset($said[$e->status]) => new CatalogRefused(409, $said[$e->status]),
                $e->status === 429 => new CatalogRefused(429, __('voices.preview_busy')),
                $e->status >= 400 && $e->status < 500 => new CatalogRefused(422, __('voices.preview_refused')),
                default => new CatalogRefused(503, __('voices.preview_unavailable')),
            };
        }
    }
}
