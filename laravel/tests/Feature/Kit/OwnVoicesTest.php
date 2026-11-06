<?php

namespace Tests\Feature\Kit;

use App\Kit\Catalog\Catalog;
use App\Kit\Settings\Settings;
use App\Kit\Voices\Cloning;
use App\Models\Agent;
use App\Models\AuditEvent;
use App\Models\OwnVoice;
use App\Models\OwnVoiceClone;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * An account's own voices (App\Kit\Voices\OwnVoices), through the kit's real routes, against Telenow's recorded answers (contract_k12voices.py, the 11-03 release with a stand-in for its
 * own voice engine): off until the operator allows cloning; a manager names the person and confirms their release; the recording goes to Telenow as it came and is not kept; a voice is
 * cloned once a provider into an offered provider that can; agents speak with a ready one by its profile, never the provider's id; one an agent speaks with is neither removed nor deleted.
 */
class OwnVoicesTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const OPERATOR = 'vai_test_operator_admin';

    private const ORG = '3e1cd17a-d25e-40c4-b0b0-0694f052f6da';

    /** The agent whose stack the catalog recording changed. */
    private const BILLING_DESK = '5b0c2a9e-4d1f-4c3a-8e72-9f6d1b0a4c35';

    private User $staff;

    private User $ana;

    private User $ben;

    private Workspace $acme;

    /** What a voice operation answers instead of its recording, by name (create, sample, clone, remove, delete, preflight:<provider>): a recording's name, or a closure. */
    private array $answers = [];

    /** Profiles made so far: the first keeps the recording's id. */
    private int $made = 0;

    /** Each recording's upload as Telenow got it, read while it was sent (the kit closes the file after). */
    private array $uploads = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.api_key', self::OPERATOR, secret: true);
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        $this->acme = $this->account('Acme Dental', self::ORG, self::KEY, $this->ana, $this->ben);
        RecordedTelenow::fake();
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->actingAs($this->staff)->putJson('/api/kit/admin/catalog', [
            'kinds' => ['llm' => ['openai' => ['offered' => true]], 'stt' => ['deepgram' => ['offered' => true]],
                'tts' => ['telenow' => ['offered' => true, 'name' => 'Studio voices'], 'elevenlabs' => ['offered' => true]]],
            'defaults' => ['llmProvider' => 'openai', 'llmModel' => 'gpt-4o-mini', 'sttProvider' => 'deepgram', 'ttsProvider' => 'telenow', 'ttsVoice' => 'vikram-en-in'],
        ])->assertOk();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $recorded = RecordedTelenow::responder();
        Http::fake(fn (Request $r) => $this->voices($r) ?? $recorded($r));
    }

    private function account(string $name, string $org, string $key, User $owner, ?User $member = null): Workspace
    {
        $w = Workspace::create(['name' => $name, 'status' => 'active', 'sync_state' => 'synced']);
        $w->forceFill(['telenow_sub_account_id' => $org, 'telenow_key' => $key])->save();
        WorkspaceMember::create(['workspace_id' => $w->id, 'user_id' => $owner->id, 'role' => 'owner']);
        if ($member !== null) {
            WorkspaceMember::create(['workspace_id' => $w->id, 'user_id' => $member->id, 'role' => 'member']);
        }

        return $w;
    }

    /** Telenow's voice operations, answered from their recordings (or `answers`); null for anything else. */
    private function voices(Request $r): mixed
    {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        if (preg_match('#^/api/orgs/[^/]+/voices(/clone-preflight|/[^/]+/sample|/[^/]+/clone|/[^/]+/clone/[^/]+/remove|/[^/]+)?$#', $path, $m) !== 1) {
            return null;
        }
        $tail = $m[1] ?? '';
        $which = match (true) {
            $tail === '/clone-preflight' => 'preflight:'.json_decode($r->body(), true)['provider'],
            $tail === '' => 'create',
            str_ends_with($tail, '/sample') => 'sample',
            str_ends_with($tail, '/remove') => 'remove',
            str_ends_with($tail, '/clone') => 'clone',
            $r->method() === 'DELETE' => 'delete',
            default => 'other',
        };
        if ($which === 'sample') {
            $this->uploads[] = $r->body();
        }
        $answer = $this->answers[$which] ?? [
            'preflight:telenow' => 'checkVoiceCloning.telenow', 'preflight:elevenlabs' => 'checkVoiceCloning.no-key', 'create' => 'createVoiceProfile.employee',
            'sample' => 'uploadVoiceSample.wav', 'clone' => 'cloneVoice.telenow', 'remove' => 'removeVoiceClone.telenow', 'delete' => 'deleteVoiceProfile.telenow',
        ][$which] ?? 'checkVoiceCloning.unsupported';

        if ($which === 'create' && $answer === 'createVoiceProfile.employee' && $this->made++ > 0) {
            $body = RecordedTelenow::body($answer);
            $body['data']['id'] = (string) \Illuminate\Support\Str::uuid(); // Telenow gives each profile its own id; the recording has one

            return Http::response($body, 201);
        }

        return $answer instanceof \Closure ? $answer($r) : RecordedTelenow::response($answer);
    }

    /** @return list<Request> */
    private function sent(string $method, string $pattern): array
    {
        return Http::recorded(fn (Request $r) => $r->method() === $method && preg_match($pattern, (string) parse_url($r->url(), PHP_URL_PATH)) === 1)->map(fn (array $p) => $p[0])->values()->all();
    }

    private function allow(int $perAccount = 10): void
    {
        app(Cloning::class)->allow(true);
        app(Cloning::class)->setPerAccount($perAccount);
    }

    private function person(array $over = []): array
    {
        return $over + ['name' => 'Front desk Asha', 'personName' => 'Asha Rao', 'relation' => 'employee', 'confirmed' => true, 'language' => 'en',
            'script' => 'Hello, thank you for calling Acme Dental.', 'source' => 'record'];
    }

    private function make(array $over = [], ?User $who = null): TestResponse
    {
        return $this->actingAs($who ?? $this->ana)->postJson('/api/kit/console/own-voices', $this->person($over));
    }

    private function wav(float $seconds = 4.0): string
    {
        $rate = 8000;
        $data = str_repeat(pack('v', 1000), (int) ($rate * $seconds));

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', strlen($data)).$data;
    }

    /** A file as a browser sends it: its name and the type it says. */
    private function temp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kit-sample');
        file_put_contents($path, $bytes);

        return $path;
    }

    private function record(OwnVoice $voice, ?string $bytes = null, string $name = 'take.webm', array $more = [], string $type = 'audio/webm'): TestResponse
    {
        return $this->actingAs($this->ana)->post("/api/kit/console/own-voices/{$voice->id}/sample",
            ['file' => new UploadedFile($this->temp($bytes ?? $this->wav()), $name, $type, null, true), 'seconds' => '4.2'] + $more, ['Accept' => 'application/json']);
    }

    private function ready(): OwnVoice
    {
        $this->allow();
        $this->make()->assertCreated();
        $voice = OwnVoice::query()->sole();
        $this->record($voice)->assertOk();
        $this->actingAs($this->ana)->postJson("/api/kit/console/own-voices/{$voice->id}/clones", ['provider' => Catalog::publicKey('tts', 'telenow')])->assertOk();

        return $voice->fresh();
    }

    private function billingDesk(): Agent
    {
        $agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Billing desk']);
        $agent->forceFill(['telenow_id' => self::BILLING_DESK, 'sync_state' => 'synced', 'llm_provider' => 'openai', 'llm_model' => 'gpt-4o-mini',
            'stt_provider' => 'deepgram', 'tts_provider' => 'telenow', 'tts_voice' => 'vikram-en-in'])->save();

        return $agent;
    }

    public function test_cloning_is_off_until_the_operator_allows_it_and_each_change_is_in_the_audit_log(): void
    {
        $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->assertOk()
            ->assertJsonPath('cloningAllowed', false)->assertJsonPath('clonesPerAccount', config('kit.own_voices.clones_per_account'))->assertJsonPath('clonesMost', 50);
        $this->actingAs($this->ana)->getJson('/api/kit/console/own-voices')->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('manages', true)->assertJsonPath('targets', []);
        $this->make()->assertStatus(409)->assertExactJson(['message' => __('own_voices.not_allowed')]);
        $this->assertSame([], $this->sent('POST', '#/voices#'), 'nothing asked of Telenow, not even which providers can clone');

        $save = fn (array $more) => $this->actingAs($this->staff)->putJson('/api/kit/admin/catalog', ['kinds' => [], 'defaults' => []] + $more);
        $save(['cloningAllowed' => true, 'clonesPerAccount' => 3])->assertOk()->assertJsonPath('cloningAllowed', true)->assertJsonPath('clonesPerAccount', 3);
        $save(['cloningAllowed' => true, 'clonesPerAccount' => 3])->assertOk();
        $save(['clonesPerAccount' => 51])->assertStatus(422)->assertJsonValidationErrors('clonesPerAccount');
        $save(['clonesPerAccount' => 0])->assertStatus(422)->assertJsonValidationErrors('clonesPerAccount');
        $save(['cloningAllowed' => false])->assertOk()->assertJsonPath('cloningAllowed', false);
        $this->assertSame(['own_voices.allowed', 'own_voices.limit_set', 'own_voices.stopped'], AuditEvent::query()->where('action', 'like', 'own_voices.%')->orderBy('id')->pluck('action')->all(),
            'once each, as it changed');
        $this->assertNotSame('audit.actions.own_voices.allowed', __('audit.actions.own_voices.allowed'));
    }

    public function test_a_manager_names_the_person_and_confirms_their_release_and_telenow_is_told_whose_voice_it_is(): void
    {
        $this->allow();
        $page = $this->actingAs($this->ana)->getJson('/api/kit/console/own-voices')->assertOk();
        $page->assertJsonPath('targets', [['key' => Catalog::publicKey('tts', 'telenow'), 'name' => 'Studio voices']])
            ->assertJsonPath('relations', ['employee', 'voice_actor', 'customer', 'other'])->assertJsonPath('clones', ['used' => 0, 'limit' => 10]);
        $this->assertSame(['telenow', 'elevenlabs'], array_map(fn (Request $r) => json_decode($r->body(), true)['provider'], $this->sent('POST', '#/voices/clone-preflight$#')),
            'each offered voice provider asked once, with the account\'s key');
        $this->actingAs($this->ana)->getJson('/api/kit/console/own-voices')->assertOk();
        $this->assertCount(2, $this->sent('POST', '#/voices/clone-preflight$#'), 'then read from the kit\'s copy for a while');

        $this->make(['confirmed' => false])->assertStatus(422)->assertJsonPath('errors.confirmed', [__('own_voices.confirm_release')]);
        $this->make(['relation' => 'self'])->assertStatus(422)->assertJsonValidationErrors('relation');
        $this->make(['personName' => ''])->assertStatus(422)->assertJsonValidationErrors('personName');
        $this->make([], $this->ben)->assertStatus(403)->assertExactJson(['message' => __('own_voices.managers_only')]);
        $this->assertSame([], $this->sent('POST', '#/voices$#'), 'nothing made at Telenow');

        $made = $this->make()->assertCreated();
        [$create] = $this->sent('POST', '#^/api/orgs/'.self::ORG.'/voices$#');
        $this->assertSame([self::KEY], $create->header('X-API-Key'));
        $this->assertSame(['name' => 'Front desk Asha', 'consent' => true, 'subject_relation' => 'employee', 'subject_name' => 'Asha Rao', 'subject_confirmed' => true, 'source_language' => 'en',
            'sample_script' => 'Hello, thank you for calling Acme Dental.', 'source_kind' => 'record', 'consent_text_version' => 'kit-voice-release-1'], json_decode($create->body(), true),
            'the person named and their release confirmed; no retention unless the operator sets one');
        $voice = OwnVoice::query()->sole();
        $this->assertSame([RecordedTelenow::body('createVoiceProfile.employee')['data']['id'], $this->ana->id, 'kit-voice-release-1'], [$voice->telenow_id, (int) $voice->confirmed_by, $voice->release_version]);
        $made->assertJsonPath('voice', 'own:'.$voice->id)->assertJsonPath('personName', 'Asha Rao')->assertJsonPath('confirmedBy', 'Ana')->assertJsonPath('hasSample', false);
        $this->assertStringNotContainsString($voice->telenow_id, $made->getContent(), "Telenow's id never leaves the kit");
        $audit = AuditEvent::query()->where('action', 'own_voices.created')->sole();
        $this->assertSame([$this->ana->id, $voice->id], [(int) $audit->user_id, $audit->subject_id]);

        config(['kit.own_voices.retention_days' => 30]);
        $this->make(['name' => 'Kept a month'])->assertCreated();
        $this->assertSame(30, json_decode($this->sent('POST', '#/voices$#')[1]->body(), true)['retention_days']);

        $this->answers['create'] = 'createVoiceProfile.self';
        $this->make(['name' => 'Refused'])->assertStatus(409)->assertExactJson(['message' => __('own_voices.refused')]);
        $this->answers['create'] = fn () => Http::response('', 502);
        $this->make(['name' => 'Unreachable'])->assertStatus(503)->assertExactJson(['message' => __('own_voices.unavailable')]);
        $this->answers['create'] = fn () => Http::response(['success' => true, 'data' => []], 201);
        $this->make(['name' => 'No id'])->assertStatus(503)->assertExactJson(['message' => __('own_voices.unavailable')]);
        $this->assertSame(2, OwnVoice::query()->count(), 'nothing kept for a refusal');

        $this->allow(2);
        $this->make(['name' => 'One too many'])->assertStatus(409)->assertExactJson(['message' => __('own_voices.voices_full', ['limit' => 2])]);
        $this->acme->forceFill(['status' => Workspace::SUSPENDED])->save();
        $this->allow(5);
        $sentBefore = count($this->sent('POST', '#/voices$#'));
        $this->make(['name' => 'Paused'])->assertStatus(409)->assertExactJson(['message' => __('console.paused.account_suspended')]);
        $this->assertCount($sentBefore, $this->sent('POST', '#/voices$#'), 'Telenow makes one while suspended (recorded); the kit does not ask');
    }

    public function test_the_recording_goes_to_telenow_as_it_came_and_the_kit_keeps_no_copy(): void
    {
        $this->allow();
        $this->make()->assertCreated();
        $voice = OwnVoice::query()->sole();
        $bytes = $this->wav();
        $before = $this->files();
        $answer = $this->record($voice, $bytes)->assertOk()->assertJsonPath('hasSample', true)->assertJsonPath('sampleSeconds', 4);
        [$sent] = $this->sent('POST', '#/voices/'.$voice->telenow_id.'/sample$#');
        $parts = collect($sent->data())->keyBy('name');
        $this->assertSame('sample.webm', $parts['file']['filename'], 'a name that says nothing of the person');
        $this->assertStringContainsString("Content-Type: audio/webm\r\nContent-Disposition: form-data; name=\"file\"; filename=\"sample.webm\"\r\n\r\n".$bytes."\r\n", $this->uploads[0],
            'the bytes as they came, with the type the browser gave');
        $this->assertSame(['4.2', 'record', 'Hello, thank you for calling Acme Dental.'], [$parts['seconds']['contents'], $parts['kind']['contents'], $parts['script']['contents']]);
        $this->assertSame($before, $this->files(), 'no copy of the recording anywhere in the kit');
        $this->assertNotNull($voice->fresh()->sample_added_at);
        $answer->assertJsonMissingPath('sampleType');

        $this->answers['sample'] = function () {
            $body = RecordedTelenow::body('uploadVoiceSample.webm');
            $body['data']['sample_seconds'] = 12.199999809265137; // Telenow keeps the length as a 32-bit float (a browser's 12.2 s came back so)

            return Http::response($body);
        };
        $this->record($voice)->assertOk()->assertJsonPath('sampleSeconds', 12.2);
        $this->assertSame(12.2, $voice->fresh()->sample_seconds, 'kept to the hundredth');
        $this->answers['sample'] = 'uploadVoiceSample.short';
        $this->record($voice)->assertStatus(422)->assertJsonPath('errors.file', [__('own_voices.sample_unusable', ['seconds' => 3])]);
        $this->answers['sample'] = 'uploadVoiceSample.unreadable';
        $this->record($voice)->assertStatus(422)->assertJsonPath('errors.file', [__('own_voices.sample_unusable', ['seconds' => 3])]);
        $this->answers['sample'] = 'uploadVoiceSample.too-big';
        $this->record($voice)->assertStatus(422)->assertJsonValidationErrors('file');
        $count = count($this->sent('POST', '#/sample$#'));
        $this->record($voice, 'not audio', 'notes.txt')->assertStatus(422)->assertJsonValidationErrors('file');
        $this->actingAs($this->ana)->post("/api/kit/console/own-voices/{$voice->id}/sample", ['file' => UploadedFile::fake()->create('long.wav', intdiv(\App\Kit\Voices\OwnVoices::sampleMaxBytes(), 1024) + 1)],
            ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->actingAs($this->ben)->post("/api/kit/console/own-voices/{$voice->id}/sample", ['file' => UploadedFile::fake()->createWithContent('take.wav', $bytes)], ['Accept' => 'application/json'])
            ->assertStatus(403);
        $this->assertCount($count, $this->sent('POST', '#/sample$#'), 'the kit refused those itself');
    }

    /** @return list<string> every file the kit's storage holds */
    private function files(): array
    {
        $root = storage_path('app');
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[] = substr($f->getPathname(), strlen($root));
        }
        sort($out);

        return $out;
    }

    public function test_a_voice_is_cloned_once_a_provider_into_an_offered_provider_that_can(): void
    {
        $this->allow();
        $this->make()->assertCreated();
        $voice = OwnVoice::query()->sole();
        $clone = fn (string $provider) => $this->actingAs($this->ana)->postJson("/api/kit/console/own-voices/{$voice->id}/clones", ['provider' => Catalog::publicKey('tts', $provider)]);
        $clone('telenow')->assertStatus(409)->assertExactJson(['message' => __('own_voices.needs_sample')]);
        $this->record($voice)->assertOk();
        $clone('elevenlabs')->assertStatus(422)->assertJsonPath('errors.provider', [__('own_voices.cannot_clone_there')]);
        $clone('openai')->assertStatus(422)->assertJsonPath('errors.provider', [__('own_voices.cannot_clone_there')]);
        $this->assertSame([], $this->sent('POST', '#/clone$#'), 'only a provider that can, as Telenow said for the account');

        $cloned = $clone('telenow')->assertOk();
        [$sent] = $this->sent('POST', '#/voices/'.$voice->telenow_id.'/clone$#');
        $this->assertSame(['provider' => 'telenow'], json_decode($sent->body(), true));
        $cloned->assertJsonPath('clones', [['provider' => Catalog::publicKey('tts', 'telenow'), 'providerName' => 'Studio voices', 'status' => 'ready']]);
        $this->assertStringNotContainsString('kitclone', $cloned->getContent(), "the provider's id for the voice is never kept or shown");
        $this->assertSame([RecordedTelenow::body('cloneVoice.telenow')['data']['id'], 'ready', $this->ana->id],
            [OwnVoiceClone::query()->sole()->telenow_id, OwnVoiceClone::query()->sole()->status, (int) OwnVoiceClone::query()->sole()->cloned_by]);

        $clone('telenow')->assertStatus(409)->assertExactJson(['message' => __('own_voices.already_cloned', ['provider' => 'Studio voices'])]);
        $this->assertCount(1, $this->sent('POST', '#/clone$#'), 'Telenow would make a second and leave the first to anyone; the kit does not ask');

        $this->allow(1);
        $this->make(['name' => 'Second'])->assertStatus(409);
        $this->allow(2);
        $this->make(['name' => 'Second'])->assertCreated();
        $second = OwnVoice::query()->where('name', 'Second')->sole();
        $this->record($second)->assertOk();
        $this->allow(1);
        $this->actingAs($this->ana)->postJson("/api/kit/console/own-voices/{$second->id}/clones", ['provider' => Catalog::publicKey('tts', 'telenow')])
            ->assertStatus(409)->assertExactJson(['message' => __('own_voices.clones_full', ['limit' => 1])]);
        $this->allow();
        $this->answers['clone'] = 'cloneVoice.cap';
        $this->actingAs($this->ana)->postJson("/api/kit/console/own-voices/{$second->id}/clones", ['provider' => Catalog::publicKey('tts', 'telenow')])
            ->assertStatus(409)->assertExactJson(['message' => __('own_voices.refused')]);
        $this->answers['clone'] = fn () => Http::response(['success' => false, 'error' => 'vendor down'], 502);
        $this->actingAs($this->ana)->postJson("/api/kit/console/own-voices/{$second->id}/clones", ['provider' => Catalog::publicKey('tts', 'telenow')])->assertStatus(503);
        $this->assertSame(1, OwnVoiceClone::query()->count(), 'nothing kept for a refusal');
    }

    public function test_an_agent_speaks_with_a_ready_own_voice_by_its_profile_and_customers_never_see_telenows_ids(): void
    {
        $voice = $this->ready();
        $agent = $this->billingDesk();
        $this->answers['put'] = null;
        $put = [];
        $recorded = RecordedTelenow::responder();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use (&$put, $recorded) {
            if ($r->method() === 'PUT' && parse_url($r->url(), PHP_URL_PATH) === '/api/agents/'.self::BILLING_DESK) {
                $put[] = json_decode($r->body(), true);
                $body = RecordedTelenow::body('updateAgent.stack');
                $body['data'] = array_replace($body['data'], ['tts_provider' => end($put)['ttsProvider'] ?? $body['data']['tts_provider'], 'tts_voice' => end($put)['ttsVoice'] ?? $body['data']['tts_voice']]);

                return Http::response($body);
            }
            if ($r->method() === 'POST' && parse_url($r->url(), PHP_URL_PATH) === '/api/providers/tts/telenow/preview') {
                return Http::response('RIFF clip', 200, ['Content-Type' => 'audio/wav']);
            }

            return $this->voices($r) ?? $recorded($r);
        });
        $stack = fn (string $voice) => ['llmProvider' => Catalog::publicKey('llm', 'openai'), 'llmModel' => 'gpt-4o-mini', 'sttProvider' => Catalog::publicKey('stt', 'deepgram'),
            'ttsProvider' => Catalog::publicKey('tts', 'telenow'), 'ttsVoice' => $voice];

        $saved = $this->actingAs($this->ben)->patchJson("/api/kit/console/agents/{$agent->id}", $stack('own:'.$voice->id))->assertOk();
        $this->assertSame('profile:'.$voice->telenow_id, $put[0]['ttsVoice'], 'Telenow is sent its own name for the voice');
        $this->assertSame('profile:'.$voice->telenow_id, $agent->fresh()->tts_voice);
        $saved->assertJsonPath('stack.ttsVoice', 'own:'.$voice->id)->assertJsonPath('stackNames.ttsVoice', 'Front desk Asha');
        $this->assertStringNotContainsString($voice->telenow_id, $saved->getContent());
        $this->actingAs($this->ben)->getJson("/api/kit/console/agents/{$agent->id}")->assertOk()->assertJsonPath('stack.ttsVoice', 'own:'.$voice->id);
        $this->actingAs($this->ben)->getJson('/api/kit/console/own-voices')->assertOk()->assertJsonPath('voices.0.usedBy', [['id' => $agent->id, 'name' => 'Billing desk']]);

        $this->actingAs($this->ben)->postJson('/api/kit/console/voices/preview', ['provider' => Catalog::publicKey('tts', 'telenow'), 'voice' => 'own:'.$voice->id])->assertOk();
        [$asked] = $this->sent('POST', '#^/api/providers/tts/telenow/preview$#');
        $this->assertSame(['text' => __('voices.preview_text'), 'voice' => 'profile:'.$voice->telenow_id], json_decode($asked->body(), true));

        $wrong = [
            'not cloned on that provider' => array_replace($stack('own:'.$voice->id), ['ttsProvider' => Catalog::publicKey('tts', 'elevenlabs')]),
            'unknown' => $stack('own:00000000-0000-4000-8000-000000000000'),
            "Telenow's name for it" => $stack('profile:'.$voice->telenow_id),
        ];
        $bright = $this->account('Bright Smiles', '9f6f1d2a-0000-4000-8000-0000000000b2', 'vai_test_customer_bright', $other = User::create(['name' => 'Cy', 'email' => 'cy@example.com', 'password' => 'a long password 9']));
        $theirs = Agent::create(['workspace_id' => $bright->id, 'name' => 'Theirs']);
        foreach ($wrong as $what => $body) {
            $this->actingAs($this->ben)->patchJson("/api/kit/console/agents/{$agent->id}", $body)->assertStatus(422)->assertJsonPath('errors.ttsVoice', [__('voices.unknown_voice')]);
        }
        $this->actingAs($other)->patchJson("/api/kit/console/agents/{$theirs->id}", $stack('own:'.$voice->id))->assertStatus(422)->assertJsonPath('errors.ttsVoice', [__('voices.unknown_voice')]);
        $this->actingAs($other)->postJson('/api/kit/console/voices/preview', ['provider' => Catalog::publicKey('tts', 'telenow'), 'voice' => 'own:'.$voice->id])->assertStatus(422);
        $this->assertCount(1, $put, 'only the first reached Telenow');
        $this->assertCount(1, $this->sent('POST', '#^/api/providers/tts/telenow/preview$#'), "another account cannot hear it");
    }

    public function test_a_voice_an_agent_speaks_with_is_neither_removed_nor_deleted_and_deleting_keeps_who_confirmed_the_release(): void
    {
        $voice = $this->ready();
        $agent = $this->billingDesk();
        $agent->forceFill(['tts_voice' => 'profile:'.$voice->telenow_id])->save();
        $remove = fn (?User $who = null) => $this->actingAs($who ?? $this->ana)->deleteJson("/api/kit/console/own-voices/{$voice->id}/clones/".Catalog::publicKey('tts', 'telenow'));
        $delete = fn (?User $who = null) => $this->actingAs($who ?? $this->ana)->deleteJson("/api/kit/console/own-voices/{$voice->id}");

        $remove()->assertStatus(409)->assertExactJson(['message' => __('own_voices.in_use', ['agents' => 'Billing desk'])]);
        $delete()->assertStatus(409)->assertExactJson(['message' => __('own_voices.in_use', ['agents' => 'Billing desk'])]);
        $this->assertSame([], $this->sent('POST', '#/remove$#'));
        $this->assertSame([], $this->sent('DELETE', '#/voices/#'), 'Telenow would quietly speak the default voice instead');
        $remove($this->ben)->assertStatus(403);
        $delete($this->ben)->assertStatus(403);

        $agent->forceFill(['tts_voice' => 'vikram-en-in'])->save();
        app(Cloning::class)->allow(false);
        $this->acme->forceFill(['status' => Workspace::SUSPENDED])->save();
        $remove()->assertOk()->assertJsonPath('clones', []);
        [$removed] = $this->sent('POST', '#/voices/'.$voice->telenow_id.'/clone/telenow/remove$#');
        $this->assertSame('{}', $removed->body(), 'Telenow wants a JSON body (no body is a 415)');
        $this->assertSame(0, OwnVoiceClone::query()->count());
        $remove()->assertStatus(404);

        $this->answers['delete'] = 'deleteVoiceProfile.gone';
        $delete()->assertNoContent();
        $kept = OwnVoice::query()->sole();
        $this->assertNotNull($kept->deleted_at);
        $this->assertSame([$this->ana->id, $this->ana->id, 'Asha Rao', 'kit-voice-release-1'], [(int) $kept->deleted_by, (int) $kept->confirmed_by, $kept->person_name, $kept->release_version],
            'the record of who confirmed the release, and when, stays');
        $this->actingAs($this->ana)->getJson('/api/kit/console/own-voices')->assertOk()->assertJsonPath('voices', []);
        $delete()->assertNotFound();
        $this->assertSame(['own_voices.created', 'own_voices.sample_added', 'own_voices.cloned', 'own_voices.clone_removed', 'own_voices.deleted'],
            AuditEvent::query()->where('action', 'like', 'own_voices.%')->where('action', '!=', 'own_voices.allowed')->where('action', '!=', 'own_voices.limit_set')->orderBy('id')->pluck('action')->all());
    }

    public function test_what_telenow_says_and_the_kits_own_limits_hold_one_voice_at_a_time_and_so_many_changes_a_minute(): void
    {
        $this->allow();
        $this->answers['preflight:elevenlabs'] = 'checkVoiceCloning.unsupported';
        $this->actingAs($this->ana)->getJson('/api/kit/console/own-voices')->assertOk()
            ->assertJsonPath('targets', [['key' => Catalog::publicKey('tts', 'telenow'), 'name' => 'Studio voices']], 'a provider Telenow says cannot clone is not offered')
            ->assertJsonPath('limits.sampleMaxBytes', min(25 * 1024 * 1024, UploadedFile::getMaxFilesize()), "Telenow's 25 MB, or what PHP takes if less");

        $lock = \Illuminate\Support\Facades\Cache::lock("kit:own-voices:{$this->acme->id}", 30);
        $this->assertTrue($lock->get());
        $this->make()->assertStatus(409)->assertExactJson(['message' => __('own_voices.busy')]);
        $this->assertSame([], $this->sent('POST', '#/voices$#'), 'one at a time: nothing asked while another is being made');
        $lock->release();
        $this->make()->assertCreated();
        $voice = OwnVoice::query()->sole();
        $this->record($voice)->assertOk();

        $this->answers['clone'] = 'cloneVoice.unsupported';
        $this->actingAs($this->ana)->postJson("/api/kit/console/own-voices/{$voice->id}/clones", ['provider' => Catalog::publicKey('tts', 'telenow')])
            ->assertStatus(422)->assertJsonPath('errors.provider', [__('own_voices.cannot_clone_there')]);
        unset($this->answers['clone']);
        $this->actingAs($this->ana)->postJson("/api/kit/console/own-voices/{$voice->id}/clones", ['provider' => Catalog::publicKey('tts', 'telenow')])->assertOk();

        $agent = $this->billingDesk();
        $agent->forceFill(['tts_voice' => 'profile:'.$voice->telenow_id])->save();
        (new OwnVoiceClone)->forceFill(['own_voice_id' => $voice->id, 'provider_id' => 'elevenlabs', 'telenow_id' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'ready'])->save();
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/own-voices/{$voice->id}/clones/".Catalog::publicKey('tts', 'elevenlabs'))->assertOk();
        $this->assertSame(['telenow'], OwnVoiceClone::query()->pluck('provider_id')->all(), 'its clone elsewhere may go: the agent speaks with it on Telenow\'s own');

        OwnVoiceClone::query()->update(['status' => 'pending']);
        $this->actingAs($this->ben)->patchJson("/api/kit/console/agents/{$agent->id}", ['llmProvider' => Catalog::publicKey('llm', 'openai'), 'llmModel' => 'gpt-4o-mini',
            'sttProvider' => Catalog::publicKey('stt', 'deepgram'), 'ttsProvider' => Catalog::publicKey('tts', 'telenow'), 'ttsVoice' => 'own:'.$voice->id])
            ->assertStatus(422)->assertJsonPath('errors.ttsVoice', [__('voices.unknown_voice')]);
        $this->actingAs($this->ben)->postJson('/api/kit/console/voices/preview', ['provider' => Catalog::publicKey('tts', 'telenow'), 'voice' => 'own:'.$voice->id])->assertStatus(422);
        OwnVoiceClone::query()->update(['status' => 'ready']);

        config(['kit.own_voices.writes_per_minute' => 2]);
        $this->travel(61)->seconds();
        $this->actingAs($this->ana)->postJson('/api/kit/console/own-voices', $this->person(['name' => 'One']))->assertCreated();
        $this->actingAs($this->ana)->postJson('/api/kit/console/own-voices', $this->person(['name' => 'Two']))->assertCreated();
        $this->actingAs($this->ana)->postJson('/api/kit/console/own-voices', $this->person(['name' => 'Three']))->assertStatus(429);
        $this->actingAs($this->ben)->postJson('/api/kit/console/own-voices', $this->person(['name' => 'Ben\'s']))->assertStatus(403)->assertExactJson(['message' => __('own_voices.managers_only')]);
    }

    public function test_members_see_the_voices_but_not_whose_they_are_and_another_accounts_voice_is_not_there(): void
    {
        $voice = $this->ready();
        $member = $this->actingAs($this->ben)->getJson('/api/kit/console/own-voices')->assertOk();
        $member->assertJsonPath('manages', false)->assertJsonPath('targets', [])->assertJsonPath('voices.0.voice', 'own:'.$voice->id)
            ->assertJsonPath('voices.0.personName', null)->assertJsonPath('voices.0.relation', null)->assertJsonPath('voices.0.confirmedBy', null)->assertJsonPath('voices.0.confirmedAt', null);
        $this->assertStringNotContainsString('Asha Rao', $member->getContent());
        $this->actingAs($this->ana)->getJson('/api/kit/console/own-voices')->assertOk()->assertJsonPath('voices.0.personName', 'Asha Rao')->assertJsonPath('voices.0.relation', 'employee');

        $cy = User::create(['name' => 'Cy', 'email' => 'cy@example.com', 'password' => 'a long password 9']);
        $this->account('Bright Smiles', '9f6f1d2a-0000-4000-8000-0000000000b2', 'vai_test_customer_bright', $cy);
        $this->actingAs($cy)->getJson('/api/kit/console/own-voices')->assertOk()->assertJsonPath('voices', []);
        foreach (['deleteJson' => "/api/kit/console/own-voices/{$voice->id}", 'postJson' => "/api/kit/console/own-voices/{$voice->id}/clones"] as $method => $path) {
            $this->actingAs($cy)->{$method}($path, ['provider' => Catalog::publicKey('tts', 'telenow')])->assertNotFound()->assertExactJson(['message' => __('own_voices.not_found')]);
        }
        $this->actingAs($cy)->deleteJson('/api/kit/console/own-voices/not-an-id')->assertNotFound();
        $this->assertNull($voice->fresh()->deleted_at);
    }
}
