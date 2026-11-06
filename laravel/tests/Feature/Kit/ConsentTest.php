<?php

namespace Tests\Feature\Kit;

use App\Kit\Consent\ConsentRule;
use App\Kit\Consent\Telenow\ConsentWrite;
use App\Kit\Consent\Telenow\PushConsentAtTelenow;
use App\Kit\Outbox\OutboxEntry;
use App\Models\Campaign;
use App\Models\ConsentRecord;
use App\Models\DncEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * A customer's record of who agreed to be called and whether their calls need one (App\Kit\Consent), through the kit's real routes, against a stand-in for Telenow doing what contract_k8consent.py recorded of the 11-03 release: it keeps the same record twice, stores a
 * date in the future without saying, withdraws by number and lists the number as do-not-call, and refuses `off` under an account that requires consent.
 */
class ConsentTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const ORG = '3e1cd17a-d25e-40c4-b0b0-0694f052f6da';

    private const ROOT = '/api/kit/console/consent';

    private const WORDS = 'I agree to be called about my appointments.';

    private User $ana;

    private User $ben;

    private Workspace $acme;

    /** @var list<array{method: string, path: string, body: array}> */
    private array $sent = [];

    /** Records as Telenow holds them. */
    private array $held = [];

    private string $mode = 'off';

    private bool $parentRequires = false;

    /** What requests answer instead, by method and how the path ends. */
    private array $answers = [];

    /** Answer the next record's request with a lost connection, after keeping it. */
    private int $loseAnswers = 0;

    /** Waiting calls the coverage counts. */
    private array $coverage = ['pending_total' => 0, 'pending_covered' => 0, 'pending_blocked' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => self::ORG, 'telenow_key' => self::KEY, 'balance' => '50'])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'owner']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ben->id, 'role' => 'member']);
        RecordedTelenow::fake();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => $this->telenow($r));
    }

    private function telenow(Request $r): mixed
    {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $this->sent[] = ['method' => $r->method(), 'path' => $path, 'body' => $r->data(), 'key' => $r->header('X-API-Key')[0] ?? null];
        foreach ($this->answers as $which => [$status, $body]) {
            [$method, $end] = explode(' ', $which);
            if ($r->method() === $method && str_ends_with($path, $end)) {
                return Http::response($body, $status);
            }
        }
        $at = '/api/orgs/'.(explode('/', $path)[3] ?? '').'/consent';
        if ($r->method() === 'POST' && $path === $at) {
            $row = $r->data() + ['id' => (string) Str::uuid(), 'revoked_at' => null];
            $row['evidence']['server'] = ['recordedVia' => 'api', 'peer' => '198.51.100.7', 'apiKeyId' => 'operator-side'];
            $this->held[] = $row;
            if ($this->loseAnswers-- > 0) {
                return Http::failedConnection();
            }

            return Http::response(['success' => true, 'data' => $row], 201);
        }
        if ($r->method() === 'GET' && $path === "{$at}/coverage") {
            return Http::response(['success' => true, 'data' => $this->coverage + ['mode' => $this->mode]]);
        }
        if ($r->method() === 'PUT' && $path === "{$at}/enforcement") {
            if ($r->data()['mode'] === 'off' && $this->parentRequires) {
                return Http::response(RecordedTelenow::body('setConsentEnforcement.held'), 403);
            }
            $this->mode = $r->data()['mode'];

            return Http::response(['success' => true, 'data' => ['mode' => $this->mode]]);
        }
        if ($r->method() === 'POST' && str_ends_with($path, '/revoke')) {
            $phone = rawurldecode(explode('/', $path)[5]);
            $revoked = 0;
            foreach ($this->held as $i => $row) {
                if ($row['phone_number'] === $phone && $row['revoked_at'] === null) {
                    $this->held[$i]['revoked_at'] = now()->toIso8601String();
                    $revoked++;
                }
            }

            return Http::response(['success' => true, 'data' => ['revoked' => $revoked]]);
        }
        if ($r->method() === 'GET' && str_starts_with($path, "{$at}/")) {
            $phone = rawurldecode(explode('/', $path)[5]);

            return Http::response(['success' => true, 'data' => ['records' => array_values(array_filter($this->held, fn (array $row) => $row['phone_number'] === $phone)), 'total' => 0]]);
        }
        if ($r->method() === 'GET' && str_ends_with($path, '/dnc')) {
            $revoked = array_values(array_filter($this->held, fn (array $row) => $row['revoked_at'] !== null));

            return Http::response(['success' => true, 'data' => ['entries' => array_map(fn (array $row) => ['id' => md5($row['phone_number']), 'phone_number' => $row['phone_number'], 'reason' => 'consent revoked', 'source' => 'opt_out', 'updated_at' => now()->toIso8601String()], $revoked), 'nextCursor' => null]]);
        }

        return Http::response(['success' => false, 'error' => "no stand-in for {$r->method()} {$path}"], 500);
    }

    private function good(array $over = []): array
    {
        return $over + ['phoneNumber' => '+14155550101', 'consentType' => 'implied', 'source' => 'paper'];
    }

    private function record(array $over = []): TestResponse
    {
        return $this->actingAs($this->ana)->postJson(self::ROOT, $this->good($over));
    }

    private function import(string $csv, array $over = []): TestResponse
    {
        return $this->actingAs($this->ana)->postJson(self::ROOT.'/import', $over + ['csv' => $csv, 'consentType' => 'implied', 'capturedAt' => '2026-10-01T09:00:00Z']);
    }

    private function withdraw(string $phone, array $over = []): TestResponse
    {
        return $this->actingAs($this->ana)->postJson(self::ROOT.'/withdraw', $over + ['phoneNumber' => $phone]);
    }

    private function rule(bool $required, ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->ana)->putJson(self::ROOT.'/rule', ['required' => $required]);
    }

    private function list(string $query = ''): TestResponse
    {
        return $this->actingAs($this->ana)->getJson(self::ROOT.$query)->assertOk();
    }

    private function called(string $method, string $end): array
    {
        return array_values(array_filter($this->sent, fn (array $s) => $s['method'] === $method && str_ends_with($s['path'], $end)));
    }

    private function runOutbox(): void
    {
        OutboxEntry::query()->whereNull('done_at')->update(['next_attempt_at' => now()->subMinute()]);
        Artisan::call('kit:outbox');
    }

    // ── Recording ───────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_record_goes_to_telenow_with_its_words_dates_and_the_kits_own_id_and_who_recorded_it(): void
    {
        $answer = $this->actingAs($this->ana)->withHeaders(['User-Agent' => 'Ana Browser'])->postJson(self::ROOT, $this->good([
            'phoneNumber' => '+1 (415) 555-0101', 'consentType' => 'express_written', 'scope' => 'service', 'source' => 'web_form', 'disclosureText' => self::WORDS, 'disclosureVersion' => 'v1', 'capturedAt' => '2026-10-01T09:00:00Z', 'expiresAt' => '2027-10-01T09:00:00Z', 'note' => 'Form 12',
        ]))->assertCreated();
        $answer->assertJsonPath('phoneNumber', '+14155550101')->assertJsonPath('state', 'live')->assertJsonPath('syncState', 'synced')->assertJsonPath('recordedBy', 'Ana')->assertJsonPath('disclosureText', self::WORDS)->assertJsonPath('note', 'Form 12');
        [$sent] = $this->called('POST', '/consent');
        $this->assertSame(self::KEY, $sent['key']);
        $row = ConsentRecord::query()->sole();
        $b = $sent['body'];
        $this->assertSame(['+14155550101', 'express_written', 'service', 'web_form', self::WORDS, 'v1', '2026-10-01T09:00:00Z', '2027-10-01T09:00:00Z'], [$b['phone_number'], $b['consent_type'], $b['scope'], $b['source'], $b['disclosure_text'], $b['disclosure_version'], $b['captured_at'], $b['expires_at']]);
        $this->assertSame($row->id, $b['evidence']['kitId']);
        $this->assertSame(['Form 12', $this->ana->id, 'Ana Browser'], [$b['evidence']['note'], $b['evidence']['recordedFrom']['user'], $b['evidence']['recordedFrom']['userAgent']]);
        $this->assertArrayHasKey('ip', $b['evidence']['recordedFrom']);
        $this->assertNotNull($row->telenow_id);
        $this->assertSame([], $this->called('GET', '/consent/%2B14155550101'), 'it looks first only after a try with no answer');
    }

    public function test_what_telenow_keeps_of_who_sent_it_and_its_own_ids_never_reach_a_customer(): void
    {
        $content = $this->record()->assertCreated()->getContent().$this->list()->getContent();
        $held = ConsentRecord::query()->sole()->telenow_id;
        foreach (['198.51.100.7', 'operator-side', 'recordedVia', 'evidence', 'telenow', $held] as $word) {
            $this->assertStringNotContainsString($word, $content);
        }
    }

    public function test_what_is_left_out_goes_as_the_defaults(): void
    {
        $this->record()->assertCreated()->assertJsonPath('scope', config('kit.consent.default_scope'));
        $b = $this->called('POST', '/consent')[0]['body'];
        $this->assertArrayNotHasKey('expires_at', $b);
        $this->assertArrayNotHasKey('disclosure_text', $b);
        $this->assertEqualsWithDelta(now()->timestamp, strtotime($b['captured_at']), 5);
    }

    public function test_what_telenow_stores_without_saying_or_refuses_in_its_own_words_is_refused_here(): void
    {
        foreach ([
            'written with no words' => [['consentType' => 'express_written'], 'disclosureText'],
            'written with blank words' => [['consentType' => 'express_written', 'disclosureText' => '   '], 'disclosureText'],
            'agreed in the future' => [['capturedAt' => now()->addDay()->toIso8601String()], 'capturedAt'],
            'ends before it began' => [['capturedAt' => '2026-10-01T00:00:00Z', 'expiresAt' => '2026-09-01T00:00:00Z'], 'expiresAt'],
            'ended before now' => [['expiresAt' => '2020-01-01T00:00:00Z'], 'expiresAt'],
            'an unknown kind' => [['consentType' => 'maybe'], 'consentType'],
            'an unknown scope' => [['scope' => 'maybe'], 'scope'],
            'an unknown way' => [['source' => 'maybe'], 'source'],
            'the file word' => [['source' => 'import'], 'source'],
            'too long a note' => [['note' => str_repeat('x', config('kit.consent.note_max_length') + 1)], 'note'],
            'too long words' => [['consentType' => 'express_written', 'disclosureText' => str_repeat('x', config('kit.consent.text_max_length') + 1)], 'disclosureText'],
            'too long a version' => [['disclosureVersion' => str_repeat('x', config('kit.consent.version_max_length') + 1)], 'disclosureVersion'],
            'no number' => [['phoneNumber' => ''], 'phoneNumber'],
        ] as $what => [$over, $field]) {
            $this->record($over)->assertUnprocessable()->assertJsonValidationErrors([$field], 'errors', $what);
        }
        $this->assertSame([], $this->called('POST', '/consent'));
        $this->assertSame(0, ConsentRecord::query()->count());
    }

    public function test_written_consent_says_what_is_missing_in_the_kits_words(): void
    {
        $this->record(['consentType' => 'express_written'])->assertUnprocessable()->assertJsonPath('errors.disclosureText.0', __('consent.written_needs_words'));
    }

    public function test_a_number_needs_its_plus_and_country_code_since_telenow_matches_it_exactly(): void
    {
        foreach (['14155550101', 'call me', '+123', '+1234567890123456', '415-555-0101'] as $number) {
            $this->record(['phoneNumber' => $number])->assertUnprocessable()->assertJsonPath('errors.phoneNumber.0', __('consent.number_needs_plus'));
        }
        $this->assertSame('14155550110', RecordedTelenow::body('recordConsent.no-plus')['data']['phone_number'], 'Telenow would have kept it so, and it would never match');
        $this->assertSame(2, RecordedTelenow::body('getConsentCoverage.required')['data']['pending_covered'], 'of seven people, two on file written the same way');
    }

    public function test_a_bad_number_and_missing_words_are_said_together(): void
    {
        $this->record(['phoneNumber' => '14155550101', 'consentType' => 'express_written'])->assertUnprocessable()->assertJsonValidationErrors(['phoneNumber', 'disclosureText']);
    }

    public function test_a_past_end_is_kept_as_history_when_it_says_when_they_agreed_and_reads_as_ended(): void
    {
        $this->record(['capturedAt' => '2019-01-01T00:00:00Z', 'expiresAt' => '2020-01-01T00:00:00Z'])->assertCreated()->assertJsonPath('state', 'expired');
    }

    public function test_it_says_when_the_account_is_not_ready_and_a_stranger_or_another_account_cannot_reach_it(): void
    {
        $this->acme->forceFill(['telenow_key' => null])->save();
        $this->record()->assertStatus(409)->assertJsonPath('message', __('consent.account_not_ready'));
        $this->import("phone_number\n+14155550101\n")->assertStatus(409);
        $this->withdraw('+14155550101')->assertStatus(409);
        $this->assertSame([], $this->sent);
        $stranger = User::create(['name' => 'Nobody', 'email' => 'nobody@example.com', 'password' => 'a long password 9']);
        $this->actingAs($stranger)->getJson(self::ROOT)->assertForbidden();
        $this->actingAs($stranger)->postJson(self::ROOT, $this->good())->assertForbidden();
    }

    public function test_only_this_accounts_records_are_listed_or_withdrawn(): void
    {
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = (new ConsentRecord)->forceFill(['workspace_id' => $other->id, 'phone_number' => '+14155550199', 'consent_type' => 'implied', 'scope' => 'marketing', 'source' => 'paper', 'captured_at' => now(), 'sync_state' => 'synced']);
        $theirs->save();
        $this->list()->assertJsonPath('total', 0);
        $this->withdraw('+14155550199')->assertNotFound();
        $this->assertNull($theirs->fresh()->revoked_at);
    }

    // ── When a write does not go through ────────────────────────────────────────────────────────────────────────────

    public function test_a_lost_answer_does_not_record_it_twice(): void
    {
        $this->loseAnswers = 1;
        $this->record()->assertCreated()->assertJsonPath('syncState', 'pending');
        $this->assertCount(1, $this->held, 'Telenow kept it, and the kit never heard');
        $this->runOutbox();
        $this->assertCount(1, $this->held);
        $this->assertCount(1, $this->called('POST', '/consent'));
        $this->assertSame('synced', ConsentRecord::query()->sole()->sync_state);
        $this->assertSame($this->held[0]['id'], ConsentRecord::query()->sole()->telenow_id);
    }

    public function test_a_try_that_never_arrived_is_sent_again_and_anothers_record_of_that_number_is_not_taken_for_it(): void
    {
        $this->record()->assertCreated();
        $this->answers = ['POST /consent' => [503, ['success' => false, 'error' => 'down']]];
        $this->record(['consentType' => 'express_oral'])->assertCreated()->assertJsonPath('syncState', 'pending');
        $this->answers = [];
        $this->runOutbox();
        $this->assertCount(2, $this->held);
        $this->assertSame(2, ConsentRecord::query()->whereNotNull('telenow_id')->distinct()->count('telenow_id'));
    }

    public function test_telenow_really_does_keep_the_same_one_twice_and_carries_the_kits_id(): void
    {
        $this->assertCount(2, RecordedTelenow::body('getConsentHistory.two')['data']['records']);
        $this->assertSame('a0000001-0000-4000-8000-000000000000', RecordedTelenow::body('getConsentHistory.with-kit-id')['data']['records'][0]['evidence']['kitId']);
    }

    public function test_when_telenow_is_down_it_says_not_yet_counted_and_goes_later(): void
    {
        $this->answers = ['POST /consent' => [503, ['success' => false, 'error' => 'down']]];
        $this->record()->assertCreated()->assertJsonPath('syncState', 'pending');
        $this->answers = [];
        $this->runOutbox();
        $this->assertSame('synced', ConsentRecord::query()->sole()->sync_state);
    }

    public function test_one_telenow_refuses_says_so_on_its_row_in_the_kits_words_and_others_carry_on(): void
    {
        $this->import("phone_number\n+14155550101\n+14155550102\n")->assertOk();
        $count = 0;
        $this->answers = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use (&$count) {
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/consent') && $count++ === 0) {
                return Http::response(RecordedTelenow::body('recordConsent.no-number'), 400);
            }

            return $this->telenow($r);
        });
        $this->runOutbox();
        $rows = ConsentRecord::query()->orderBy('created_at')->orderBy('id')->get();
        $this->assertEqualsCanonicalizing(['failed', 'synced'], $rows->pluck('sync_state')->all());
        $failed = $rows->firstWhere('sync_state', 'failed');
        $this->assertSame(__('consent.refused'), $failed->sync_error);
        $this->assertSame(0, OutboxEntry::query()->whereNull('done_at')->count());
    }

    public function test_other_refusals_stop_there_and_are_tried_again_later_by_the_job(): void
    {
        $this->answers = ['POST /consent' => [401, ['success' => false, 'error' => 'no']]];
        $this->record()->assertCreated()->assertJsonPath('syncState', 'pending');
        $this->assertSame('pending', ConsentRecord::query()->sole()->sync_state);
        $this->assertSame(1, OutboxEntry::query()->whereNotNull('failed_at')->whereNull('done_at')->count());
        $this->answers = [];
        Artisan::call('kit:consent');
        $this->assertSame('pending', ConsentRecord::query()->sole()->sync_state, 'not until it has waited');
        OutboxEntry::query()->update(['failed_at' => now()->subMinutes(config('kit.consent.retry_given_up_minutes') + 1)]);
        Artisan::call('kit:consent');
        $this->assertSame('synced', ConsentRecord::query()->sole()->sync_state);
    }

    public function test_a_new_record_sends_again_what_was_given_up(): void
    {
        $this->answers = ['POST /consent' => [401, ['success' => false, 'error' => 'no']]];
        $this->record()->assertCreated();
        $this->answers = [];
        $this->record(['phoneNumber' => '+14155550102'])->assertCreated()->assertJsonPath('syncState', 'synced');
        $this->assertSame(['synced', 'synced'], ConsentRecord::query()->pluck('sync_state')->all());
    }

    // ── A file ──────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_file_is_saved_at_once_and_sent_by_the_job_with_its_kind_words_and_date(): void
    {
        $this->import("phone_number,first_name\n+14155550101,Ana\n+1 415 555 0102,Ben\n", ['consentType' => 'express_written', 'scope' => 'service', 'disclosureText' => self::WORDS, 'disclosureVersion' => 'v2'])->assertOk()->assertExactJson(['added' => 2, 'skipped' => 0, 'duplicates' => 0]);
        $this->assertSame([], $this->called('POST', '/consent'), 'a request does not wait for a file');
        $this->assertSame(2, ConsentRecord::query()->where('sync_state', 'pending')->count());
        $this->assertSame(1, OutboxEntry::query()->where('resource', ConsentWrite::RESOURCE)->count());
        $this->runOutbox();
        $sent = $this->called('POST', '/consent');
        $this->assertCount(2, $sent);
        $b = $sent[0]['body'];
        $this->assertSame(['express_written', 'service', 'import', self::WORDS, 'v2', '2026-10-01T09:00:00Z'], [$b['consent_type'], $b['scope'], $b['source'], $b['disclosure_text'], $b['disclosure_version'], $b['captured_at']]);
        $this->assertEqualsCanonicalizing(['+14155550101', '+14155550102'], array_map(fn (array $s) => $s['body']['phone_number'], $sent));
        $this->assertSame($this->ana->id, ConsentRecord::query()->first()->recorded_by);
    }

    public function test_a_long_file_goes_a_part_at_a_time_and_is_not_counted_a_failure(): void
    {
        config(['kit.consent.push_per_run' => 2]);
        $this->import("phone_number\n+14155550101\n+14155550102\n+14155550103\n+14155550104\n+14155550105\n")->assertOk();
        $this->runOutbox();
        $this->assertCount(2, $this->called('POST', '/consent'));
        $entry = OutboxEntry::query()->where('resource', ConsentWrite::RESOURCE)->sole();
        $this->assertSame(0, $entry->attempts, 'coming back for the rest is not a try');
        $this->assertNull($entry->done_at);
        $this->runOutbox();
        $this->runOutbox();
        $this->assertCount(5, $this->called('POST', '/consent'));
        $this->assertNotNull($entry->fresh()->done_at);
    }

    public function test_a_request_does_not_wait_behind_a_long_file(): void
    {
        config(['kit.consent.send_at_once_max' => 2]);
        $this->import("phone_number\n+14155550101\n+14155550102\n+14155550103\n")->assertOk();
        $this->record(['phoneNumber' => '+14155550104'])->assertCreated()->assertJsonPath('syncState', 'pending');
        $this->assertSame([], $this->called('POST', '/consent'));
        $this->runOutbox();
        $this->assertCount(4, $this->called('POST', '/consent'));
    }

    public function test_each_line_may_say_when_that_person_agreed_and_when_it_ends(): void
    {
        $this->import("phone,consent_date,expires_on\n+14155550101,2025-03-01,2027-03-01\n+14155550102,,\n", ['capturedAt' => '2026-10-01T09:00:00Z'])->assertOk()->assertJsonPath('added', 2);
        $this->runOutbox();
        $byPhone = collect($this->called('POST', '/consent'))->keyBy(fn (array $s) => $s['body']['phone_number']);
        $this->assertSame(['2025-03-01T00:00:00Z', '2027-03-01T00:00:00Z'], [$byPhone['+14155550101']['body']['captured_at'], $byPhone['+14155550101']['body']['expires_at']]);
        $this->assertSame('2026-10-01T09:00:00Z', $byPhone['+14155550102']['body']['captured_at']);
    }

    public function test_lines_that_cannot_count_are_skipped_and_said(): void
    {
        $csv = "phone_number,consent_date,expires_on\n"
            ."14155550101,2025-01-01,\n"          // no plus
            .",2025-01-01,\n"                      // no number
            ."+14155550102,someday,\n"             // no good date
            ."+14155550103,2099-01-01,\n"          // the future
            ."+14155550104,2025-01-01,2024-01-01\n" // ends before it began
            ."+14155550105,2025-01-01,\n"
            ."\n";
        $this->actingAs($this->ana)->postJson(self::ROOT.'/import', ['csv' => $csv, 'consentType' => 'implied'])->assertOk()->assertExactJson(['added' => 1, 'skipped' => 5, 'duplicates' => 0]);
        $this->assertSame(['+14155550105'], ConsentRecord::query()->pluck('phone_number')->all());
    }

    public function test_a_file_sent_twice_adds_nothing_the_second_time(): void
    {
        $csv = "phone_number\n+14155550101\n+14155550101\n+14155550102\n";
        $this->import($csv)->assertOk()->assertExactJson(['added' => 2, 'skipped' => 0, 'duplicates' => 1]);
        $this->import($csv)->assertOk()->assertExactJson(['added' => 0, 'skipped' => 0, 'duplicates' => 3]);
        $this->assertSame(2, ConsentRecord::query()->count());
        $this->assertSame(1, OutboxEntry::query()->count());
    }

    public function test_the_same_person_on_another_date_or_kind_is_another_record(): void
    {
        $this->import("phone_number\n+14155550101\n")->assertOk();
        $this->import("phone_number\n+14155550101\n", ['capturedAt' => '2026-10-02T09:00:00Z'])->assertOk()->assertJsonPath('added', 1);
        $this->import("phone_number\n+14155550101\n", ['consentType' => 'express_oral'])->assertOk()->assertJsonPath('added', 1);
        $this->import("phone_number\n+14155550101\n", ['scope' => 'service'])->assertOk()->assertJsonPath('added', 1);
        $this->assertSame(1, OutboxEntry::query()->count(), 'one write sends all that are waiting');
    }

    public function test_an_old_file_sent_again_does_not_bring_back_someone_who_has_since_said_no(): void
    {
        $this->import("phone_number\n+14155550101\n+14155550102\n")->assertOk();
        $this->runOutbox();
        $this->withdraw('+14155550101')->assertNoContent();
        $this->import("phone_number\n+14155550101\n+14155550102\n")->assertOk()->assertExactJson(['added' => 0, 'skipped' => 0, 'duplicates' => 2]);
        $this->assertSame(1, ConsentRecord::query()->whereNull('revoked_at')->count());
        $this->import("phone_number\n+14155550101\n", ['capturedAt' => '2026-11-01T09:00:00Z'])->assertOk()->assertJsonPath('added', 1);
    }

    public function test_a_file_needs_a_number_column_and_a_date_and_is_kept_to_its_limits(): void
    {
        $this->import("mobile\n+14155550101\n")->assertUnprocessable()->assertJsonValidationErrors(['csv']);
        $this->actingAs($this->ana)->postJson(self::ROOT.'/import', ['csv' => "phone_number\n+14155550101\n", 'consentType' => 'implied'])->assertUnprocessable()->assertJsonPath('errors.capturedAt.0', __('consent.import_needs_date', ['column' => config('kit.consent.captured_column')]));
        $this->import("phone_number\n+14155550101\n", ['consentType' => 'express_written'])->assertUnprocessable()->assertJsonValidationErrors(['disclosureText']);
        $this->import("phone_number\n+14155550101\n", ['capturedAt' => now()->addDay()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors(['capturedAt']);
        config(['kit.consent.import_max_rows' => 2]);
        $this->import("phone_number\n+14155550101\n+14155550102\n+14155550103\n")->assertUnprocessable()->assertJsonValidationErrors(['csv']);
        config(['kit.consent.import_max_bytes' => 5]);
        $this->import("phone_number\n+14155550101\n")->assertUnprocessable()->assertJsonValidationErrors(['csv']);
        $this->assertSame(0, ConsentRecord::query()->count());
    }

    public function test_a_file_with_a_mark_at_its_start_still_finds_its_number_column_and_more_rows_than_one_insert(): void
    {
        config(['kit.consent.insert_batch' => 2]);
        $this->import("\xEF\xBB\xBFPhone_Number\n+14155550101\n+14155550102\n+14155550103\n")->assertOk()->assertJsonPath('added', 3);
    }

    // ── Withdrawing ─────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_withdrawing_takes_all_of_a_numbers_records_and_lists_it_at_once(): void
    {
        $this->record(['note' => 'first'])->assertCreated();
        $this->record(['consentType' => 'express_oral'])->assertCreated();
        $this->record(['phoneNumber' => '+14155550102'])->assertCreated();
        $this->withdraw('+1 415 555 0101', ['note' => 'Asked by email'])->assertNoContent();
        [$revoke] = $this->called('POST', '/revoke');
        $this->assertStringEndsWith('/consent/%2B14155550101/revoke', $revoke['path']);
        $this->assertSame(['source' => config('kit.consent.revoke_source'), 'note' => 'Asked by email'], $revoke['body']);
        $this->assertSame(['withdrawn', 'withdrawn', 'live'], array_map(fn (string $p) => $p, collect($this->list()->json('records'))->sortBy(fn (array $r) => [$r['phoneNumber'], $r['id']])->pluck('state')->all()));
        $row = collect($this->list()->json('records'))->firstWhere('state', 'withdrawn');
        $this->assertSame('Asked by email', $row['withdrawnNote']);
        $this->assertNotNull($row['withdrawnAt']);
        $this->assertTrue($row['onDoNotCall'], 'read straight after, not at the next feed');
        $this->assertSame(1, DncEntry::query()->where('phone_number', '+14155550101')->where('source', 'opt_out')->count());
        $this->assertSame($this->ana->id, ConsentRecord::query()->whereNotNull('revoked_at')->first()->revoked_by);
        $this->assertSame(2, ConsentRecord::query()->whereNotNull('revoke_sent_at')->count());
    }

    public function test_nothing_is_removed_by_withdrawing_and_there_is_no_way_to_change_a_record(): void
    {
        $this->record()->assertCreated();
        $this->withdraw('+14155550101')->assertNoContent();
        $this->assertSame(1, ConsentRecord::query()->count());
        $id = ConsentRecord::query()->sole()->id;
        $this->actingAs($this->ana)->deleteJson(self::ROOT."/{$id}")->assertNotFound();
        $this->actingAs($this->ana)->putJson(self::ROOT."/{$id}", [])->assertNotFound();
        $this->actingAs($this->ana)->patchJson(self::ROOT."/{$id}", [])->assertNotFound();
    }

    public function test_there_is_nothing_to_withdraw_for_a_number_with_none_or_already_withdrawn_or_badly_written(): void
    {
        $this->withdraw('+14155550101')->assertNotFound()->assertJsonPath('message', __('consent.nothing_to_withdraw'));
        $this->record()->assertCreated();
        $this->withdraw('14155550101')->assertNotFound();
        $this->withdraw('+14155550101')->assertNoContent();
        $this->withdraw('+14155550101')->assertNotFound();
        $this->assertCount(1, $this->called('POST', '/revoke'));
        $this->actingAs($this->ana)->postJson(self::ROOT.'/withdraw', ['phoneNumber' => ''])->assertUnprocessable();
        $this->withdraw('+14155550102', ['note' => str_repeat('x', config('kit.consent.note_max_length') + 1)])->assertUnprocessable()->assertJsonValidationErrors(['note']);
    }

    public function test_a_record_not_sent_yet_is_never_sent_once_withdrawn_and_telenow_still_lists_the_number(): void
    {
        $this->import("phone_number\n+14155550101\n+14155550102\n")->assertOk();
        $this->withdraw('+14155550101')->assertNoContent();
        $this->assertCount(1, $this->called('POST', '/revoke'), 'it does not wait behind the file');
        $this->runOutbox();
        $this->assertSame(['+14155550102'], array_map(fn (array $s) => $s['body']['phone_number'], $this->called('POST', '/consent')));
        $this->assertSame('synced', ConsentRecord::query()->where('phone_number', '+14155550101')->value('sync_state'), 'nothing more is on its way for it');
        $this->assertNull(ConsentRecord::query()->where('phone_number', '+14155550101')->value('telenow_id'));
    }

    public function test_a_withdrawal_goes_later_when_telenow_is_down_and_nothing_new_is_taken_for_that_number_until_it_has(): void
    {
        $this->record()->assertCreated();
        $this->answers = ['POST /revoke' => [503, ['success' => false, 'error' => 'down']]];
        $this->withdraw('+14155550101')->assertNoContent();
        $this->record()->assertStatus(409)->assertJsonPath('message', __('consent.still_withdrawing'));
        $this->import("phone_number\n+14155550101\n+14155550102\n")->assertOk()->assertExactJson(['added' => 1, 'skipped' => 1, 'duplicates' => 0]);
        $this->answers = [];
        $this->runOutbox();
        $this->record()->assertCreated()->assertJsonPath('state', 'live');
        $this->assertNotNull(ConsentRecord::query()->whereNotNull('revoked_at')->first()->revoke_sent_at);
    }

    public function test_a_record_withdrawn_while_its_request_was_out_is_withdrawn_at_telenow_too(): void
    {
        $this->record()->assertCreated();
        $row = ConsentRecord::query()->sole();
        $row->forceFill(['sync_state' => 'pending', 'telenow_id' => null, 'tried_at' => null])->save();
        $this->answers = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use ($row) {
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/consent')) {
                $row->forceFill(['revoked_at' => now()])->save();
            }

            return $this->telenow($r);
        });
        $this->sent = [];
        $w = app(PushConsentAtTelenow::class);
        $w->send(OutboxEntry::query()->create(['resource' => ConsentWrite::RESOURCE, 'kit_id' => $this->acme->id, 'operation' => 'create', 'idempotency_key' => (string) Str::uuid(), 'next_attempt_at' => now()]));
        $this->assertSame(1, OutboxEntry::query()->where('kit_id', $row->id)->where('operation', 'revoke')->whereNull('done_at')->count());
    }

    public function test_a_failed_read_of_the_list_afterwards_does_not_undo_the_withdrawal(): void
    {
        $this->record()->assertCreated();
        $this->answers = ['GET /dnc' => [503, ['success' => false, 'error' => 'down']]];
        $this->withdraw('+14155550101')->assertNoContent();
        $this->assertNotNull(ConsentRecord::query()->sole()->revoke_sent_at);
        $this->assertSame(0, OutboxEntry::query()->whereNull('done_at')->count());
    }

    public function test_what_was_recorded_is_what_this_rests_on(): void
    {
        $this->assertSame(2, RecordedTelenow::body('revokeConsent.two')['data']['revoked']);
        $this->assertSame(0, RecordedTelenow::body('revokeConsent.again')['data']['revoked']);
        $this->assertSame('2099-01-01T00:00:00Z', RecordedTelenow::body('recordConsent.future')['data']['captured_at'], 'it stores a date in the future');
        $this->assertSame('2026-09-01T00:00:00Z', RecordedTelenow::body('recordConsent.backwards')['data']['expires_at'], 'and an end before the start');
        $this->assertSame('+14155550101', RecordedTelenow::body('recordConsent.written')['data']['phone_number']);
        $this->assertStringContainsString('disclosure_text', RecordedTelenow::body('recordConsent.no-words')['error']);
        $this->assertContains('opt_out', array_column(RecordedTelenow::body('listDnc.after-revoking')['data']['entries'], 'source'));
        $this->assertSame('required', RecordedTelenow::body('getConsentCoverage.inherited')['data']['mode']);
        $this->assertStringContainsString('cannot be switched off', RecordedTelenow::body('setConsentEnforcement.held')['error']);
        $this->assertSame(5, RecordedTelenow::body('getCampaignReach.consent-required')['data']['excludedConsent']);
    }

    // ── The list ────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_list_is_newest_first_by_any_part_of_the_number_and_a_page_at_a_time(): void
    {
        config(['kit.consent.per_page' => 2]);
        foreach ([['+14155550101', '2026-11-01 10:00:00'], ['+14155550102', '2026-11-02 10:00:00'], ['+442071838750', '2026-11-03 10:00:00']] as [$phone, $at]) {
            (new ConsentRecord)->forceFill(['workspace_id' => $this->acme->id, 'phone_number' => $phone, 'consent_type' => 'implied', 'scope' => 'marketing', 'source' => 'paper', 'captured_at' => now(), 'sync_state' => 'synced', 'created_at' => $at])->save();
        }
        $this->list()->assertJsonPath('total', 3)->assertJsonPath('perPage', 2)->assertJsonCount(2, 'records')->assertJsonPath('records.0.phoneNumber', '+442071838750');
        $this->list('?page=2')->assertJsonCount(1, 'records')->assertJsonPath('records.0.phoneNumber', '+14155550101');
        $this->list('?search=0102')->assertJsonPath('total', 1)->assertJsonPath('records.0.phoneNumber', '+14155550102');
        $this->list('?search=%2B44 2071')->assertJsonPath('total', 1)->assertJsonPath('records.0.phoneNumber', '+442071838750');
        $this->list('?search=nothing')->assertJsonPath('total', 3);
    }

    public function test_a_live_record_for_a_number_on_the_do_not_call_list_says_so(): void
    {
        $this->record()->assertCreated()->assertJsonPath('onDoNotCall', false);
        $entry = new DncEntry(['phone_number' => '+14155550101']);
        $entry->forceFill(['workspace_id' => $this->acme->id, 'source' => 'opt_out', 'sync_state' => 'synced'])->save();
        $this->list()->assertJsonPath('records.0.onDoNotCall', true);
        $this->record(['phoneNumber' => '+14155550102'])->assertCreated()->assertJsonPath('onDoNotCall', false);
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = new DncEntry(['phone_number' => '+14155550102']);
        $theirs->forceFill(['workspace_id' => $other->id, 'source' => 'manual', 'sync_state' => 'synced'])->save();
        $this->list('?search=0102')->assertJsonPath('records.0.onDoNotCall', false);
        $this->record(['phoneNumber' => '+14155550101', 'consentType' => 'express_oral'])->assertCreated()->assertJsonPath('onDoNotCall', true);
        $entry->forceFill(['deleted_at' => now()])->save();
        $this->list()->assertJsonPath('records.0.onDoNotCall', false);
    }

    public function test_it_gives_the_choices_and_limits_a_form_needs(): void
    {
        $c = config('kit.consent');
        $this->list()->assertJsonPath('limits', ['types' => $c['types'], 'writtenType' => $c['written_type'], 'scopes' => $c['scopes'], 'defaultScope' => $c['default_scope'], 'sources' => $c['sources'], 'phoneColumns' => config('kit.campaigns.phone_columns'),
            'capturedColumn' => $c['captured_column'], 'expiresColumn' => $c['expires_column'], 'importMaxBytes' => $c['import_max_bytes'], 'importMaxRows' => $c['import_max_rows']]);
    }

    // ── Whether calls need it ───────────────────────────────────────────────────────────────────────────────────────

    public function test_off_to_begin_with_and_says_what_waits(): void
    {
        $this->coverage = RecordedTelenow::body('getConsentCoverage.off')['data'];
        unset($this->coverage['mode']);
        $this->list()->assertJsonPath('rule', ['required' => false, 'byOperator' => false, 'byCustomer' => false, 'byProvider' => false, 'saving' => false, 'waiting' => ['total' => 7, 'covered' => 2, 'blocked' => 5], 'canChange' => true]);
    }

    public function test_a_failed_read_leaves_the_page_working(): void
    {
        $this->answers = ['GET /coverage' => [503, ['success' => false, 'error' => 'down']]];
        $this->list()->assertJsonPath('rule.waiting', null)->assertJsonPath('rule.required', false);
    }

    public function test_a_manager_turns_it_on_and_off_and_it_reaches_telenow(): void
    {
        $this->rule(true)->assertOk()->assertJsonPath('required', true)->assertJsonPath('byCustomer', true)->assertJsonPath('saving', false)->assertJsonPath('canChange', true);
        $this->assertSame(['required', 'required'], [$this->mode, $this->acme->fresh()->consent_mode]);
        $this->assertSame(self::KEY, $this->called('PUT', '/enforcement')[0]['key']);
        $this->rule(false)->assertOk()->assertJsonPath('required', false)->assertJsonPath('byCustomer', false);
        $this->assertSame('off', $this->mode);
    }

    public function test_someone_who_does_not_manage_the_account_cannot_and_is_told(): void
    {
        $this->rule(true, $this->ben)->assertForbidden()->assertJsonPath('message', __('consent.only_managers'));
        $this->assertSame('off', $this->mode);
        $this->assertFalse($this->acme->fresh()->consent_required);
        $this->actingAs($this->ben)->getJson(self::ROOT)->assertOk()->assertJsonPath('rule.canChange', false);
        $this->actingAs($this->ben)->postJson(self::ROOT, $this->good())->assertCreated();
        $this->actingAs($this->ana)->putJson(self::ROOT.'/rule', ['required' => 'maybe'])->assertUnprocessable();
    }

    public function test_when_telenow_is_down_the_rule_goes_later_and_says_it_is_on_its_way(): void
    {
        $this->answers = ['PUT /enforcement' => [503, ['success' => false, 'error' => 'down']], 'GET /coverage' => [503, ['success' => false, 'error' => 'down']]];
        $this->rule(true)->assertOk()->assertJsonPath('required', true)->assertJsonPath('saving', true);
        $this->answers = [];
        $this->runOutbox();
        $this->assertSame(['required', 'required'], [$this->mode, $this->acme->fresh()->consent_mode]);
        $this->list()->assertJsonPath('rule.saving', false);
    }

    public function test_turning_it_off_while_telenow_is_down_is_not_taken_for_a_refusal(): void
    {
        $this->rule(true)->assertOk();
        $this->answers = ['PUT /enforcement' => [503, ['success' => false, 'error' => 'down']]];
        $this->rule(false)->assertOk()->assertJsonPath('required', false)->assertJsonPath('byProvider', false)->assertJsonPath('saving', true);
        $this->assertFalse($this->acme->fresh()->consent_locked);
        $this->answers = [];
        $this->runOutbox();
        $this->assertSame('off', $this->mode);
    }

    public function test_a_refusal_of_turning_it_on_is_not_taken_for_one_above_holding_it(): void
    {
        $this->answers = ['PUT /enforcement' => [403, ['success' => false, 'error' => 'no']]];
        $this->rule(true)->assertOk();
        $this->assertFalse($this->acme->fresh()->consent_locked);
        $this->assertSame('off', $this->acme->fresh()->consent_mode);
        $this->assertSame(1, OutboxEntry::query()->whereNotNull('failed_at')->whereNull('done_at')->count());
    }

    public function test_it_cannot_be_turned_off_under_an_account_that_requires_it_and_stays_on(): void
    {
        $this->rule(true)->assertOk();
        $this->parentRequires = true;
        $answer = $this->rule(false)->assertStatus(409)->assertJsonPath('message', __('consent.held_by_provider'));
        $this->assertStringNotContainsString('billed', $answer->getContent());
        $w = $this->acme->fresh();
        $this->assertSame(['required', true], [$w->consent_mode, $w->consent_locked]);
        $this->assertSame(0, OutboxEntry::query()->whereNull('done_at')->count(), 'an answer, and not a failure to try again');
        $this->list()->assertJsonPath('rule', ['required' => true, 'byOperator' => false, 'byCustomer' => false, 'byProvider' => true, 'saving' => false, 'waiting' => ['total' => 0, 'covered' => 0, 'blocked' => 0], 'canChange' => true]);
        $this->assertSame(0, $this->callSweep(), 'and nothing keeps trying');
        $this->parentRequires = false;
        $this->rule(false)->assertOk()->assertJsonPath('required', false)->assertJsonPath('byProvider', false);
        $this->assertSame('off', $this->mode);
    }

    public function test_an_account_made_under_one_that_requires_it_says_so_when_read(): void
    {
        $this->mode = 'required';
        $this->parentRequires = true;
        $this->list()->assertJsonPath('rule.required', true)->assertJsonPath('rule.byProvider', true)->assertJsonPath('rule.byCustomer', false)->assertJsonPath('rule.saving', false);
        $this->assertSame(0, $this->callSweep());
    }

    public function test_what_telenow_holds_corrects_the_kits_copy_when_someone_changed_it_and_the_job_puts_it_right(): void
    {
        $this->rule(true)->assertOk();
        $this->mode = 'off';
        $this->list()->assertJsonPath('rule.required', true)->assertJsonPath('rule.saving', true);
        $this->assertSame('off', $this->acme->fresh()->consent_mode);
        Artisan::call('kit:consent');
        $this->assertSame('required', $this->mode);
    }

    public function test_a_read_does_not_correct_it_while_a_write_is_on_its_way(): void
    {
        $this->answers = ['PUT /enforcement' => [503, ['success' => false, 'error' => 'down']]];
        $this->rule(true)->assertOk();
        $this->list()->assertJsonPath('rule.saving', true);
        $this->assertSame('off', $this->acme->fresh()->consent_mode);
        // The write took, its answer was lost, and they changed their mind: what Telenow says now is the kit's own doing, and nobody above holds it.
        $this->mode = 'required';
        $this->rule(false)->assertOk()->assertJsonPath('byProvider', false);
        $this->assertFalse($this->acme->fresh()->consent_locked);
        $this->assertSame('off', $this->acme->fresh()->consent_mode);
    }

    public function test_two_changes_while_down_leave_one_write_which_sends_the_last(): void
    {
        $this->answers = ['PUT /enforcement' => [503, ['success' => false, 'error' => 'down']]];
        $this->rule(true)->assertOk();
        $this->rule(false)->assertOk();
        $this->rule(true)->assertOk();
        $this->assertSame(1, OutboxEntry::query()->where('resource', ConsentWrite::RULE_RESOURCE)->count());
        $this->answers = [];
        $this->runOutbox();
        $this->assertSame('required', $this->mode);
    }

    public function test_a_few_stuck_accounts_do_not_hold_every_runs_places_and_still_count_as_not_told(): void
    {
        $this->answers = ['PUT /enforcement' => [503, ['success' => false, 'error' => 'down']]];
        $rule = app(ConsentRule::class);
        $rule->saveForEveryone(true);
        config(['kit.consent.rule_sweep_per_run' => 1]);
        Artisan::call('kit:consent');
        $this->assertSame(1, OutboxEntry::query()->where('resource', ConsentWrite::RULE_RESOURCE)->count());
        $this->assertSame(1, $rule->stillToTell(), 'not told yet, whatever is on its way');
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $other->forceFill(['telenow_sub_account_id' => (string) Str::uuid(), 'telenow_key' => self::KEY])->save();
        $this->answers = [];
        Artisan::call('kit:consent');
        $this->assertSame('required', $other->fresh()->consent_mode);
        $this->assertSame('off', $this->acme->fresh()->consent_mode);
    }

    public function test_the_operator_requiring_it_reaches_every_account_a_few_at_a_time_and_a_new_one_too(): void
    {
        $others = [];
        foreach (range(1, 3) as $i) {
            $w = Workspace::create(['name' => "Other {$i}", 'status' => 'active', 'sync_state' => 'synced']);
            $w->forceFill(['telenow_sub_account_id' => (string) Str::uuid(), 'telenow_key' => self::KEY])->save();
            $others[] = $w;
        }
        $notYet = Workspace::create(['name' => 'Setting up', 'status' => 'active', 'sync_state' => 'pending']);
        config(['kit.consent.rule_sweep_per_run' => 2]);
        $rule = app(ConsentRule::class);
        $this->acme->forceFill(['consent_required' => true])->save();
        $this->assertSame(0, $rule->stillToTell(), 'one customer on their way to their own choice is not the operator asking');
        $rule->saveForEveryone(true);
        $this->assertSame(4, $rule->stillToTell());
        Artisan::call('kit:consent');
        $this->assertCount(2, $this->called('PUT', '/enforcement'));
        $this->assertSame(2, $rule->stillToTell());
        Artisan::call('kit:consent');
        Artisan::call('kit:consent');
        $this->assertCount(4, $this->called('PUT', '/enforcement'));
        $this->assertSame(0, $rule->stillToTell());
        $this->assertSame('off', $notYet->fresh()->consent_mode, 'no key yet');
        $notYet->forceFill(['telenow_sub_account_id' => (string) Str::uuid(), 'telenow_key' => self::KEY])->save();
        Artisan::call('kit:consent');
        $this->assertSame('required', $notYet->fresh()->consent_mode);
    }

    public function test_a_customer_cannot_turn_off_what_the_operator_requires_and_their_own_choice_comes_back_when_the_operator_stops(): void
    {
        $this->rule(true)->assertOk();
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $other->forceFill(['telenow_sub_account_id' => (string) Str::uuid(), 'telenow_key' => self::KEY, 'consent_mode' => 'required'])->save();
        $rule = app(ConsentRule::class);
        $rule->saveForEveryone(true);
        $this->rule(false)->assertStatus(409)->assertJsonPath('message', __('consent.required_by_operator'));
        $this->assertTrue($this->acme->fresh()->consent_required);
        $this->list()->assertJsonPath('rule.byOperator', true)->assertJsonPath('rule.required', true);
        $rule->saveForEveryone(false);
        $this->sent = [];
        Artisan::call('kit:consent');
        $this->assertSame('off', $other->fresh()->consent_mode, 'it never asked');
        $this->assertSame('required', $this->acme->fresh()->consent_mode, 'its own choice stays');
        $this->assertCount(1, $this->called('PUT', '/enforcement'));
    }

    public function test_the_job_does_not_write_twice_for_one_on_its_way(): void
    {
        $this->answers = ['PUT /enforcement' => [503, ['success' => false, 'error' => 'down']]];
        $this->rule(true)->assertOk();
        $this->sent = [];
        Artisan::call('kit:consent');
        $this->assertSame([], $this->called('PUT', '/enforcement'));
        $this->assertSame(1, OutboxEntry::query()->where('resource', ConsentWrite::RULE_RESOURCE)->count());
    }

    public function test_a_change_of_mind_replaces_a_write_given_up(): void
    {
        $this->answers = ['PUT /enforcement' => [401, ['success' => false, 'error' => 'no']]];
        $this->rule(true)->assertOk();
        $this->assertSame(1, OutboxEntry::query()->whereNotNull('failed_at')->whereNull('done_at')->count());
        $this->answers = [];
        $this->rule(false)->assertOk();
        $this->assertSame(0, OutboxEntry::query()->whereNull('done_at')->count());
        $this->assertSame('off', $this->acme->fresh()->consent_mode);
    }

    public function test_a_closed_account_is_left_alone(): void
    {
        $this->acme->forceFill(['telenow_key' => null])->save();
        app(ConsentRule::class)->saveForEveryone(true);
        Artisan::call('kit:consent');
        $this->assertSame([], $this->sent);
        $this->assertSame(0, OutboxEntry::query()->count());
        $this->assertSame(0, app(ConsentRule::class)->stillToTell());
        $this->list()->assertJsonPath('rule.waiting', null);
    }

    // ── From a campaign ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_list_says_how_many_numbers_consent_cannot_be_matched_to(): void
    {
        $campaign = (new Campaign)->forceFill(['workspace_id' => $this->acme->id, 'name' => 'Recall', 'concurrency' => 2, 'timezone' => 'UTC', 'machine_detection' => 'off', 'max_attempts' => 1, 'retry_on_no_answer' => true, 'status' => 'draft', 'telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced']);
        $campaign->save();
        $this->answers = ['POST /targets' => [200, ['added' => 3, 'duplicates' => 0, 'suppressed' => 0, 'skipped' => 0]]];
        $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$campaign->id}/targets", ['csv' => "phone_number\n+14155550101\n14155550102\n4155550103\n"])->assertOk()->assertExactJson(['added' => 3, 'skipped' => 0, 'noCountryCode' => 2]);
    }

    private function callSweep(): int
    {
        return app(ConsentRule::class)->sweep();
    }
}
