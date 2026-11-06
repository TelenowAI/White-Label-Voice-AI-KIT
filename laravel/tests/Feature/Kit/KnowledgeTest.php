<?php

namespace Tests\Feature\Kit;

use App\Kit\Knowledge\Telenow\BaseWrite;
use App\Kit\Outbox\OutboxEntry;
use App\Models\Agent;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Console → Knowledge (App\Kit\Knowledge): a base is made at Telenow with the customer's own key; a document is added as
 * text, a file (sent as Telenow takes it, then not kept) or a web page (https only), Telenow's refusals in the kit's
 * words; the feed brings whether each was read — never Telenow's error text, which names the vendor's address — and
 * deletions; a document Telenow could not read is tried again; documents and bases are deleted; an agent answers from
 * the bases chosen, all at once. Real recordings (contract_k1knowledge.py: no vendor is reachable from the box, so text
 * failed to embed there and the table was indexed).
 */
class KnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const ORG = '/api/orgs/3e1cd17a-d25e-40c4-b0b0-0694f052f6da';

    private const KB = '8f3b2c1d-5e6a-4b7c-9d8e-0f1a2b3c4d5e';

    private const OPENING_HOURS = '1a2b3c4d-0001-4e5f-8a9b-0c1d2e3f4a5b';

    private const PARKING = '1a2b3c4d-0002-4e5f-8a9b-0c1d2e3f4a5b';

    private const PRICES = '1a2b3c4d-0003-4e5f-8a9b-0c1d2e3f4a5b';

    private User $ana;

    private Workspace $acme;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        Storage::fake(config('kit.knowledge.upload_disk'));
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        $this->agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Front desk']);
        $this->agent->forceFill(['telenow_id' => RecordedTelenow::body('createAgent.customer')['data']['id'], 'sync_state' => 'synced'])->save();
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

    private function base(array $over = []): KnowledgeBase
    {
        $base = (new KnowledgeBase)->forceFill(array_merge(['workspace_id' => $this->acme->id, 'name' => 'Clinic FAQ', 'telenow_id' => self::KB, 'sync_state' => 'synced'], $over));
        $base->save();

        return $base;
    }

    private function doc(KnowledgeBase $base, string $telenowId, string $title, string $status, array $over = []): KnowledgeDocument
    {
        $doc = (new KnowledgeDocument)->forceFill(array_merge(['knowledge_base_id' => $base->id, 'workspace_id' => $this->acme->id, 'title' => $title,
            'source_type' => 'inline', 'status' => $status, 'telenow_id' => $telenowId, 'sync_state' => 'synced'], $over));
        $doc->save();

        return $doc;
    }

    private static function recordedQuery(string $name): array
    {
        $c = json_decode((string) file_get_contents(base_path("../contract/telenow-mock/cassettes/{$name}.json")), true);
        parse_str($c['request']['query'], $q);

        return $q;
    }

    public function test_a_base_is_made_at_telenow_with_the_customers_key(): void
    {
        $made = $this->actingAs($this->ana)->postJson('/api/kit/console/knowledge', ['name' => 'Clinic FAQ', 'description' => 'What callers ask most'])->assertCreated();
        $base = KnowledgeBase::query()->sole();
        $made->assertExactJson(['id' => $base->id, 'name' => 'Clinic FAQ', 'description' => 'What callers ask most',
            'documentCounts' => ['total' => 0, 'ready' => 0, 'working' => 0, 'failed' => 0], 'agents' => [],
            'syncState' => 'synced', 'syncError' => null, 'createdAt' => $base->created_at->toIso8601String()]);
        [$create] = $this->sent('POST', self::ORG.'/knowledge-bases');
        $this->assertSame([self::KEY], $create->header('X-API-Key'));
        $this->assertEquals(['name' => 'Clinic FAQ', 'description' => 'What callers ask most'], json_decode($create->body(), true));
        $this->assertSame([self::KB, RecordedTelenow::body('createKnowledgeBase.customer')['data']['updated_at']], [$base->telenow_id, $base->telenow_updated_at]);
        $this->assertStringNotContainsString(self::KB, $made->getContent());

        $this->actingAs($this->ana)->postJson('/api/kit/console/knowledge', ['description' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = (new KnowledgeBase)->forceFill(['workspace_id' => $other->id, 'name' => 'Theirs', 'sync_state' => 'synced']);
        $theirs->save();
        $this->actingAs($this->ana)->getJson("/api/kit/console/knowledge/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/knowledge/{$theirs->id}")->assertNotFound();
        $this->assertSame(['Clinic FAQ'], array_column($this->actingAs($this->ana)->getJson('/api/kit/console/knowledge')->json('knowledgeBases'), 'name'), 'only its own');
        $this->acme->forceFill(['telenow_sub_account_id' => null])->save();
        $this->actingAs($this->ana)->postJson('/api/kit/console/knowledge', ['name' => 'x'])->assertStatus(409)->assertExactJson(['message' => __('knowledge.account_not_ready')]);
    }

    public function test_documents_are_added_as_text_a_file_or_a_page_and_telenows_refusals_are_the_kits_words(): void
    {
        $base = $this->base();
        $url = "/api/kit/console/knowledge/{$base->id}/documents";
        $text = $this->actingAs($this->ana)->postJson($url, ['title' => 'Opening hours', 'text' => 'We are open Monday to Friday, 9 am to 6 pm.'])->assertCreated();
        $text->assertJson(['title' => 'Opening hours', 'kind' => 'text', 'source' => null, 'state' => 'working', 'problem' => null]);
        [$add] = $this->sent('POST', self::ORG.'/knowledge-bases/'.self::KB.'/documents');
        $this->assertEquals(['title' => 'Opening hours', 'body' => 'We are open Monday to Friday, 9 am to 6 pm.'], json_decode($add->body(), true));
        $this->assertSame(self::OPENING_HOURS, KnowledgeDocument::query()->find($text->json('id'))->telenow_id);

        $file = UploadedFile::fake()->createWithContent('parking.txt', "Parking is free behind the clinic.\n");
        $up = $this->actingAs($this->ana)->post("{$url}/upload", ['file' => $file, 'title' => 'Parking'], ['Accept' => 'application/json'])->assertCreated();
        $up->assertJson(['title' => 'Parking', 'kind' => 'file', 'source' => 'parking.txt', 'state' => 'working']);
        [$sent] = $this->sent('POST', self::ORG.'/knowledge-bases/'.self::KB.'/documents/upload');
        $this->assertTrue($sent->isMultipart());
        $this->assertSame([['file', 'parking.txt'], ['title', null]], array_map(fn ($p) => [$p['name'], $p['filename'] ?? null], $sent->data()));
        $this->assertSame([], Storage::disk(config('kit.knowledge.upload_disk'))->allFiles(), 'sent, then not kept');

        $table = RecordedTelenow::body('uploadKnowledgeFile.table');
        $this->answering(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/documents/upload') ? Http::response($table, 201) : null);
        $this->actingAs($this->ana)->post("{$url}/upload", ['file' => UploadedFile::fake()->createWithContent('prices.csv', "service,price\ncleaning,1200\n")], ['Accept' => 'application/json'])
            ->assertCreated()->assertJson(['title' => 'prices', 'kind' => 'file', 'source' => 'prices.csv', 'state' => 'ready', 'problem' => null]);

        $page = $this->actingAs($this->ana)->postJson("{$url}/page", ['url' => 'https://example.com/'])->assertCreated();
        $page->assertJson(['kind' => 'page', 'source' => 'https://example.com/', 'state' => 'failed', 'problem' => __('knowledge.page_refused')]);
        $this->assertStringNotContainsString('error sending request', $page->getContent());
        [$fetch] = $this->sent('POST', self::ORG.'/knowledge-bases/'.self::KB.'/documents/url');
        $this->assertEquals(['url' => 'https://example.com/'], json_decode($fetch->body(), true));

        $before = count(Http::recorded());
        $this->actingAs($this->ana)->postJson("{$url}/page", ['url' => 'http://example.com/'])->assertStatus(422)->assertJsonPath('errors.url', [__('knowledge.https_only')]);
        $this->actingAs($this->ana)->post("{$url}/upload", ['file' => UploadedFile::fake()->createWithContent('photo.png', 'x')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->actingAs($this->ana)->postJson($url, ['title' => 'Empty'])->assertStatus(422)->assertJsonValidationErrors(['text']);
        $this->assertCount($before, Http::recorded(), 'refused before Telenow');

        $refused = RecordedTelenow::body('uploadKnowledgeFile.unsupported');
        $this->answering(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/documents/upload') ? Http::response($refused, 400) : null);
        $this->actingAs($this->ana)->post("{$url}/upload", ['file' => UploadedFile::fake()->createWithContent('notes.md', '# Notes')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJson(['state' => 'failed', 'problem' => __('knowledge.file_refused', ['types' => implode(', ', config('kit.knowledge.file_types')), 'max' => '20'])]);
        $this->assertSame([], Storage::disk(config('kit.knowledge.upload_disk'))->allFiles(), 'given up: not kept either');
    }

    public function test_a_document_waits_for_its_base_to_be_made(): void
    {
        $base = $this->base(['telenow_id' => null, 'sync_state' => 'pending']);
        $this->actingAs($this->ana)->postJson("/api/kit/console/knowledge/{$base->id}/documents", ['title' => 'Hours', 'text' => 'Nine to six.'])
            ->assertCreated()->assertJson(['state' => 'saving', 'problem' => null]);
        $this->assertSame([], Http::recorded()->all(), 'nothing sent while its base is not made');
        $this->assertSame(__('knowledge.will_retry'), KnowledgeDocument::query()->sole()->sync_error);
        $this->assertSame(__('knowledge.not_ready'), OutboxEntry::query()->sole()->last_error, 'waiting, and why');
    }

    public function test_the_feed_brings_whether_each_document_was_read_and_deletions_never_telenows_error_text(): void
    {
        $from = self::recordedQuery('listKnowledgeDocuments.feed')['updatedAfter'];
        $base = $this->base();
        $base->forceFill(['created_at' => Carbon::parse($from)])->save();
        $this->artisan('kit:knowledge')->assertSuccessful();
        [$feed] = $this->sent('GET', self::ORG.'/knowledge-bases/'.self::KB.'/documents');
        parse_str((string) parse_url($feed->url(), PHP_URL_QUERY), $q);
        $this->assertSame([[self::KEY], ['updatedAfter' => $from]], [$feed->header('X-API-Key'), $q]);
        $shown = $this->actingAs($this->ana)->getJson("/api/kit/console/knowledge/{$base->id}")->assertOk();
        $this->assertEqualsCanonicalizing([['Opening hours', 'failed', __('knowledge.not_read')], ['Parking', 'failed', __('knowledge.not_read')], ['prices', 'ready', null]],
            array_map(fn ($d) => [$d['title'], $d['state'], $d['problem']], $shown->json('documents')));
        $this->assertContainsOnly('int', array_column($shown->json('documents'), 'chars'));
        $this->assertSame(['total' => 3, 'ready' => 1, 'working' => 0, 'failed' => 2], $this->actingAs($this->ana)->getJson('/api/kit/console/knowledge')->json('knowledgeBases.0.documentCounts'));
        $this->assertStringNotContainsString('openai', $shown->getContent());
        $latest = max(array_column(RecordedTelenow::body('listKnowledgeDocuments.feed')['data']['documents'], 'updated_at'));
        $this->assertSame($latest, $base->fresh()->documents_after);
        $this->assertCount(1, $this->sent('GET', self::ORG.'/knowledge-bases'), "and the bases Telenow has: this one, kept");

        $this->artisan('kit:knowledge')->assertSuccessful();
        $this->assertCount(1, $this->sent('GET', self::ORG.'/knowledge-bases/'.self::KB.'/documents'), 'nothing still being read: not read again yet');
        KnowledgeDocument::query()->where('telenow_id', self::OPENING_HOURS)->update(['status' => 'embedding']);
        $this->artisan('kit:knowledge')->assertSuccessful();
        $this->assertCount(2, $this->sent('GET', self::ORG.'/knowledge-bases/'.self::KB.'/documents'), 'one still being read: read every run');
        $this->travel(config('kit.knowledge.feed_minutes'))->minutes();
        $this->answering(fn (Request $r) => self::is($r, 'GET', self::ORG.'/knowledge-bases/'.self::KB.'/documents')
            ? Http::response(RecordedTelenow::body('listKnowledgeDocuments.feed-deleted')) : null);
        $this->artisan('kit:knowledge')->assertSuccessful();
        $this->assertNotNull(KnowledgeDocument::query()->where('telenow_id', self::PARKING)->sole()->deleted_at, 'deleted at Telenow');
        $this->assertNotContains('Parking', array_column($this->actingAs($this->ana)->getJson("/api/kit/console/knowledge/{$base->id}")->json('documents'), 'title'));
    }

    public function test_the_feed_leaves_a_document_the_kit_is_changing_alone_and_a_base_gone_at_telenow_goes(): void
    {
        $base = $this->base();
        $base->forceFill(['created_at' => Carbon::parse(self::recordedQuery('listKnowledgeDocuments.feed')['updatedAfter'])])->save();
        $mine = $this->doc($base, self::OPENING_HOURS, 'Opening hours', 'embedding', ['sync_state' => 'pending']);
        $base->agents()->attach($this->agent->id);
        $this->answering(fn (Request $r) => self::is($r, 'GET', self::ORG.'/knowledge-bases') ? Http::response(['success' => true, 'data' => ['knowledgeBases' => [], 'total' => 0]]) : null);
        $this->artisan('kit:knowledge')->assertSuccessful();
        $this->assertSame(['embedding', 'pending'], [$mine->fresh()->status, $mine->fresh()->sync_state]);
        $this->assertNotNull($base->fresh()->deleted_at, 'Telenow no longer has it');
        $this->assertSame([], $base->agents()->pluck('agents.id')->all());
    }

    public function test_a_failed_document_is_tried_again_and_documents_and_bases_are_deleted(): void
    {
        $base = $this->base();
        $failed = $this->doc($base, self::OPENING_HOURS, 'Opening hours', 'failed');
        $ready = $this->doc($base, self::PRICES, 'prices', 'indexed');
        $retried = $this->actingAs($this->ana)->postJson("/api/kit/console/knowledge/{$base->id}/documents/{$failed->id}/retry")->assertOk();
        $this->assertCount(1, $this->sent('POST', self::ORG.'/knowledge-bases/'.self::KB.'/documents/'.self::OPENING_HOURS.'/retry'));
        $retried->assertJson(['state' => 'working', 'problem' => null]);
        $this->actingAs($this->ana)->postJson("/api/kit/console/knowledge/{$base->id}/documents/{$ready->id}/retry")->assertStatus(409)
            ->assertExactJson(['message' => __('knowledge.nothing_to_retry')]);

        $parking = $this->doc($base, self::PARKING, 'Parking', 'failed');
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/knowledge/{$base->id}/documents/{$parking->id}")->assertNoContent();
        $this->assertCount(1, $this->sent('DELETE', self::ORG.'/knowledge-bases/'.self::KB.'/documents/'.self::PARKING));
        $this->assertSame(['synced', true], [$parking->fresh()->sync_state, $parking->fresh()->deleted_at !== null]);
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/knowledge/{$base->id}/documents/{$parking->id}")->assertNotFound();

        $gone = RecordedTelenow::body('deleteKnowledgeDocument.gone');
        $this->answering(fn (Request $r) => $r->method() === 'DELETE' ? Http::response($gone, 404) : null);
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/knowledge/{$base->id}/documents/{$ready->id}")->assertNoContent();
        $this->assertSame(['synced', true], [$ready->fresh()->sync_state, $ready->fresh()->deleted_at !== null], 'Telenow no longer has it: as good as deleted');

        Http::swap(new Factory);
        RecordedTelenow::fake();
        $base->agents()->attach($this->agent->id);
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/knowledge/{$base->id}")->assertNoContent();
        $this->assertCount(1, $this->sent('DELETE', self::ORG.'/knowledge-bases/'.self::KB));
        $this->assertSame([], $this->agent->knowledgeBases()->pluck('knowledge_bases.id')->all(), 'its agents stop answering from it');
        $this->actingAs($this->ana)->getJson("/api/kit/console/knowledge/{$base->id}")->assertNotFound();

        $never = $this->base(['telenow_id' => null, 'sync_state' => 'failed']);
        (new OutboxEntry)->forceFill(['resource' => BaseWrite::RESOURCE, 'kit_id' => $never->id, 'operation' => 'create', 'body' => [], 'idempotency_key' => 'k', 'next_attempt_at' => now(), 'failed_at' => now()])->save();
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/knowledge/{$never->id}")->assertNoContent();
        $this->assertSame(__('knowledge.cancelled'), OutboxEntry::query()->where('kit_id', $never->id)->sole()->last_error);
        $this->assertCount(1, $this->sent('DELETE', self::ORG.'/knowledge-bases/'.self::KB), 'never made: Telenow not asked');
    }

    public function test_an_agent_answers_from_the_bases_chosen_all_at_once(): void
    {
        $base = $this->base();
        $set = fn (array $ids) => $this->actingAs($this->ana)->putJson("/api/kit/console/agents/{$this->agent->id}/knowledge", ['knowledgeBaseIds' => $ids]);
        $set([$base->id])->assertOk()->assertExactJson(['knowledgeBases' => [['id' => $base->id, 'name' => 'Clinic FAQ']], 'syncState' => 'synced', 'syncError' => null]);
        $path = self::ORG.'/agents/'.$this->agent->telenow_id.'/knowledge-bases';
        [$put] = $this->sent('PUT', $path);
        $this->assertSame([self::KEY], $put->header('X-API-Key'));
        $this->assertEquals(['knowledgeBases' => [['kbId' => self::KB]]], json_decode($put->body(), true));
        $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$this->agent->id}")->assertJsonPath('knowledgeBases', [['id' => $base->id, 'name' => 'Clinic FAQ']]);
        $this->actingAs($this->ana)->getJson('/api/kit/console/knowledge')->assertJsonPath('knowledgeBases.0.agents', [['id' => $this->agent->id, 'name' => 'Front desk']]);

        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = (new KnowledgeBase)->forceFill(['workspace_id' => $other->id, 'name' => 'Theirs', 'telenow_id' => 'x', 'sync_state' => 'synced']);
        $theirs->save();
        $deleted = $this->base(['telenow_id' => 'y', 'deleted_at' => now()]);
        foreach ([[$theirs->id], [$deleted->id], ['not-a-base']] as $bad) {
            $set($bad)->assertStatus(422)->assertJsonPath('errors.knowledgeBaseIds', [__('knowledge.unknown_base')]);
        }
        $this->assertCount(1, $this->sent('PUT', $path), 'nothing more sent');

        $set([])->assertOk()->assertJsonPath('knowledgeBases', []);
        $this->assertEquals(['knowledgeBases' => []], json_decode($this->sent('PUT', $path)[1]->body(), true), 'none: detached at Telenow');

        $unmade = $this->base(['telenow_id' => null, 'sync_state' => 'pending']);
        $set([$base->id, $unmade->id])->assertOk()->assertJsonPath('syncState', 'pending')->assertJsonPath('syncError', __('agents.will_retry'));
        $this->assertCount(2, $this->sent('PUT', $path), 'waits until every base is made');
        $this->assertSame(__('knowledge.not_ready'), OutboxEntry::query()->where('operation', 'knowledge')->whereNull('done_at')->sole()->last_error, 'and says why');
    }

    public function test_the_knowledge_list_says_what_can_be_uploaded(): void
    {
        $limits = $this->actingAs($this->ana)->getJson('/api/kit/console/knowledge')->assertOk()->json('limits');
        $this->assertSame(config('kit.knowledge.file_types'), $limits['fileTypes']);
        $this->assertLessThanOrEqual(config('kit.knowledge.file_max_bytes'), $limits['fileMaxBytes']);
        $this->assertGreaterThan(0, $limits['fileMaxBytes']);
        config(['kit.knowledge.file_max_bytes' => PHP_INT_MAX]);
        $this->assertLessThan(PHP_INT_MAX, $this->actingAs($this->ana)->getJson('/api/kit/console/knowledge')->json('limits.fileMaxBytes'), 'never more than this host lets PHP receive');
    }
}
