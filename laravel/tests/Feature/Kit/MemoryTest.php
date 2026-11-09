<?php

namespace Tests\Feature\Kit;

use App\Models\Agent;
use App\Models\AgentWebChat;
use App\Models\Call;
use App\Models\ChatConversation;
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
 * Caller memory (App\Kit\Memory\CallerMemory; contract/kit-api.yaml getCallerMemory, forgetCaller): what the account's agents remember, read live
 * with the account's own key and named in the kit's terms (a number, a web chat visitor's website, the kit's own call), ended ones marked; forgetting
 * only by someone who manages the account, a number however written. Telenow answers as recorded on its live release (contract_k17memory.py).
 */
class MemoryTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const ORG = 'fe94c9cc-8ddc-51b7-89e0-17bd6af2029e';

    private User $ana;

    private User $ben;

    private Workspace $acme;

    /** @var list<array{method: string, path: string, query: string, key: ?string}> */
    private array $sent = [];

    /** What forgetting answers next: recordings' names or closures (none left: removed). */
    private array $forgets = [];

    private string|\Closure $listed = 'listOrgCallerMemory.three';

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => self::ORG, 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'owner']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ben->id, 'role' => 'member']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            $this->sent[] = ['method' => $r->method(), 'path' => $path, 'query' => (string) parse_url($r->url(), PHP_URL_QUERY), 'key' => $r->header('X-API-Key')[0] ?? null];
            if ($r->method() === 'GET') {
                return $this->listed instanceof \Closure ? ($this->listed)() : RecordedTelenow::response($this->listed);
            }
            $next = array_shift($this->forgets) ?? 'eraseOrgCallerMemory.removed';

            return $next instanceof \Closure ? $next() : RecordedTelenow::response($next);
        });
    }

    public function test_what_the_agents_remember_in_the_kits_own_terms(): void
    {
        $recorded = RecordedTelenow::body('listOrgCallerMemory.three')['data'];
        $byKey = array_column($recorded, null, 'callerKey');
        $chatKey = array_values(array_filter(array_keys($byKey), fn ($k) => str_starts_with($k, 'kit-')))[0];
        // The kit's own conversation behind the web chat visitor, and its own call behind the phone caller's last session.
        $agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Front desk']);
        $box = (new AgentWebChat)->forceFill(['agent_id' => $agent->id, 'workspace_id' => $this->acme->id, 'public_key' => 'k', 'enabled' => true, 'websites' => ['https://www.clinic.example']]);
        $box->save();
        (new ChatConversation)->forceFill(['id' => substr($chatKey, 4), 'web_chat_id' => $box->id, 'workspace_id' => $this->acme->id, 'token_hash' => str_repeat('a', 64),
            'website' => 'https://www.clinic.example', 'messages' => 1])->save();
        $call = (new Call)->forceFill(['workspace_id' => $this->acme->id, 'telenow_id' => $byKey['14155550100']['lastSession'], 'status' => 'ended', 'detail_state' => 'final', 'reads' => 1]);
        $call->save();

        $got = $this->actingAs($this->ben)->getJson('/api/kit/console/memory')->assertOk();
        $this->assertSame(['limit' => 200, 'canForget' => false], array_intersect_key($got->json(), array_flip(['limit', 'canForget'])));
        $callers = array_column($got->json('callers'), null, 'key');
        $this->assertSame(['kind' => 'chat', 'caller' => $chatKey, 'website' => 'https://www.clinic.example', 'facts' => [], 'calls' => 1, 'expired' => false, 'lastCallId' => null],
            array_intersect_key($callers[$chatKey], array_flip(['kind', 'caller', 'website', 'facts', 'calls', 'expired', 'lastCallId'])));
        $asha = $callers['14155550100'];
        $this->assertSame(['phone', '+14155550100', null, 3, false, $call->id], [$asha['kind'], $asha['caller'], $asha['website'], $asha['calls'], $asha['expired'], $asha['lastCallId']]);
        $facts = array_column($asha['facts'], 'value', 'name');
        ksort($facts);
        $this->assertSame(['children' => '2', 'name' => 'Asha', 'preference' => 'mornings'], $facts, 'every fact as text, a number too');
        $this->assertSame($byKey['14155550100']['summary'], $asha['summary']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $asha['updatedAt']);
        $this->assertTrue($callers['14155550199']['expired'], 'ended: kept, no longer used on calls');
        $this->assertSame(['GET', '/api/orgs/'.self::ORG.'/memory', self::KEY], [$this->sent[0]['method'], $this->sent[0]['path'], $this->sent[0]['key']]);
        // Telenow's own ids stay in the kit: its org, its session.
        $this->assertStringNotContainsString(self::ORG, $got->getContent());
        $this->assertStringNotContainsString($byKey['14155550100']['lastSession'], $got->getContent());
        $this->assertTrue($this->actingAs($this->ana)->getJson('/api/kit/console/memory')->json('canForget'));
    }

    public function test_only_someone_who_manages_the_account_forgets_a_caller(): void
    {
        $this->actingAs($this->ben)->deleteJson('/api/kit/console/memory?caller=%2B14155550100')->assertForbidden()->assertExactJson(['message' => __('memory.only_managers')]);
        $this->assertSame([], $this->sent);

        $this->actingAs($this->ana)->deleteJson('/api/kit/console/memory?caller='.rawurlencode('+1 (415) 555-0100'))->assertOk()->assertExactJson(['removed' => true]);
        $this->assertSame(['DELETE', '/api/orgs/'.self::ORG.'/memory', 'caller=14155550100', self::KEY], array_values(end($this->sent)));
        $this->actingAs($this->ana)->deleteJson('/api/kit/console/memory?caller=KIT-55B47F25-16C1-5ECA-AE46-F25DFEC463CD')->assertOk();
        $this->assertSame('caller=kit-55b47f25-16c1-5eca-ae46-f25dfec463cd', end($this->sent)['query'], 'a listed key as it is kept');
        $this->forgets = ['eraseOrgCallerMemory.not-there'];
        $this->actingAs($this->ana)->deleteJson('/api/kit/console/memory?caller=14155550100')->assertOk()->assertExactJson(['removed' => false]);
    }

    public function test_a_caller_that_is_not_one_and_the_platform_unreachable(): void
    {
        $sent = count($this->sent);
        $this->actingAs($this->ana)->deleteJson('/api/kit/console/memory')->assertStatus(422)->assertJsonValidationErrors(['caller']);
        $this->actingAs($this->ana)->deleteJson('/api/kit/console/memory?caller='.rawurlencode('+ ( ) -'))->assertStatus(422)->assertJsonPath('errors.caller.0', __('memory.caller_invalid'));
        $this->assertCount($sent, $this->sent, 'nothing sent');
        // Another kind of caller's key (an app's own identifier) goes as it is, never cut down to its digits, which could name another caller.
        $this->actingAs($this->ana)->deleteJson('/api/kit/console/memory?caller=Order-12345')->assertOk();
        $this->assertSame('caller=order-12345', end($this->sent)['query']);
        $this->forgets = ['eraseOrgCallerMemory.blank'];
        $this->actingAs($this->ana)->deleteJson('/api/kit/console/memory?caller=123')->assertStatus(422);
        $this->forgets = [fn () => throw new ConnectionException('unreachable')];
        $this->actingAs($this->ana)->deleteJson('/api/kit/console/memory?caller=123')->assertStatus(503)->assertExactJson(['message' => __('memory.unavailable')]);
        $this->listed = fn () => throw new ConnectionException('unreachable');
        $this->actingAs($this->ben)->getJson('/api/kit/console/memory')->assertStatus(503)->assertExactJson(['message' => __('memory.unavailable')]);
        $this->listed = 'listOrgCallerMemory.not-this-account';
        $this->actingAs($this->ben)->getJson('/api/kit/console/memory')->assertStatus(503);
    }

    public function test_an_account_not_on_the_platform_yet_remembers_nobody(): void
    {
        $this->acme->forceFill(['telenow_sub_account_id' => null, 'telenow_key' => null])->save();
        $this->actingAs($this->ben)->getJson('/api/kit/console/memory')->assertOk()->assertJsonPath('callers', []);
        $this->actingAs($this->ana)->deleteJson('/api/kit/console/memory?caller=14155550100')->assertOk()->assertExactJson(['removed' => false]);
        $this->assertSame([], $this->sent);
        $this->assertSame([], RecordedTelenow::body('listOrgCallerMemory.empty')['data']);
    }
}
