<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Kit\Settings\Settings;
use App\Models\OutgoingEmail;
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
 * Admin → Platform billing (App\Kit\Telenow\PlatformBilling; contract/kit-api.yaml getPlatformBilling, setPlatformBalanceAlert): the operator's own
 * account at the voice platform — its spend state, its low-balance level set at the platform, the platform's invoices — and the email to the staff
 * who handle money when it runs low or stops. Telenow answers as recorded on its live release (contract_k29billing.py).
 */
class PlatformBillingTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_operator_admin';

    private const ORG = '9ea0c695-16dc-5814-b6e1-cf1b3eb6381f';

    private User $owner;

    /** @var array<string, list<string|\Closure>> answers queued by "METHOD what" */
    private array $answers = [];

    /** @var list<array{method: string, path: string, body: mixed, key: ?string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $settings = app(Settings::class);
        $settings->put('telenow.api_key', self::KEY, secret: true);
        $settings->put('telenow.account', ['org_id' => self::ORG]);
        $this->owner = User::create(['name' => 'Olu', 'email' => 'olu@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'a long password 1', 'role' => 'admin']);
        User::create(['name' => 'Sam', 'email' => 'sam@example.com', 'password' => 'a long password 1', 'role' => 'support']);
        User::create(['name' => 'Phil', 'email' => null, 'phone' => '+14155550100', 'password' => 'a long password 1', 'role' => 'admin']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            $this->sent[] = ['method' => $r->method(), 'path' => $path, 'body' => $r->data(), 'key' => $r->header('X-API-Key')[0] ?? null];
            $what = $r->method().' '.basename($path);
            $answer = ($this->answers[$what] ?? []) !== [] ? array_shift($this->answers[$what]) : null;
            $answer ??= match ($what) {
                'PUT spend-alert' => 'setSpendAlert.below',
                'GET invoices' => 'listInvoices.postpaid',
                'GET summary' => 'getBillingSummary.operator-admin',
                default => throw new \RuntimeException("nothing recorded for {$what}"),
            };

            return $answer instanceof \Closure ? $answer($r) : RecordedTelenow::response($answer);
        });
    }

    private function emailsOn(): void
    {
        $senders = app(Senders::class);
        $senders->putSiteUrl('https://console.acme.example');
        $senders->putFrom('hello@acme.example', null);
        $senders->put([['id' => 'down', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none']]]);
    }

    /** The status job reading this recorded summary. */
    private function read(string $summary): void
    {
        $this->answers['GET summary'][] = $summary;
        $this->artisan('kit:telenow-status')->assertSuccessful();
    }

    /** @return list<array{kind: string, to: string}> */
    private function emails(): array
    {
        return OutgoingEmail::query()->orderBy('id')->get()->map(fn (OutgoingEmail $e) => ['kind' => $e->kind, 'to' => $e->to_address])->all();
    }

    public function test_the_page_shows_the_state_and_the_platforms_invoices_in_the_kits_shape(): void
    {
        $this->read('getBillingSummary.operator-admin');
        $page = $this->actingAs($this->owner)->getJson('/api/kit/admin/platform-billing')->assertOk();
        $page->assertJsonPath('state.state', 'ok')->assertJsonPath('state.balanceUsd', '40.0000000000')->assertJsonPath('state.lowThresholdUsd', null)
            ->assertJsonPath('invoicesError', null);
        $this->assertNotNull($page->json('checkedAt'));
        [$draft, $issued, $paid] = $page->json('invoices');
        // Newest period first, whatever order the platform gives.
        $recorded = RecordedTelenow::body('listInvoices.postpaid')['data'];
        $this->answers['GET invoices'][] = fn () => Http::response(['success' => true, 'data' => array_reverse($recorded)]);
        $this->assertSame(['2026-11-01', '2026-10-01', '2026-09-01'], array_column($this->actingAs($this->owner)->getJson('/api/kit/admin/platform-billing')->json('invoices'), 'periodStart'));
        $this->assertSame(['periodStart' => '2026-10-01', 'periodEnd' => '2026-10-31', 'status' => 'issued', 'issuedAt' => '2026-11-03T18:27:27.705875Z',
            'dueAt' => '2026-11-10T18:27:27.705875Z', 'paidAt' => null, 'currency' => 'INR', 'total' => '1469.10', 'totalUsd' => '17.70'],
            array_intersect_key($issued, array_flip(['periodStart', 'periodEnd', 'status', 'issuedAt', 'dueAt', 'paidAt', 'currency', 'total', 'totalUsd'])));
        $this->assertSame([['kind' => 'chat', 'description' => 'Chat sessions', 'amount' => '83.00'], ['kind' => 'platform_fee', 'description' => 'Platform fee', 'amount' => '186.75'],
            ['kind' => 'number_rental', 'description' => 'Phone number rentals', 'amount' => '435.75'], ['kind' => 'agent_calls', 'description' => 'AI agent calls', 'amount' => '539.50']],
            $issued['lines']);
        $this->assertSame('224.10', $issued['rest'], "the total's tax: 1469.10 less its lines' 1245.00");
        $this->assertSame(['draft', null, '249.00'], [$draft['status'], $draft['rest'], $draft['total']]);
        $this->assertSame(['paid', null, '2026-10-09T18:27:27.693882Z'], [$paid['status'], $paid['rest'], $paid['paidAt']]);
        $asked = array_values(array_filter($this->sent, fn ($s) => str_ends_with($s['path'], '/invoices')));
        $this->assertSame(['GET', '/api/orgs/'.self::ORG.'/billing/invoices', self::KEY], [$asked[0]['method'], $asked[0]['path'], $asked[0]['key']]);
        // A prepaid account gets none; the platform out of reach leaves the rest of the page.
        $this->answers['GET invoices'][] = 'listInvoices.prepaid';
        $this->actingAs($this->owner)->getJson('/api/kit/admin/platform-billing')->assertOk()->assertJsonPath('invoices', []);
        $this->answers['GET invoices'][] = fn () => throw new ConnectionException('down');
        $this->actingAs($this->owner)->getJson('/api/kit/admin/platform-billing')->assertOk()->assertJsonPath('invoices', null)
            ->assertJsonPath('invoicesError', __('platform_billing.unavailable'))->assertJsonPath('state.state', 'ok');
        // An unknown status reads as `other`.
        $this->answers['GET invoices'][] = fn () => Http::response(['success' => true, 'data' => [['invoice' => array_merge(RecordedTelenow::body('listInvoices.postpaid')['data'][1]['invoice'], ['status' => 'disputed']), 'lines' => []]]]);
        $this->actingAs($this->owner)->getJson('/api/kit/admin/platform-billing')->assertJsonPath('invoices.0.status', 'other');
        // Lines that make the whole total: no tax line.
        $issued = RecordedTelenow::body('listInvoices.postpaid')['data'][1];
        $this->answers['GET invoices'][] = fn () => Http::response(['success' => true, 'data' => [['invoice' => array_merge($issued['invoice'], ['totalBilled' => '1245.00']), 'lines' => $issued['lines']]]]);
        $this->actingAs($this->owner)->getJson('/api/kit/admin/platform-billing')->assertJsonPath('invoices.0.rest', null)->assertJsonPath('invoices.0.total', '1245.00');
    }

    public function test_the_low_balance_level_is_set_at_the_platform_in_usd(): void
    {
        $this->actingAs($this->owner)->putJson('/api/kit/admin/platform-billing/alert', ['thresholdUsd' => '25'])->assertOk()
            ->assertJsonPath('state.lowThresholdUsd', '25.0000000000')->assertJsonPath('state.state', 'ok');
        $put = array_values(array_filter($this->sent, fn ($s) => $s['method'] === 'PUT'));
        $this->assertSame(['/api/orgs/'.self::ORG.'/billing/spend-alert', self::KEY], [$put[0]['path'], $put[0]['key']]);
        $this->assertEquals(['thresholdUsd' => 25], $put[0]['body'], 'a JSON number');
        $this->assertIsNotString($put[0]['body']['thresholdUsd']);
        $this->assertSame('25.0000000000', app(Settings::class)->get('telenow.billing')['lowThresholdUsd'], 'kept for the page at once');
        $this->travelTo(now()->addMinutes(3));
        $this->actingAs($this->owner)->putJson('/api/kit/admin/platform-billing/alert', ['thresholdUsd' => '25'])->assertOk()
            ->assertJsonPath('checkedAt', now()->toIso8601String());
        $this->travelBack();
        $this->answers['PUT spend-alert'][] = 'setSpendAlert.cents';
        $this->actingAs($this->owner)->putJson('/api/kit/admin/platform-billing/alert', ['thresholdUsd' => '12.35'])->assertOk();
        $this->assertEquals(['thresholdUsd' => 12.35], array_slice($this->sent, -1)[0]['body']);
        foreach ([null, ''] as $none) {
            $this->answers['PUT spend-alert'][] = 'setSpendAlert.cleared';
            $this->actingAs($this->owner)->putJson('/api/kit/admin/platform-billing/alert', ['thresholdUsd' => $none])->assertOk()->assertJsonPath('state.lowThresholdUsd', null);
            $this->assertSame(['thresholdUsd' => null], array_slice($this->sent, -1)[0]['body']);
        }
        $count = count($this->sent);
        foreach (['-1', '1.234', 'ten', '12345678', '01', 25] as $bad) {
            $this->actingAs($this->owner)->putJson('/api/kit/admin/platform-billing/alert', ['thresholdUsd' => $bad])->assertStatus(422)
                ->assertJsonPath('errors.thresholdUsd.0', __('platform_billing.alert_shape', ['digits' => 7]));
        }
        $this->actingAs($this->owner)->putJson('/api/kit/admin/platform-billing/alert', [])->assertStatus(422);
        $this->assertCount($count, $this->sent, 'refused before the platform');
        $this->actingAs($this->owner)->putJson('/api/kit/admin/platform-billing/alert', ['thresholdUsd' => '9999999.99'])->assertOk();
        // The platform's refusals in the kit's words.
        $this->answers['PUT spend-alert'][] = 'setSpendAlert.negative';
        $this->actingAs($this->owner)->putJson('/api/kit/admin/platform-billing/alert', ['thresholdUsd' => '5'])->assertStatus(422)
            ->assertJsonPath('errors.thresholdUsd.0', __('platform_billing.alert_refused'));
        $this->answers['PUT spend-alert'][] = fn () => throw new ConnectionException('down');
        $this->actingAs($this->owner)->putJson('/api/kit/admin/platform-billing/alert', ['thresholdUsd' => '5'])->assertStatus(503)
            ->assertExactJson(['message' => __('platform_billing.unavailable')]);
        app(Settings::class)->forget('telenow.api_key');
        $this->actingAs($this->owner)->putJson('/api/kit/admin/platform-billing/alert', ['thresholdUsd' => '5'])->assertStatus(409)
            ->assertExactJson(['message' => __('platform_billing.not_connected')]);
    }

    public function test_the_staff_who_handle_money_are_emailed_once_when_it_runs_low_and_when_it_stops(): void
    {
        $this->emailsOn();
        // The first state ever read sends nothing, even a stopped one.
        $this->read('getBillingSummary.operator-stopped-alert');
        $this->assertSame([], $this->emails());
        $this->read('getBillingSummary.operator-admin');
        $this->read('getBillingSummary.operator-low');
        $this->assertSame([['kind' => 'platform_balance_low', 'to' => 'olu@example.com'], ['kind' => 'platform_balance_low', 'to' => 'ada@example.com']], $this->emails(),
            'owner and admin (money); not support, not one with no email');
        $text = OutgoingEmail::query()->first()->body['text'];
        $this->assertStringContainsString('40.00 USD', $text);
        $this->assertStringContainsString('100.00 USD', $text);
        $this->assertStringContainsString('https://console.acme.example/admin/platform-billing', $text);
        $this->read('getBillingSummary.operator-low');
        $this->assertCount(2, $this->emails(), 'once per change');
        $this->read('getBillingSummary.operator-stopped-alert');
        $this->assertSame(['platform_stopped', 'platform_stopped'], array_column(array_slice($this->emails(), 2), 'kind'));
        $this->assertStringContainsString(__('platform_billing.stopped_by.out_of_credit'), OutgoingEmail::query()->orderByDesc('id')->first()->body['text']);
        $this->read('getBillingSummary.operator-stopped-alert');
        $this->read('getBillingSummary.operator-admin');
        $this->assertCount(4, $this->emails(), 'nothing for the same state, nor for recovering');
        $this->read('getBillingSummary.operator-stopped-alert');
        $this->assertCount(6, $this->emails(), 'stopping straight from ok');
        $this->read('getBillingSummary.operator-low');
        $this->assertCount(6, $this->emails(), 'low after stopped is no worse');
        // Setting a level already passed shows at once and sends nothing.
        $this->read('getBillingSummary.operator-admin');
        $this->answers['PUT spend-alert'][] = 'setSpendAlert.low';
        $this->actingAs($this->owner)->putJson('/api/kit/admin/platform-billing/alert', ['thresholdUsd' => '40'])->assertOk()->assertJsonPath('state.state', 'low');
        $this->read('getBillingSummary.operator-low');
        $this->assertCount(6, $this->emails());
    }

    public function test_no_email_while_the_platform_cannot_send_one(): void
    {
        $this->read('getBillingSummary.operator-admin');
        $this->read('getBillingSummary.operator-low');
        $this->assertSame([], $this->emails());
        $this->assertSame('low', app(Settings::class)->get('telenow.billing')['state']);
    }

    public function test_platform_billing_is_for_the_staff_who_handle_money(): void
    {
        foreach (['sam@example.com'] as $email) {
            $who = User::query()->where('email', $email)->sole();
            $this->actingAs($who)->getJson('/api/kit/admin/platform-billing')->assertForbidden();
            $this->actingAs($who)->putJson('/api/kit/admin/platform-billing/alert', ['thresholdUsd' => '5'])->assertForbidden();
        }
        $customer = User::create(['name' => 'Cy', 'email' => 'cy@example.com', 'password' => 'a long password 1']);
        $w = Workspace::create(['name' => 'Clinic Co', 'status' => 'active', 'sync_state' => 'synced']);
        WorkspaceMember::create(['workspace_id' => $w->id, 'user_id' => $customer->id, 'role' => 'owner']);
        $this->actingAs($customer)->getJson('/api/kit/admin/platform-billing')->assertForbidden();
        $this->assertSame([], $this->sent);
        $this->actingAs(User::query()->where('email', 'ada@example.com')->sole())->getJson('/api/kit/admin/platform-billing')->assertOk();
    }
}
