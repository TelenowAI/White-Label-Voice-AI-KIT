<?php

namespace Tests\Feature\Kit;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Knowledge tables (App\Kit\Knowledge\KnowledgeTables; contract/kit-api.yaml getKnowledgeTable, setKnowledgeColumnTypes): a CSV, JSON or Excel
 * document as the agents use it — its columns, how each is read, a page of its rows, in the prompt whole or looked up — read live with the account's
 * own key and named in the kit's terms (never Telenow's ids); changing how columns are read, the table read first so a column it does not have is
 * refused before anything is sent. Telenow answers as recorded on its live release (contract_k19tables.py).
 */
class KnowledgeTableTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const ORG = 'ad21b3f8-2e31-596b-bb62-8eb18ba65dea';

    private const KB = '52610ecc-2139-5dd0-ab6d-89e27aea9a45';

    private const CSV = '418b9592-3400-5ae7-a6ee-642df050a70e';

    private const JSON = '9539ce10-0234-5cd8-b509-6621fb595570';

    private const BIG = 'bf13c7a6-ac4d-506b-92c0-6f8e23123f63';

    private User $ana;

    private Workspace $acme;

    private KnowledgeBase $base;

    /** @var list<array{method: string, path: string, query: string, body: mixed, key: ?string}> */
    private array $sent = [];

    /** What Telenow answers next, by what is asked: recordings' names or closures. */
    private array $answers = ['dataset' => 'getKnowledgeTable.csv', 'rows' => 'listKnowledgeTableRows.csv', 'patch' => 'setKnowledgeColumnTypes.changed'];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => self::ORG, 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        $this->base = (new KnowledgeBase)->forceFill(['workspace_id' => $this->acme->id, 'name' => 'Price list', 'telenow_id' => self::KB, 'sync_state' => 'synced']);
        $this->base->save();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            $this->sent[] = ['method' => $r->method(), 'path' => $path, 'query' => (string) parse_url($r->url(), PHP_URL_QUERY),
                'body' => $r->method() === 'PATCH' ? json_decode($r->body(), true) : null, 'key' => $r->header('X-API-Key')[0] ?? null];
            $next = $this->answers[$r->method() === 'PATCH' ? 'patch' : (str_ends_with($path, '/rows') ? 'rows' : 'dataset')];

            return $next instanceof \Closure ? $next() : RecordedTelenow::response($next);
        });
    }

    private function document(string $file, ?string $telenowId, string $status = 'indexed', string $sync = 'synced', string $type = 'upload'): KnowledgeDocument
    {
        $doc = (new KnowledgeDocument)->forceFill(['knowledge_base_id' => $this->base->id, 'workspace_id' => $this->acme->id, 'title' => pathinfo($file, PATHINFO_FILENAME),
            'source_type' => $type, 'source_uri' => $file, 'status' => $status, 'telenow_id' => $telenowId, 'sync_state' => $sync]);
        $doc->save();

        return $doc;
    }

    private function url(KnowledgeDocument $doc, string $query = ''): string
    {
        return "/api/kit/console/knowledge/{$this->base->id}/documents/{$doc->id}/table".($query === '' ? '' : "?{$query}");
    }

    private static function at(string $doc, string $tail): string
    {
        return '/api/orgs/'.self::ORG.'/knowledge-bases/'.self::KB.'/documents/'.$doc.'/'.$tail;
    }

    public function test_a_table_document_as_the_agents_use_it(): void
    {
        $csv = $this->document('prices.csv', self::CSV);
        $this->document('Hours', null, 'failed', 'synced', 'text');
        $docs = array_column($this->actingAs($this->ana)->getJson("/api/kit/console/knowledge/{$this->base->id}")->assertOk()->json('documents'), 'table', 'title');
        $this->assertSame(['prices' => true, 'Hours' => false], ['prices' => $docs['prices'], 'Hours' => $docs['Hours']]);

        $got = $this->actingAs($this->ana)->getJson($this->url($csv))->assertOk();
        $this->assertSame([
            ['key' => 'item', 'name' => 'Item', 'type' => 'text'], ['key' => 'price', 'name' => 'Price (₹)', 'type' => 'text'],
            ['key' => 'in_stock', 'name' => 'In stock', 'type' => 'text'], ['key' => 'updated', 'name' => 'Updated', 'type' => 'date'],
            ['key' => 'sku', 'name' => 'SKU', 'type' => 'text'], ['key' => 'notes', 'name' => 'Notes', 'type' => 'text'],
        ], $got->json('columns'));
        $this->assertSame(['Cleaning', '1200', 'yes', '2026-11-01', '00123', 'Twice a year'], $got->json('rows.0'), 'cells in the order of the columns, as the file had them');
        $this->assertSame(['Filling', 'N/A', 'yes', '2026-09-30', '00125', 'Price on inspection, varies'], $got->json('rows.2'));
        $this->assertSame(['rowCount' => 4, 'offset' => 0, 'limit' => 50, 'format' => 'csv', 'inPrompt' => true, 'searchByMeaningRows' => null],
            array_intersect_key($got->json(), array_flip(['rowCount', 'offset', 'limit', 'format', 'inPrompt', 'searchByMeaningRows'])));
        $this->assertSame([['GET', self::at(self::CSV, 'dataset'), '', self::KEY], ['GET', self::at(self::CSV, 'rows'), 'limit=50&offset=0', self::KEY]],
            array_map(fn ($s) => [$s['method'], $s['path'], $s['query'], $s['key']], $this->sent));
        foreach ([self::ORG, self::KB, self::CSV, 'sql_name', 'inlined', 'fuzzy'] as $theirs) {
            $this->assertStringNotContainsString($theirs, $got->getContent());
        }
    }

    public function test_cells_of_every_kind_and_a_big_table_a_page_at_a_time(): void
    {
        $this->answers = ['dataset' => 'getKnowledgeTable.json', 'rows' => 'listKnowledgeTableRows.json'] + $this->answers;
        $got = $this->actingAs($this->ana)->getJson($this->url($this->document('doctors.json', self::JSON)))->assertOk();
        $this->assertSame(['hours', 'name', 'room', 'weekends'], array_column($got->json('columns'), 'key'));
        $this->assertSame([['{"mon":"9-5"}', 'Dr. Rao', 2, true], [null, 'Dr. Iyer', 3, false], [null, 'Dr. Shah', '4B', true]], $got->json('rows'),
            'a nested value as its JSON text, numbers and true/false as they are, a missing one as null');
        // Telenow sends a nested value as text (recorded); were it to send one as it is, it is still one cell of text, as the contract says.
        $rows = RecordedTelenow::body('listKnowledgeTableRows.json');
        $rows['data']['rows'][0]['hours'] = ['mon' => '9-5', 'days' => ['Sat', 'Sun']];
        $this->answers['rows'] = fn () => Http::response($rows);
        $this->assertSame('{"mon":"9-5","days":["Sat","Sun"]}', $this->actingAs($this->ana)->getJson($this->url(KnowledgeDocument::query()->where('title', 'doctors')->firstOrFail()))->assertOk()->json('rows.0.0'));

        $big = $this->document('orders.csv', self::BIG);
        $this->answers = ['dataset' => 'getKnowledgeTable.big', 'rows' => 'listKnowledgeTableRows.big-page'] + $this->answers;
        $this->sent = [];
        $got = $this->actingAs($this->ana)->getJson($this->url($big, 'offset=10040&limit=3'))->assertOk();
        $this->assertSame('limit=3&offset=10040', $this->sent[1]['query']);
        $this->assertSame([[11040, 'Customer 10040', 680.5], [11041, 'Customer 10041', 717.5], [11042, 'Customer 10042', 754.5]], $got->json('rows'));
        $this->assertSame(['rowCount' => 10050, 'offset' => 10040, 'limit' => 3, 'inPrompt' => false, 'searchByMeaningRows' => 10000],
            array_intersect_key($got->json(), array_flip(['rowCount', 'offset', 'limit', 'inPrompt', 'searchByMeaningRows'])));
        $this->answers['rows'] = 'listKnowledgeTableRows.past-end';
        $this->assertSame([], $this->actingAs($this->ana)->getJson($this->url($big, 'offset=20000&limit=3'))->assertOk()->json('rows'));
        // Telenow always says the page and the kind (recorded, and its document requires them); were it not to, they are still the asked page and the file's own kind.
        $table = RecordedTelenow::body('getKnowledgeTable.big');
        $page = RecordedTelenow::body('listKnowledgeTableRows.big-page');
        unset($table['data']['format'], $page['data']['offset'], $page['data']['limit']);
        $this->answers = ['dataset' => fn () => Http::response($table), 'rows' => fn () => Http::response($page)] + $this->answers;
        $this->assertSame(['offset' => 7, 'limit' => 9, 'format' => 'csv'],
            array_intersect_key($this->actingAs($this->ana)->getJson($this->url($big, 'offset=7&limit=9'))->assertOk()->json(), array_flip(['offset', 'limit', 'format'])));

        $this->sent = [];
        foreach (['limit=201', 'limit=0', 'offset=-1', 'limit=lots'] as $bad) {
            $this->actingAs($this->ana)->getJson($this->url($big, $bad))->assertStatus(422);
        }
        $this->assertSame([], $this->sent, 'nothing sent for a page that is not one');
    }

    public function test_changing_how_columns_are_read(): void
    {
        $csv = $this->document('prices.csv', self::CSV);
        $this->answers['dataset'] = 'getKnowledgeTable.csv';
        $got = $this->actingAs($this->ana)->patchJson($this->url($csv), ['types' => ['in_stock' => 'bool', 'price' => 'number']])->assertOk();
        $this->assertSame(['text', 'number', 'bool', 'date', 'text', 'text'], array_column($got->json('columns'), 'type'));
        $this->assertSame(['key' => 'price', 'name' => 'Price (₹)', 'type' => 'number'], $got->json('columns.1'));
        $patch = end($this->sent);
        $this->assertSame(['PATCH', self::at(self::CSV, 'dataset'), ['types' => ['in_stock' => 'bool', 'price' => 'number']], self::KEY],
            [$patch['method'], $patch['path'], $patch['body'], $patch['key']]);
        $this->assertSame('GET', $this->sent[0]['method'], 'the table read first');

        $this->sent = [];
        $this->actingAs($this->ana)->patchJson($this->url($csv), ['types' => ['colour' => 'text']])->assertStatus(422)
            ->assertJsonPath('errors.types.0', __('knowledge.table_column_unknown'));
        $this->assertSame(['GET'], array_column($this->sent, 'method'), 'a column the table does not have: nothing changed');
        $this->sent = [];
        foreach ([['types' => ['price' => 'money']], ['types' => []], ['types' => 'number'], [], ['types' => ['price' => null]]] as $bad) {
            $this->actingAs($this->ana)->patchJson($this->url($csv), $bad)->assertStatus(422)->assertJsonValidationErrors(['types']);
        }
        $this->assertSame([], $this->sent, 'nothing sent for types that are not');

        $this->answers['patch'] = 'setKnowledgeColumnTypes.unknown-column';
        $this->actingAs($this->ana)->patchJson($this->url($csv), ['types' => ['sku' => 'text']])->assertStatus(422);
        $this->answers['patch'] = 'setKnowledgeColumnTypes.text-document';
        $this->actingAs($this->ana)->patchJson($this->url($csv), ['types' => ['sku' => 'text']])->assertNotFound()->assertExactJson(['message' => __('knowledge.not_a_table')]);
        $this->answers['patch'] = fn () => throw new ConnectionException('unreachable');
        $this->actingAs($this->ana)->patchJson($this->url($csv), ['types' => ['sku' => 'text']])->assertStatus(503)->assertExactJson(['message' => __('knowledge.table_not_saved')]);
        $this->answers['dataset'] = fn () => throw new ConnectionException('unreachable');
        $this->sent = [];
        $this->actingAs($this->ana)->patchJson($this->url($csv), ['types' => ['sku' => 'text']])->assertStatus(503)->assertExactJson(['message' => __('knowledge.table_unavailable')]);
        $this->assertSame(['GET'], array_column($this->sent, 'method'), 'nothing changed when the table cannot be read');
    }

    public function test_documents_that_are_not_tables_not_ready_or_not_reachable(): void
    {
        $text = $this->document('Hours', 'aaaaaaaa-0000-4000-8000-000000000001', 'indexed', 'synced', 'text');
        $pdf = $this->document('menu.pdf', 'aaaaaaaa-0000-4000-8000-000000000002', 'embedded');
        foreach ([$text, $pdf] as $doc) {
            $this->actingAs($this->ana)->getJson($this->url($doc))->assertNotFound()->assertExactJson(['message' => __('knowledge.not_a_table')]);
            $this->actingAs($this->ana)->patchJson($this->url($doc), ['types' => ['a' => 'text']])->assertNotFound();
        }
        foreach ([$this->document('a.csv', null, 'pending', 'pending'), $this->document('b.xlsx', 'aaaaaaaa-0000-4000-8000-000000000003', 'pending'),
            $this->document('c.csv', 'aaaaaaaa-0000-4000-8000-000000000004', 'indexed', 'failed')] as $doc) {
            $this->actingAs($this->ana)->getJson($this->url($doc))->assertStatus(409)->assertExactJson(['message' => __('knowledge.table_not_ready')]);
        }
        $this->assertSame([], $this->sent);

        $csv = $this->document('prices.CSV', self::CSV);
        $this->answers['dataset'] = 'getKnowledgeTable.text';
        $this->actingAs($this->ana)->getJson($this->url($csv))->assertNotFound()->assertExactJson(['message' => __('knowledge.not_a_table')]);
        $this->answers = ['dataset' => 'getKnowledgeTable.csv', 'rows' => 'listKnowledgeTableRows.text'] + $this->answers;
        $this->actingAs($this->ana)->getJson($this->url($csv))->assertNotFound();
        $this->answers['dataset'] = 'getKnowledgeTable.not-this-account';
        $this->actingAs($this->ana)->getJson($this->url($csv))->assertStatus(503)->assertExactJson(['message' => __('knowledge.table_unavailable')]);
        $this->answers = ['dataset' => 'getKnowledgeTable.csv', 'rows' => fn () => throw new ConnectionException('unreachable')] + $this->answers;
        $this->actingAs($this->ana)->getJson($this->url($csv))->assertStatus(503);

        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        WorkspaceMember::create(['workspace_id' => $other->id, 'user_id' => $ben->id, 'role' => 'owner']);
        $this->actingAs($ben)->getJson($this->url($csv))->assertNotFound()->assertExactJson(['message' => __('knowledge.not_found')]);
        $this->acme->forceFill(['telenow_key' => null])->save();
        $this->actingAs($this->ana)->getJson($this->url($csv))->assertStatus(409);
    }
}
