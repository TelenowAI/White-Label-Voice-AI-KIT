<?php

namespace Tests\Feature\Kit;

use App\Kit\Outbox\OutboxEntry;
use App\Models\Call;
use App\Models\DncEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Console → Do not call (App\Kit\Dnc): a number is put on the customer's list at Telenow with its own key — written as
 * Telenow writes it, the entry the kit already had when it was on the list, the kit's own words when Telenow cannot
 * dial it — and taken off; a CSV goes to Telenow whole and its numbers come back through the feed, which also brings
 * Telenow's own entries (a caller's opt-out, linked to its call) and every removal. Real recordings (contract_k1dnc.py).
 */
class DncTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const DNC = '/api/orgs/3e1cd17a-d25e-40c4-b0b0-0694f052f6da/dnc';

    private User $ana;

    private Workspace $acme;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        RecordedTelenow::fake();
    }

    private function answering(\Closure $first): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $recorded = RecordedTelenow::responder();
        Http::fake(fn (Request $r) => $first($r) ?? $recorded($r));
    }

    private static function is(Request $r, string $method, string $path): bool
    {
        return $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path;
    }

    /** @return list<Request> */
    private function sent(string $method, string $path): array
    {
        return Http::recorded(fn (Request $r) => self::is($r, $method, $path))->map(fn (array $p) => $p[0])->values()->all();
    }

    private function add(string $number, ?string $reason = 'Asked not to be called'): TestResponse
    {
        return $this->actingAs($this->ana)->postJson('/api/kit/console/dnc', ['phoneNumber' => $number, 'reason' => $reason]);
    }

    public function test_a_number_goes_on_the_list_at_telenow_written_its_way(): void
    {
        $telenow = RecordedTelenow::body('addDnc.customer')['data'];
        $made = $this->add('+1 (415) 555-0123')->assertCreated()
            ->assertJson(['phoneNumber' => '+14155550123', 'reason' => 'Asked not to be called', 'source' => 'manual', 'syncState' => 'synced', 'syncError' => null, 'callId' => null]);
        [$add] = $this->sent('POST', self::DNC);
        $this->assertSame([self::KEY], $add->header('X-API-Key'));
        $this->assertEquals(['phoneNumber' => '+1 (415) 555-0123', 'reason' => 'Asked not to be called'], json_decode($add->body(), true));
        foreach ([$telenow['id'], $telenow['created_by'], $telenow['org_id']] as $never) {
            $this->assertStringNotContainsString($never, $made->getContent());
        }
        $this->actingAs($this->ana)->getJson('/api/kit/console/dnc')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('entries.0.id', $made->json('id'));
        $this->actingAs($this->ana)->getJson('/api/kit/console/dnc?search=0123')->assertJsonPath('total', 1);
        $this->actingAs($this->ana)->getJson('/api/kit/console/dnc?search=Asked')->assertJsonPath('total', 1);
        $this->actingAs($this->ana)->getJson('/api/kit/console/dnc?search=9999')->assertJsonPath('total', 0);
    }

    public function test_a_number_already_on_the_list_is_the_entry_the_kit_had_with_the_new_reason(): void
    {
        $first = $this->add('+1 (415) 555-0123')->json('id');
        $this->answering(fn (Request $r) => self::is($r, 'POST', self::DNC) ? Http::response(RecordedTelenow::body('addDnc.again'), 201) : null);
        // typed another way: the entry is found by the number as Telenow writes it
        $this->add('+1 415 555 0123', 'Complained twice')->assertCreated()->assertJsonPath('id', $first)->assertJsonPath('reason', 'Complained twice');
        $this->assertSame(1, DncEntry::query()->whereNull('deleted_at')->count());
        $this->actingAs($this->ana)->getJson('/api/kit/console/dnc')->assertJsonPath('total', 1)->assertJsonPath('entries.0.reason', 'Complained twice');
    }

    public function test_a_number_telenow_cannot_dial_says_so_in_the_kits_words_and_goes_without_asking_telenow(): void
    {
        $this->answering(fn (Request $r) => self::is($r, 'POST', self::DNC) ? Http::response(RecordedTelenow::body('addDnc.undialable'), 400) : null);
        $made = $this->add('call me maybe')->assertCreated()->assertJson(['syncState' => 'failed', 'syncError' => __('dnc.not_dialable')]);
        $this->assertStringNotContainsString('dialable number:', $made->getContent());
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/dnc/{$made->json('id')}")->assertNoContent();
        $this->assertSame([], Http::recorded(fn (Request $r) => $r->method() === 'DELETE')->all());
        $this->assertSame(0, OutboxEntry::query()->whereNull('done_at')->count());
    }

    public function test_removing_takes_it_off_at_telenow_and_one_gone_there_is_done(): void
    {
        $id = $this->add('+1 (415) 555-0123')->json('id');
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/dnc/{$id}")->assertNoContent();
        $telenowId = RecordedTelenow::body('addDnc.customer')['data']['id'];
        [$remove] = $this->sent('DELETE', self::DNC.'/'.$telenowId);
        $this->assertSame([self::KEY], $remove->header('X-API-Key'));
        $this->actingAs($this->ana)->getJson('/api/kit/console/dnc')->assertJsonPath('total', 0);
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/dnc/{$id}")->assertNotFound();

        $this->answering(fn (Request $r) => self::is($r, 'DELETE', self::DNC.'/'.$telenowId) ? Http::response(RecordedTelenow::body('removeDnc.gone'), 404) : null);
        DncEntry::query()->whereKey($id)->update(['deleted_at' => null]);
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/dnc/{$id}")->assertNoContent();
        $this->assertSame(['synced', true], [DncEntry::query()->find($id)->sync_state, DncEntry::query()->find($id)->deleted_at !== null]);
    }

    public function test_a_csv_goes_to_telenow_whole_and_its_numbers_come_back_through_the_feed(): void
    {
        $csv = "+14155550124,From the CRM\nnot a number\n+14155550125\n";
        $this->actingAs($this->ana)->postJson('/api/kit/console/dnc/import', ['csv' => $csv])->assertOk()->assertExactJson(['added' => 2, 'skipped' => 1]);
        [$import] = $this->sent('POST', self::DNC.'/import');
        $this->assertSame([self::KEY], $import->header('X-API-Key'));
        $this->assertStringContainsString('multipart/form-data', $import->header('Content-Type')[0]);
        // the CSV as sent (the kit's requests are trimmed of outer white space, as every Laravel request is)
        $this->assertStringContainsString(rtrim($csv), $import->body());
        // The feed, read on to its end: the imported numbers, the caller's opt-out, and (in the recording, later) the
        // manual entry's removal.
        $list = $this->actingAs($this->ana)->getJson('/api/kit/console/dnc')->assertJsonPath('total', 3)->json('entries');
        $this->assertEqualsCanonicalizing(['csv', 'csv', 'opt_out'], array_column($list, 'source'));
        $this->assertSame(RecordedTelenow::body('listDnc.feed-next')['data']['nextCursor'], $this->acme->fresh()->dnc_cursor);
    }

    public function test_the_feed_brings_a_callers_opt_out_with_its_call_and_every_removal(): void
    {
        $optOut = collect(RecordedTelenow::body('listDnc.feed-first')['data']['entries'])->firstWhere('source', 'opt_out');
        $call = (new Call)->forceFill(['workspace_id' => $this->acme->id, 'telenow_id' => $optOut['session_id'], 'status' => 'ended']);
        $call->save();
        $this->artisan('kit:dnc')->assertSuccessful();
        $entry = DncEntry::query()->where('source', 'opt_out')->sole();
        $this->assertSame([$optOut['phone_number'], $call->id], [$entry->phone_number, $entry->call_id]);
        [$start] = $this->sent('GET', self::DNC);
        parse_str((string) parse_url($start->url(), PHP_URL_QUERY), $q);
        $this->assertSame(['updatedAfter' => $this->acme->created_at->utc()->format('Y-m-d\TH:i:s.v\Z')], $q, "from the account's start");

        // The feed is read on until a page is empty: its next page (from the cursor) holds the removal.
        [, $next] = $this->sent('GET', self::DNC);
        parse_str((string) parse_url($next->url(), PHP_URL_QUERY), $q2);
        $this->assertSame(['cursor' => RecordedTelenow::body('listDnc.feed-first')['data']['nextCursor']], $q2);
        $removed = RecordedTelenow::body('listDnc.feed-next')['data']['entries'][0]['id'];
        $this->assertNotNull(DncEntry::query()->where('telenow_id', $removed)->value('deleted_at'), 'removed at Telenow, removed here');
        $this->actingAs($this->ana)->getJson('/api/kit/console/dnc')->assertJsonPath('total', 3);
        $this->assertCount(config('kit.dnc.feed_pages_per_run'), $this->sent('GET', self::DNC), 'at most feed_pages_per_run pages a run');
    }

    public function test_a_removal_the_kit_is_still_sending_is_not_undone_by_the_feed(): void
    {
        $id = $this->add('+1 (415) 555-0123')->json('id');
        // Telenow down for the removal, and its feed still listing the entry (and no removal yet)
        $this->answering(fn (Request $r) => match (true) {
            $r->method() === 'DELETE' => Http::response(['error' => 'upstream'], 503),
            self::is($r, 'GET', self::DNC) => Http::response(RecordedTelenow::body('listDnc.feed-first')),
            default => null,
        });
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/dnc/{$id}")->assertNoContent();
        $this->assertSame(['pending', __('dnc.will_retry')], [DncEntry::query()->find($id)->sync_state, DncEntry::query()->find($id)->sync_error]);
        $this->artisan('kit:dnc')->assertSuccessful();
        $this->assertNotNull(DncEntry::query()->find($id)->deleted_at, 'Telenow still lists it, but the kit is removing it');
        $this->actingAs($this->ana)->getJson('/api/kit/console/dnc?search=0123')->assertJsonPath('total', 0);
    }

    public function test_an_import_refused_or_not_sent_says_so_in_the_kits_words(): void
    {
        $this->actingAs($this->ana)->postJson('/api/kit/console/dnc/import', ['csv' => " \n"])->assertStatus(422)->assertJsonValidationErrors('csv');
        config(['kit.dnc.import_max_bytes' => 10]);
        $this->actingAs($this->ana)->postJson('/api/kit/console/dnc/import', ['csv' => str_repeat('+14155550124\n', 3)])->assertStatus(422);
        config(['kit.dnc.import_max_bytes' => 2097152]);
        foreach ([[400, 422, 'dnc.import_refused'], [503, 503, 'dnc.import_unavailable']] as [$telenow, $ours, $words]) {
            $this->answering(fn (Request $r) => str_ends_with($r->url(), '/dnc/import') ? Http::response(['error' => 'Telenow said no'], $telenow) : null);
            $this->actingAs($this->ana)->postJson('/api/kit/console/dnc/import', ['csv' => "+14155550124\n"])->assertStatus($ours)->assertExactJson(['message' => __($words)]);
        }
    }

    public function test_a_member_sees_and_changes_only_their_accounts_list(): void
    {
        $other = Workspace::create(['name' => 'Beta', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = new DncEntry(['phone_number' => '+14155550199', 'reason' => 'x']);
        $theirs->forceFill(['workspace_id' => $other->id, 'sync_state' => 'synced'])->save();
        $this->actingAs($this->ana)->getJson('/api/kit/console/dnc')->assertJsonPath('total', 0);
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/dnc/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->ana)->deleteJson('/api/kit/console/dnc/not-a-uuid')->assertNotFound();
        $this->assertNull($theirs->fresh()->deleted_at);
        $this->add('')->assertStatus(422)->assertJsonValidationErrors('phoneNumber');
        $this->add(str_repeat('1', config('kit.dnc.phone_max_length') + 1))->assertStatus(422);

        $this->acme->forceFill(['telenow_sub_account_id' => null, 'telenow_key' => null])->save();
        $this->add('+14155550123')->assertStatus(409)->assertExactJson(['message' => __('dnc.account_not_ready')]);
        auth()->guard('web')->logout();
        $this->getJson('/api/kit/console/dnc')->assertUnauthorized();
        $this->assertSame([], Http::recorded()->all());
    }
}
