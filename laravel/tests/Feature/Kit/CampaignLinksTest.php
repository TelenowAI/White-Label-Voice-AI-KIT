<?php

namespace Tests\Feature\Kit;

use App\Kit\Campaigns\CampaignSources;
use App\Kit\Net\HostResolver;
use App\Kit\Net\PublicLinks;
use App\Kit\Settings\Settings;
use App\Models\Agent;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\CampaignTarget;
use App\Models\PhoneNumber;
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
 * A campaign's people from a link (App\Kit\Campaigns\CampaignSources, App\Kit\Net\PublicLinks; contract/kit-api.yaml setCampaignSource,
 * removeCampaignSource): off until the operator allows it; the link read now and again when due, only new numbers added (by their digits), no one taken
 * off, through the same parse, insert and push as a CSV upload; and read only when every step is public — https, every address the name resolves to,
 * each redirect, the connection pinned to the address checked — with size and kind limits. Telenow's push answers as recorded
 * (contract_k1campaigns.py); the links' hosts are stood in for by a resolver and Http fakes.
 */
class CampaignLinksTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const CAMPAIGN = 'c0ffee00-1a2b-4c3d-8e4f-5a6b7c8d9e0f';

    private const SHEET = 'https://sheets.example.com/d/e/abc/pub?output=csv';

    private User $ana;

    private Workspace $acme;

    private Campaign $campaign;

    /** @var array<string, list<string>> each host's addresses, as the stand-in resolver answers them */
    private array $dns = ['sheets.example.com' => ['93.184.216.34'], 'cdn.example.net' => ['151.101.1.1']];

    /** @var array<string, \Closure|array{0: int, 1: string, 2?: array}> what each link answers, by its whole address */
    private array $links = [];

    /** @var list<array{url: string, resolve: mixed}> */
    private array $read = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        $agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Recall caller']);
        $agent->forceFill(['telenow_id' => RecordedTelenow::body('createAgent.customer')['data']['id'], 'sync_state' => 'synced'])->save();
        $number = (new PhoneNumber)->forceFill(['workspace_id' => $this->acme->id, 'e164' => '918045671001', 'telenow_id' => '9c4d1e2f-3a4b-4c5d-8e6f-7a8b9c0d1e2f', 'sync_state' => 'synced']);
        $number->save();
        $this->campaign = (new Campaign)->forceFill(['workspace_id' => $this->acme->id, 'agent_id' => $agent->id, 'phone_number_id' => $number->id,
            'name' => 'Recall reminders', 'concurrency' => 2, 'timezone' => 'UTC', 'machine_detection' => 'voicemail', 'max_attempts' => 1,
            'retry_on_no_answer' => false, 'status' => 'running', 'telenow_id' => self::CAMPAIGN, 'sync_state' => 'synced']);
        $this->campaign->save();
        app(CampaignSources::class)->allow(true);
        $this->app->instance(HostResolver::class, new class($this) extends HostResolver
        {
            public function __construct(private readonly CampaignLinksTest $test) {}

            public function resolve(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->test->addressesOf($host);
            }
        });
        $this->links[self::SHEET] = [200, "phone_number,first_name\n+14155550101,Ana\n+14155550102,Ben\n", ['Content-Type' => 'text/csv; charset=utf-8']];
        RecordedTelenow::fake();
        $recorded = RecordedTelenow::responder();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r, array $options) use ($recorded) {
            if (str_starts_with($r->url(), config('kit.telenow.base_url'))) {
                return $recorded($r);
            }
            $this->read[] = ['url' => $r->url(), 'resolve' => $options['curl'][CURLOPT_RESOLVE] ?? null, 'redirects' => $options['allow_redirects'] ?? null, 'stream' => $options['stream'] ?? false];
            $answer = $this->links[$r->url()] ?? [404, 'not found', []];
            if ($answer instanceof \Closure) {
                return $answer();
            }

            return Http::response($answer[1], $answer[0], $answer[2] ?? []);
        });
    }

    /** @return list<string> */
    public function addressesOf(string $host): array
    {
        return $this->dns[$host] ?? [];
    }

    private function follow(string $url): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->ana)->putJson("/api/kit/console/campaigns/{$this->campaign->id}/source", ['url' => $url]);
    }

    /** @return list<array{0: string, 1: string}> the people the campaign has, by number and name */
    private function people(): array
    {
        return CampaignTarget::query()->where('campaign_id', $this->campaign->id)->orderBy('phone_number')->get()
            ->map(fn (CampaignTarget $t) => [$t->phone_number, (is_array($t->variables) ? $t->variables : json_decode((string) $t->variables, true))['first_name'] ?? null])->all();
    }

    public function test_people_from_a_link_now_and_again_only_the_new_ones_never_anyone_taken_off(): void
    {
        $got = $this->follow(self::SHEET)->assertOk();
        $this->assertSame(['added' => 2, 'alreadyThere' => 0, 'skipped' => 0, 'noCountryCode' => 0], $got->json('added'));
        $this->assertSame(['url' => self::SHEET, 'problem' => null, 'added' => 2], array_intersect_key($got->json('campaign.source'), array_flip(['url', 'problem', 'added'])));
        $this->assertSame([['+14155550101', 'Ana'], ['+14155550102', 'Ben']], $this->people());
        $this->assertSame([self::SHEET, ['sheets.example.com:443:93.184.216.34'], false, false], [$this->read[0]['url'], $this->read[0]['resolve'], $this->read[0]['redirects'], $this->read[0]['stream']],
            'pinned to the address checked, redirects followed by hand, never streamed (Guzzle\'s stream handler cannot pin: found on the real network)');
        $this->assertNotEmpty(Http::recorded(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/v1/campaigns/'.self::CAMPAIGN.'/targets')), 'pushed as an upload is');

        // Not due yet: nothing read. Due: Ben again (written another way), Cleo new, Ana gone from the sheet but kept.
        $this->links[self::SHEET] = [200, "phone_number,first_name\n+1 (415) 555-0102,Ben\n+14155550103,Cleo\n,Nobody\n", ['Content-Type' => 'text/csv']];
        $this->read = [];
        $this->artisan('kit:campaign-links')->assertSuccessful();
        $this->assertSame([], $this->read, 'not due');
        $this->travel(config('kit.campaigns.link_every_minutes') + 1)->minutes();
        $this->artisan('kit:campaign-links')->assertSuccessful();
        $this->assertSame([['+14155550101', 'Ana'], ['+14155550102', 'Ben'], ['+14155550103', 'Cleo']], $this->people());
        $this->assertSame([3, null], [$this->campaign->fresh()->source_added, $this->campaign->fresh()->source_problem]);

        // A link that cannot be read says so on its campaign; the operator turning links off stops every read; a finished campaign is not read.
        $this->links[self::SHEET] = [403, 'denied', []];
        $this->travel(config('kit.campaigns.link_every_minutes') + 1)->minutes();
        $this->artisan('kit:campaign-links')->assertSuccessful();
        $this->assertSame(__('links.status', ['status' => 403]), $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$this->campaign->id}")->json('source.problem'));
        $this->read = [];
        app(CampaignSources::class)->allow(false);
        $this->travel(config('kit.campaigns.link_every_minutes') + 1)->minutes();
        $this->artisan('kit:campaign-links')->assertSuccessful();
        app(CampaignSources::class)->allow(true);
        $this->campaign->forceFill(['status' => 'completed'])->save();
        $this->artisan('kit:campaign-links')->assertSuccessful();
        $this->assertSame([], $this->read, 'off, then finished: nothing read');

        $this->campaign->forceFill(['status' => 'running'])->save();
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/campaigns/{$this->campaign->id}/source")->assertNoContent();
        $this->assertNull($this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$this->campaign->id}")->json('source'));
        $this->assertCount(3, $this->people(), 'the people it added stay');
    }

    public function test_only_public_https_links_every_step_checked(): void
    {
        $refused = fn (string $url, string $reason) => $this->follow($url)->assertStatus(422)->assertJsonPath('errors.url.0', __("links.{$reason}", ['max' => 2, 'status' => 0]));
        $refused('http://sheets.example.com/x.csv', 'https');
        $refused('https://user:pass@sheets.example.com/x.csv', 'shape');
        $refused('https://127.0.0.1/x.csv', 'private');
        $refused('https://sheets.example.com:8443/x.csv', 'port');
        $refused('https://nowhere.example.org/x.csv', 'unresolved');
        $this->assertSame([], $this->read, 'nothing read for any of those');

        // A name that resolves to a private address — or to several, one of them private — is refused before anything is sent.
        foreach ([['10.0.0.5'], ['93.184.216.34', '169.254.169.254'], ['::ffff:127.0.0.1'], ['100.64.0.9'], ['fd00::1']] as $addresses) {
            $this->dns['inside.example.com'] = $addresses;
            $refused('https://inside.example.com/x.csv', 'private');
        }
        $this->assertSame([], $this->read);

        // Each redirect is a new link, checked again: to a private address is refused; to a public one is followed; too many are refused.
        $this->links['https://sheets.example.com/moved'] = [302, '', ['Location' => 'https://inside.example.com/x.csv']];
        $this->dns['inside.example.com'] = ['192.168.1.10'];
        $refused('https://sheets.example.com/moved', 'private');
        $this->links['https://sheets.example.com/cdn'] = [302, '', ['Location' => 'https://cdn.example.net/file.csv']];
        $this->links['https://cdn.example.net/file.csv'] = [200, "phone,first_name\n+14155550199,Dee\n", ['Content-Type' => 'text/csv']];
        $this->follow('https://sheets.example.com/cdn')->assertOk()->assertJsonPath('added.added', 1);
        $this->assertSame(['cdn.example.net:443:151.101.1.1'], end($this->read)['resolve'], 'the redirect pinned to its own checked address');
        // One of a name's addresses cannot be reached: the next checked one is tried, pinned the same way; none reachable is said.
        $this->dns['multi.example.com'] = ['203.0.114.1', '151.101.1.2'];
        $this->links['https://multi.example.com/people.csv'] = function () {
            if ((end($this->read)['resolve'][0] ?? '') === 'multi.example.com:443:203.0.114.1') {
                throw new ConnectionException('timed out');
            }

            return Http::response("phone\n+14155550177\n", 200, ['Content-Type' => 'text/csv']);
        };
        $this->follow('https://multi.example.com/people.csv')->assertOk()->assertJsonPath('added.added', 1);
        $this->assertSame([['multi.example.com:443:203.0.114.1'], ['multi.example.com:443:151.101.1.2']], array_slice(array_column($this->read, 'resolve'), -2));
        $this->dns['multi.example.com'] = ['203.0.114.1', '203.0.114.2', '203.0.114.3', '203.0.114.4'];
        $this->links['https://multi.example.com/none.csv'] = fn () => throw new ConnectionException('timed out');
        $before = count($this->read);
        $refused('https://multi.example.com/none.csv', 'unreachable');
        $this->assertSame(config('kit.links.addresses_tried'), count($this->read) - $before, 'at most that many tried');
        $this->links['https://sheets.example.com/loop'] = [302, '', ['Location' => '/loop']];
        $refused('https://sheets.example.com/loop', 'too_many_redirects');
        $this->assertSame(config('kit.links.max_redirects') + 1, count(array_filter($this->read, fn ($r) => str_ends_with($r['url'], '/loop'))));
    }

    public function test_what_is_not_a_call_list_and_who_may(): void
    {
        $this->links['https://sheets.example.com/page'] = [200, '<html>Sign in</html>', ['Content-Type' => 'text/html; charset=utf-8']];
        $this->follow('https://sheets.example.com/page')->assertStatus(422)->assertJsonPath('errors.url.0', __('links.web_page'));
        $this->links['https://sheets.example.com/big'] = [200, str_repeat("phone\n+14155550101\n", 200000), ['Content-Type' => 'text/csv']];
        $this->follow('https://sheets.example.com/big')->assertStatus(422)->assertJsonPath('errors.url.0', __('links.too_big', ['max' => intdiv(config('kit.campaigns.import_max_bytes'), 1048576)]));
        $this->links['https://sheets.example.com/names'] = [200, "name,email\nAna,ana@example.com\n", ['Content-Type' => 'text/csv']];
        $this->follow('https://sheets.example.com/names')->assertStatus(422)->assertJsonPath('errors.url.0', __('campaigns.no_phone_column', ['columns' => implode(', ', config('kit.campaigns.phone_columns'))]));
        $this->links['https://sheets.example.com/down'] = fn () => throw new ConnectionException('down');
        $this->follow('https://sheets.example.com/down')->assertStatus(422)->assertJsonPath('errors.url.0', __('links.unreachable'));
        $this->assertNull($this->campaign->fresh()->source_url, 'a link that could not be read is not followed');
        $this->assertSame([], $this->people());

        $this->campaign->forceFill(['status' => 'completed'])->save();
        $this->follow(self::SHEET)->assertStatus(409);
        $this->campaign->forceFill(['status' => 'running'])->save();
        app(CampaignSources::class)->allow(false);
        $this->follow(self::SHEET)->assertForbidden()->assertExactJson(['message' => __('campaigns.links_off')]);
        $this->assertFalse($this->actingAs($this->ana)->getJson('/api/kit/console/campaigns')->json('limits.linksAllowed'));
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        WorkspaceMember::create(['workspace_id' => $other->id, 'user_id' => $ben->id, 'role' => 'owner']);
        $this->actingAs($ben)->putJson("/api/kit/console/campaigns/{$this->campaign->id}/source", ['url' => self::SHEET])->assertNotFound();
    }

    public function test_the_operator_turns_links_on_and_off_and_it_is_audited(): void
    {
        app(CampaignSources::class)->allow(false);
        app(Settings::class)->put(\App\Kit\Catalog\Catalog::COPY, ['fetchedAt' => now()->toIso8601String(), 'recommended' => [], 'kinds' => [
            'llm' => [['id' => 'openai', 'name' => 'OpenAI', 'models' => [['id' => 'gpt-5.4-mini', 'name' => 'Mini']]]], 'stt' => [['id' => 'deepgram', 'name' => 'Deepgram']], 'tts' => [['id' => 'openai', 'name' => 'OpenAI voices']],
        ]]);
        $olu = User::create(['name' => 'Olu', 'email' => 'olu@op.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $page = $this->actingAs($olu)->getJson('/api/kit/admin/catalog')->assertOk();
        $this->assertFalse($page->json('campaignLinksAllowed'));
        $this->actingAs($olu)->putJson('/api/kit/admin/catalog', ['kinds' => [], 'defaults' => [], 'campaignLinksAllowed' => true])->assertOk()
            ->assertJsonPath('campaignLinksAllowed', true);
        $this->assertTrue(app(CampaignSources::class)->allowed());
        $this->assertSame('campaign_links.allowed', AuditEvent::query()->latest('id')->value('action'));
    }

    public function test_addresses_that_are_not_public(): void
    {
        foreach (['10.1.2.3', '172.16.0.1', '192.168.0.1', '127.0.0.1', '169.254.169.254', '0.0.0.0', '100.64.0.1', '198.18.0.1', '192.0.2.1', '::1', 'fd12::1', 'fe80::1',
            '::ffff:10.0.0.1', '64:ff9b::7f00:1', '2001:db8::1'] as $ip) {
            $this->assertFalse(PublicLinks::isPublic($ip), $ip);
        }
        foreach (['93.184.216.34', '151.101.1.1', '2606:4700::6810:85e5', '::ffff:93.184.216.34'] as $ip) {
            $this->assertTrue(PublicLinks::isPublic($ip), $ip);
        }
    }
}
