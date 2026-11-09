<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Kit\Numbers\NumberRenewals;
use App\Kit\Numbers\Numbers;
use App\Kit\Settings\Settings;
use App\Models\Agent;
use App\Models\Campaign;
use App\Models\OutgoingEmail;
use App\Models\PhoneNumber;
use App\Models\PriceList;
use App\Models\RoutingRule;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Number controls (App\Kit\Numbers; contract/kit-api.yaml setNumberCallerId, setNumberUnanswered, listLostNumbers, getLostNumberBack,
 * getNumberRenewals): a number as its agent's caller id; what a call to a number no agent answers does; numbers the platform gave up when their rent
 * was not paid, got back at the plan's price (a new number in the old one's place) or asked for; whether the balance covers the next rent, and the
 * email when it will not. Telenow answers as recorded on its live release (contract_k27numbers.py).
 */
class NumberControlsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    /** Telenow's ids in the recordings: the given-up number, what it came back as, its agent, the one the carrier would not give back. */
    private const GONE = 'c90520dc-eff2-5a2b-8fba-9bbc3bc6eaf8';

    private const BACK = 'e03a8b83-e69e-5c42-b8fa-a13e34faf07b';

    private const FRONT = '5b2c9c98-5fa1-50b9-bc46-a37f5c4bea3b';

    private const ASKED = 'a3e1ccc2-419e-5205-b2ac-d2ada15e30d8';

    private User $ana;

    private User $ben;

    private Workspace $acme;

    private Agent $front;

    private Agent $late;

    private PhoneNumber $main;

    private PhoneNumber $spare;

    private PhoneNumber $loose;

    /** @var array<string, list<string|\Closure>> answers queued by "METHOD what" */
    private array $answers = [];

    /** @var list<array{method: string, path: string, body: mixed, key: ?string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $settings = app(Settings::class);
        $settings->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        // A number's setup 50.00 INR whatever it costs; other number fees 20 %: a rent of 5.25 USD is 5.25 × 83 × 1.2 = 522.90 INR.
        PriceList::create(['id' => 'standard', 'name' => 'Standard', 'definition' => ['id' => 'standard', 'currency' => 'INR', 'decimals' => 2, 'rounding' => 'half-up',
            'billingIncrementSeconds' => 60, 'rules' => [
                ['id' => 'setup', 'match' => ['item' => 'fee', 'refKind' => 'number_setup'], 'price' => ['fixed' => '50.00']],
                ['id' => 'numbers', 'match' => ['item' => 'fee'], 'price' => ['markupPercent' => '20']],
            ]]]);
        $settings->put('pricing.currency', 'INR');
        $settings->put('pricing.usd_rate', '83.00');
        $settings->put('pricing.default_plan', 'standard');
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => self::KEY, 'balance' => '1000.00'])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'owner']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ben->id, 'role' => 'member']);
        $this->front = $this->agent('Front desk', self::FRONT);
        $this->late = $this->agent('After hours');
        $this->main = $this->number('918045671003', ['agent_id' => $this->front->id]);
        $this->spare = $this->number('918045671002', ['agent_id' => $this->front->id, 'agent_caller_id' => true]);
        $this->loose = $this->number('918045671004');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            $this->sent[] = ['method' => $r->method(), 'path' => $path, 'body' => $r->data(), 'key' => $r->header('X-API-Key')[0] ?? null];
            $what = $r->method().' '.match (true) {
                str_ends_with($path, '/default-outbound') => 'caller',
                str_ends_with($path, '/inbound-action') => 'unanswered',
                str_ends_with($path, '/assign-agent') => 'assign',
                str_ends_with($path, '/numbers/reclaimable') => 'lost',
                str_ends_with($path, '/reclaim') => 'reclaim',
                str_ends_with($path, '/billing/ledger') => 'ledger',
                $path === '/api/voice/numbers' => 'feed',
                (bool) preg_match('#^/api/voice/numbers/[0-9a-f-]{36}$#', $path) => 'number',
                default => $path,
            };
            $answer = ($this->answers[$what] ?? []) !== [] ? array_shift($this->answers[$what]) : null;
            $answer ??= match ($what) {
                'POST caller' => fn (Request $r) => $this->numberAnswer($r, $r->data()['isDefault'] ? 'setDefaultOutboundNumber.set' : 'setDefaultOutboundNumber.off'),
                'POST unanswered' => fn (Request $r) => $this->numberAnswer($r, 'setNumberInboundAction.'.($r->data()['action'] ?? 'cleared')),
                'POST assign' => fn (Request $r) => $this->numberAnswer($r, 'assignNumberAgent.same-agent-again'),
                'GET lost' => 'listReclaimableNumbers.unpaid',
                'POST reclaim' => 'reclaimNumber.restored',
                'GET number' => 'getNumber.restored',
                'GET feed' => fn () => Http::response(['success' => true, 'data' => ['numbers' => [], 'total' => 0]]),
                'GET ledger' => fn () => Http::response(['success' => true, 'data' => ['entries' => [], 'limit' => 50, 'nextCursor' => null]]),
                default => throw new \RuntimeException("nothing recorded for {$what}"),
            };

            return $answer instanceof \Closure ? $answer($r) : RecordedTelenow::response($answer);
        });
    }

    private function agent(string $name, ?string $telenowId = null): Agent
    {
        $a = Agent::create(['workspace_id' => $this->acme->id, 'name' => $name]);
        $a->forceFill(['telenow_id' => $telenowId ?? (string) Str::uuid(), 'sync_state' => 'synced'])->save();

        return $a;
    }

    private function number(string $e164, array $over = [], ?Workspace $in = null): PhoneNumber
    {
        $n = new PhoneNumber;
        $n->forceFill($over + ['workspace_id' => ($in ?? $this->acme)->id, 'e164' => $e164, 'telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced'])->save();

        return $n;
    }

    /** The given-up number as the kit keeps it: released, with Telenow's id in the recordings. */
    private function gone(array $over = []): PhoneNumber
    {
        return $this->number('918045671001', $over + ['telenow_id' => self::GONE, 'released_at' => now()->subHour()]);
    }

    private function campaign(PhoneNumber $callerId): Campaign
    {
        return Campaign::forceCreate(['workspace_id' => $this->acme->id, 'agent_id' => $this->front->id, 'phone_number_id' => $callerId->id, 'name' => 'Recall',
            'concurrency' => 1, 'timezone' => 'Asia/Kolkata', 'machine_detection' => 'off', 'max_attempts' => 1, 'retry_on_no_answer' => false, 'status' => 'draft', 'sync_state' => 'synced']);
    }

    /** Telenow's recorded number answer, as the number asked about: its id, its digits and its agent by Telenow's id. */
    private function numberAnswer(Request $r, string $name): \GuzzleHttp\Promise\PromiseInterface
    {
        $body = RecordedTelenow::body($name);
        $n = PhoneNumber::query()->where('telenow_id', basename(dirname((string) parse_url($r->url(), PHP_URL_PATH))))->firstOrFail();
        $body['data'] = array_merge($body['data'], ['id' => $n->telenow_id, 'e164' => $n->e164, 'agent_id' => $n->agent?->telenow_id]);

        return Http::response($body, 200);
    }

    private function sentTo(string $suffix, ?string $method = null): array
    {
        return array_values(array_filter($this->sent, fn ($s) => str_ends_with($s['path'], $suffix) && ($method === null || $s['method'] === $method)));
    }

    private function listed(PhoneNumber $n, ?User $who = null): array
    {
        return collect($this->actingAs($who ?? $this->ben)->getJson('/api/kit/console/numbers')->assertOk()->json('numbers'))->firstWhere('id', $n->id);
    }

    public function test_a_number_becomes_its_agents_caller_id_and_the_agents_other_stops_being(): void
    {
        $theirsToo = $this->number('918045671005', ['agent_id' => $this->late->id, 'agent_caller_id' => true]); // another agent's: stays
        $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$this->main->id}/caller-id", ['agentCallerId' => true])
            ->assertOk()->assertJsonPath('agentCallerId', true)->assertJsonPath('syncState', 'synced');
        $sent = $this->sentTo('/default-outbound');
        $this->assertCount(1, $sent);
        $this->assertSame(['isDefault' => true], $sent[0]['body']);
        $this->assertSame(self::KEY, $sent[0]['key']);
        $this->assertSame("/api/voice/numbers/{$this->main->telenow_id}/default-outbound", $sent[0]['path']);
        $this->assertFalse($this->listed($this->spare)['agentCallerId']);
        $this->assertTrue($this->listed($this->main)['agentCallerId']);
        $this->assertTrue($this->listed($theirsToo)['agentCallerId']);

        $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$this->main->id}/caller-id", ['agentCallerId' => false])
            ->assertOk()->assertJsonPath('agentCallerId', false);
        $this->assertSame(['isDefault' => false], $this->sentTo('/default-outbound')[1]['body']);

        // A number with no agent is nobody's caller id, either way (Telenow refuses it too, recorded).
        foreach ([true, false] as $on) {
            $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$this->loose->id}/caller-id", ['agentCallerId' => $on])
                ->assertStatus(409)->assertJsonPath('message', __('numbers.caller_id_needs_agent'));
        }
        $this->front->forceFill(['deleted_at' => now()])->save();
        $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$this->main->id}/caller-id", ['agentCallerId' => true])->assertStatus(409);
        $this->assertCount(2, $this->sentTo('/default-outbound'));
        $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$this->main->id}/caller-id", [])->assertStatus(422)->assertJsonValidationErrors('agentCallerId');
        $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$this->main->id}/caller-id", ['agentCallerId' => 'yes'])->assertStatus(422);
        $theirs = $this->number('918045679001', [], Workspace::create(['name' => 'Bright Smiles', 'status' => 'active', 'sync_state' => 'synced']));
        $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$theirs->id}/caller-id", ['agentCallerId' => true])->assertNotFound();
        $released = $this->number('918045679002', ['agent_id' => $this->late->id, 'released_at' => now()]);
        $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$released->id}/caller-id", ['agentCallerId' => true])->assertNotFound();
        $this->assertCount(2, $this->sentTo('/default-outbound'));
    }

    public function test_giving_a_number_an_agent_clears_its_caller_id_and_what_unanswered_calls_do_as_telenow_does(): void
    {
        $this->main->forceFill(['agent_caller_id' => true])->save();
        // Recorded: the same agent again clears it too.
        $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$this->main->id}/agent", ['agentId' => $this->front->id])
            ->assertOk()->assertJsonPath('agentCallerId', false)->assertJsonPath('agent.id', $this->front->id);
        $this->loose->forceFill(['inbound_action' => 'reject'])->save();
        $this->assertSame('busy', $this->listed($this->loose)['unanswered']);
        $this->spare->forceFill(['agent_caller_id' => true])->save();
        $this->answers['POST assign'] = [fn () => Http::response(['error' => 'down'], 503), fn () => Http::response(['error' => 'down'], 503)];
        // Cleared at once, before Telenow answers (it clears both whenever the agent is set).
        $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$this->loose->id}/agent", ['agentId' => $this->late->id])
            ->assertOk()->assertJsonPath('unanswered', 'message')->assertJsonPath('syncState', 'pending');
        $this->assertNull($this->loose->fresh()->inbound_action);
        $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$this->spare->id}/agent", ['agentId' => $this->late->id])
            ->assertOk()->assertJsonPath('agentCallerId', false)->assertJsonPath('syncState', 'pending');
    }

    public function test_an_agent_call_comes_from_its_caller_id_first(): void
    {
        $this->spare->forceFill(['agent_caller_id' => false])->save();
        // No caller id: the agent's numbers in order (918045671002 before 918045671003).
        $this->actingAs($this->ben)->getJson("/api/kit/console/agents/{$this->front->id}/call-form")->assertOk()->assertJsonPath('ownNumber', $this->spare->id);
        $this->main->forceFill(['agent_caller_id' => true])->save();
        $this->actingAs($this->ben)->getJson("/api/kit/console/agents/{$this->front->id}/call-form")->assertOk()->assertJsonPath('ownNumber', $this->main->id);
    }

    public function test_what_a_call_to_a_number_no_agent_answers_does(): void
    {
        foreach (['busy' => 'reject', 'unavailable' => 'no-answer', 'message' => 'speak'] as $choice => $telenow) {
            $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$this->loose->id}/unanswered", ['unanswered' => $choice])
                ->assertOk()->assertJsonPath('unanswered', $choice)->assertJsonPath('syncState', 'synced');
            $this->assertSame(['action' => $telenow], array_slice($this->sentTo('/inbound-action'), -1)[0]['body']);
            $this->assertSame($telenow, $this->loose->fresh()->inbound_action);
        }
        // An agent answers it: this never happens (Telenow refuses it too, recorded).
        $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$this->main->id}/unanswered", ['unanswered' => 'busy'])
            ->assertStatus(409)->assertJsonPath('message', __('numbers.unanswered_has_agent'));
        $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$this->loose->id}/unanswered", ['unanswered' => 'voicemail'])
            ->assertStatus(422)->assertJsonPath('errors.unanswered.0', __('numbers.unknown_unanswered'));
        $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$this->loose->id}/unanswered", ['unanswered' => 'forward'])->assertStatus(422);
        $this->assertCount(3, $this->sentTo('/inbound-action'));
        // As Telenow keeps it: none is the account's default (a message), and `forward` speaks it (no number to forward to, recorded).
        foreach ([null => 'message', 'forward' => 'message', 'reject' => 'busy', 'no-answer' => 'unavailable', 'speak' => 'message'] as $kept => $read) {
            $this->loose->forceFill(['inbound_action' => $kept === '' ? null : $kept])->save();
            $this->assertSame($read, Numbers::unansweredOf($this->loose->fresh()));
        }
    }

    public function test_the_feed_keeps_the_caller_id_what_unanswered_calls_do_and_the_rent_never_shown(): void
    {
        $row = RecordedTelenow::body('getNumber.restored')['data'];
        $this->answers['GET feed'][] = fn () => Http::response(['success' => true, 'data' => ['total' => 2, 'numbers' => [
            array_merge($row, ['id' => $this->spare->telenow_id, 'e164' => $this->spare->e164, 'agent_id' => self::FRONT, 'is_default_outbound' => false, 'updated_at' => '2026-11-08T15:54:10.000000Z']),
            array_merge($row, ['id' => $this->loose->telenow_id, 'e164' => $this->loose->e164, 'agent_id' => null, 'is_default_outbound' => false, 'inbound_action' => 'no-answer', 'monthly_rent_usd' => '6.10', 'updated_at' => '2026-11-08T15:54:11.000000Z']),
        ]]]);
        app(Numbers::class)->feed($this->acme);
        $this->assertFalse($this->spare->fresh()->agent_caller_id);
        $this->assertSame('5.25', $this->spare->fresh()->rent_usd);
        $this->assertSame('6.10', $this->loose->fresh()->rent_usd);
        $this->assertSame('unavailable', $this->listed($this->loose)['unanswered']);
        $page = $this->actingAs($this->ben)->getJson('/api/kit/console/numbers')->assertOk()->getContent();
        $this->assertStringNotContainsString('6.10', $page);
        $this->assertStringNotContainsString('5.25', $page);
        $this->assertStringNotContainsString('no-answer', $page);
    }

    public function test_lost_numbers_are_listed_at_the_plans_price_in_the_kits_words(): void
    {
        $gone = $this->gone();
        $page = $this->actingAs($this->ben)->getJson('/api/kit/console/numbers/lost')->assertOk();
        $page->assertExactJson(['numbers' => [[
            'id' => $gone->id, 'number' => '+918045671001', 'releasedAt' => $gone->released_at->toIso8601String(),
            'until' => '2026-11-13T15:54:15+00:00', 'askedAt' => null, 'reason' => 'unpaid',
            'agent' => ['id' => $this->front->id, 'name' => 'Front desk'], 'wasCallerId' => true,
            'setupPrice' => ['amount' => '50.00', 'currency' => 'INR'], 'monthlyPrice' => ['amount' => '522.90', 'currency' => 'INR'],
        ]]]);
        foreach (['teleone', 'renewal_unpaid', '499', '5.25', self::GONE, self::FRONT] as $never) {
            $this->assertStringNotContainsString($never, $page->getContent());
        }
        $this->answers['GET lost'][] = fn () => Http::response(['success' => true, 'data' => ['total' => 1, 'numbers' => [
            array_merge(RecordedTelenow::body('listReclaimableNumbers.unpaid')['data']['numbers'][0], ['releaseReason' => 'forced'])]]]);
        $this->actingAs($this->ben)->getJson('/api/kit/console/numbers/lost')->assertOk()->assertJsonPath('numbers.0.reason', 'other');
        // The feed has not brought it yet: kept now, released, so it can be got back.
        $gone->delete();
        $kept = $this->actingAs($this->ben)->getJson('/api/kit/console/numbers/lost')->assertOk()->json('numbers.0');
        $row = PhoneNumber::query()->where('telenow_id', self::GONE)->sole();
        $this->assertSame($row->id, $kept['id']);
        $this->assertNotNull($row->released_at);
        $this->assertSame('2026-11-08T15:54:15+00:00', $kept['releasedAt']);
        // Charging off: no prices.
        PriceList::query()->delete();
        $this->actingAs($this->ben)->getJson('/api/kit/console/numbers/lost')->assertOk()->assertJsonPath('numbers.0.setupPrice', null)->assertJsonPath('numbers.0.monthlyPrice', null);
        $this->answers['GET lost'][] = fn () => throw new ConnectionException('down');
        $this->actingAs($this->ben)->getJson('/api/kit/console/numbers/lost')->assertStatus(503)->assertJsonPath('message', __('numbers.lost_unavailable'));
    }

    public function test_a_lost_number_comes_back_as_a_new_number_in_the_old_ones_place(): void
    {
        $gone = $this->gone(['agent_id' => null]);
        $campaign = $this->campaign($gone);
        $rule = RoutingRule::forceCreate(['workspace_id' => $this->acme->id, 'position' => 1, 'name' => 'Main line', 'enabled' => true, 'numbers' => [$this->main->id, $gone->id],
            'forwarded_from' => [], 'caller_starts' => [], 'days' => [], 'action' => 'answer']);
        $this->acme->forceFill(['numbers_synced_at' => now()])->save();
        $done = $this->actingAs($this->ben)->postJson("/api/kit/console/numbers/{$gone->id}/get-back")->assertOk()->assertJsonPath('outcome', 'restored');
        $new = PhoneNumber::query()->where('telenow_id', self::BACK)->sole();
        $done->assertJsonPath('number.id', $new->id)->assertJsonPath('number.number', '+918045671001')->assertJsonPath('number.agent.id', $this->front->id)
            ->assertJsonPath('number.agentCallerId', true)->assertJsonPath('number.monthlyPrice', ['amount' => '522.90', 'currency' => 'INR'])
            ->assertJsonPath('number.syncState', 'synced')->assertJsonPath('number.releasing', false);
        $this->assertSame('5.25', $new->rent_usd);
        $this->assertSame($new->id, $gone->fresh()->got_back_as);
        $this->assertSame($new->id, $campaign->fresh()->phone_number_id);
        $this->assertSame([$this->main->id, $new->id], $rule->fresh()->numbers);
        $this->assertNull($this->acme->fresh()->numbers_synced_at); // its fees: on the next feed run, as a purchase's
        $this->assertSame(["/api/voice/numbers/reclaimable", '/api/voice/numbers/'.self::GONE.'/reclaim', '/api/voice/numbers/'.self::BACK],
            array_column($this->sent, 'path'));
        // Once back, never again (Telenow is not asked).
        $this->actingAs($this->ben)->postJson("/api/kit/console/numbers/{$gone->id}/get-back")->assertStatus(409)->assertJsonPath('message', __('numbers.already_back'));
        $this->assertCount(3, $this->sent);
        // Its new self is listed; a number still held cannot be "got back".
        $this->assertNotNull($this->listed($new));
        $this->actingAs($this->ben)->postJson("/api/kit/console/numbers/{$this->main->id}/get-back")->assertNotFound();
    }

    public function test_the_number_is_kept_as_the_reclaim_answer_says_when_it_cannot_be_read_again(): void
    {
        $gone = $this->gone();
        $this->answers['GET number'][] = fn () => throw new ConnectionException('down');
        $this->actingAs($this->ben)->postJson("/api/kit/console/numbers/{$gone->id}/get-back")->assertOk()->assertJsonPath('outcome', 'restored')
            ->assertJsonPath('number.agent', null)->assertJsonPath('number.monthlyPrice', ['amount' => '522.90', 'currency' => 'INR']);
        $this->assertSame($gone->fresh()->got_back_as, PhoneNumber::query()->where('telenow_id', self::BACK)->sole()->id);
    }

    public function test_getting_a_number_back_needs_the_balance_first_while_charging_is_on(): void
    {
        $gone = $this->gone();
        $this->acme->forceFill(['balance' => '572.89'])->save();
        $this->actingAs($this->ben)->postJson("/api/kit/console/numbers/{$gone->id}/get-back")->assertStatus(409)
            ->assertJsonPath('message', __('numbers.not_enough_balance_back', ['amount' => '572.90', 'currency' => 'INR']));
        $this->assertSame([], $this->sentTo('/reclaim'));
        $this->acme->forceFill(['balance' => '572.90'])->save();
        $this->actingAs($this->ben)->postJson("/api/kit/console/numbers/{$gone->id}/get-back")->assertOk();
        $this->assertCount(1, $this->sentTo('/reclaim'));
    }

    public function test_without_charging_a_number_comes_back_whatever_the_balance(): void
    {
        $gone = $this->gone();
        $this->acme->forceFill(['balance' => '0'])->save();
        PriceList::query()->delete();
        $this->actingAs($this->ben)->postJson("/api/kit/console/numbers/{$gone->id}/get-back")->assertOk()->assertJsonPath('number.monthlyPrice', null);
    }

    public function test_a_number_the_carrier_will_not_give_back_is_asked_for_and_takes_the_old_ones_place_when_it_comes(): void
    {
        $asked = $this->number('918045679999', ['telenow_id' => self::ASKED, 'released_at' => now()->subHour()]);
        $campaign = $this->campaign($asked);
        $this->answers['GET lost'][] = fn () => Http::response(['success' => true, 'data' => ['total' => 1, 'numbers' => [
            array_merge(RecordedTelenow::body('listReclaimableNumbers.requested')['data']['numbers'][0], ['reclaimRequestedAt' => null])]]]);
        $this->answers['POST reclaim'][] = 'reclaimNumber.requested';
        $this->actingAs($this->ben)->postJson("/api/kit/console/numbers/{$asked->id}/get-back")->assertOk()
            ->assertExactJson(['outcome' => 'requested', 'until' => '2026-11-13T15:54:15+00:00']);
        $this->assertSame('2026-11-08 15:54:15', $asked->fresh()->get_back_asked_at->format('Y-m-d H:i:s'));
        $this->assertSame([], $this->sentTo(self::BACK));
        // Asked already: no balance needed again, Telenow says so again.
        $this->acme->forceFill(['balance' => '0'])->save();
        $this->answers['GET lost'][] = 'listReclaimableNumbers.requested';
        $this->answers['POST reclaim'][] = 'reclaimNumber.requested-again';
        $this->actingAs($this->ben)->postJson("/api/kit/console/numbers/{$asked->id}/get-back")->assertOk()->assertJsonPath('outcome', 'requested');
        $this->answers['GET lost'][] = 'listReclaimableNumbers.requested';
        $this->actingAs($this->ben)->getJson('/api/kit/console/numbers/lost')->assertOk()->assertJsonPath('numbers.0.askedAt', '2026-11-08T15:54:15+00:00')
            ->assertJsonPath('numbers.0.agent', null)->assertJsonPath('numbers.0.wasCallerId', false);
        // Telenow's staff bring it back: the feed brings a new number with its digits, which takes its place.
        $other = $this->number('918045671009', ['released_at' => now()->subDay()]); // released by the account: never adopted
        $row = RecordedTelenow::body('getNumber.restored')['data'];
        $this->answers['GET feed'][] = fn () => Http::response(['success' => true, 'data' => ['total' => 1, 'numbers' => [
            array_merge($row, ['id' => '7f1c1f5e-0f43-4a8e-9a39-6f3f0d0d1d01', 'e164' => '+91 80456 79999', 'agent_id' => null, 'is_default_outbound' => false]),
            array_merge($row, ['id' => '7f1c1f5e-0f43-4a8e-9a39-6f3f0d0d1d02', 'e164' => '918045671009', 'agent_id' => null, 'is_default_outbound' => false]),
        ]]]);
        app(Numbers::class)->feed($this->acme);
        $new = PhoneNumber::query()->where('telenow_id', '7f1c1f5e-0f43-4a8e-9a39-6f3f0d0d1d01')->sole();
        $this->assertSame($new->id, $asked->fresh()->got_back_as);
        $this->assertSame($new->id, $campaign->fresh()->phone_number_id);
        $this->assertNull($other->fresh()->got_back_as);
    }

    public function test_telenows_refusals_read_in_the_kits_words(): void
    {
        $gone = $this->gone();
        foreach (['reclaimNumber.wallet-empty' => __('numbers.get_back_not_now'), 'reclaimNumber.past-hold' => __('numbers.get_back_too_late'),
            'reclaimNumber.twice' => __('numbers.already_back'), 'reclaimNumber.unknown' => __('numbers.not_lost')] as $recorded => $words) {
            $this->answers['POST reclaim'][] = $recorded;
            $answer = $this->actingAs($this->ben)->postJson("/api/kit/console/numbers/{$gone->id}/get-back")->assertStatus(409)->assertJsonPath('message', $words);
            $this->assertStringNotContainsString('billed to', $answer->getContent());
            $this->assertStringNotContainsString('carrier', $answer->getContent());
        }
        $this->answers['POST reclaim'][] = fn () => Http::response(['error' => 'down'], 502);
        $this->actingAs($this->ben)->postJson("/api/kit/console/numbers/{$gone->id}/get-back")->assertStatus(503)->assertJsonPath('message', __('numbers.lost_unavailable'));
        // Not among Telenow's lost numbers (released by the account, say): not asked to buy anything.
        $mine = $this->number('918045671008', ['released_at' => now()->subDay()]);
        $calls = count($this->sentTo('/reclaim'));
        $this->actingAs($this->ben)->postJson("/api/kit/console/numbers/{$mine->id}/get-back")->assertStatus(409)->assertJsonPath('message', __('numbers.not_lost'));
        $this->assertCount($calls, $this->sentTo('/reclaim'));
        $this->assertNull($gone->fresh()->got_back_as);
        $this->assertSame(0, PhoneNumber::query()->where('telenow_id', self::BACK)->count());
    }

    public function test_renewals_reckon_the_running_total_against_the_balance(): void
    {
        PhoneNumber::query()->delete();
        $a = $this->number('918045671101', ['next_renewal_at' => now()->addDays(2), 'rent_usd' => '5.25']);
        $b = $this->number('918045671102', ['next_renewal_at' => now()->addDays(5), 'rent_usd' => '5.25']);
        $c = $this->number('918045671103', ['next_renewal_at' => now()->addDays(20), 'rent_usd' => '5.25']);
        $d = $this->number('918045671104', ['next_renewal_at' => now()->addDays(25)]); // no rent known: not counted
        $this->number('918045671105', ['next_renewal_at' => now()->addDay(), 'rent_usd' => '5.25', 'released_at' => now()]);
        $this->number('918045671106', ['rent_usd' => '5.25']);
        $rent = ['amount' => '522.90', 'currency' => 'INR'];
        $this->actingAs($this->ben)->getJson('/api/kit/console/numbers/renewals')->assertOk()->assertExactJson([
            'charging' => true, 'balance' => ['amount' => '1000.00', 'currency' => 'INR'], 'soonDays' => 7,
            'numbers' => [
                ['id' => $a->id, 'number' => '+918045671101', 'renewsAt' => $a->next_renewal_at->toIso8601String(), 'rent' => $rent, 'status' => 'soon', 'shortBy' => null],
                ['id' => $b->id, 'number' => '+918045671102', 'renewsAt' => $b->next_renewal_at->toIso8601String(), 'rent' => $rent, 'status' => 'short', 'shortBy' => ['amount' => '45.80', 'currency' => 'INR']],
                ['id' => $c->id, 'number' => '+918045671103', 'renewsAt' => $c->next_renewal_at->toIso8601String(), 'rent' => $rent, 'status' => 'short', 'shortBy' => ['amount' => '568.70', 'currency' => 'INR']],
                ['id' => $d->id, 'number' => '+918045671104', 'renewsAt' => $d->next_renewal_at->toIso8601String(), 'rent' => null, 'status' => 'ok', 'shortBy' => null],
            ],
        ]);
        PriceList::query()->delete();
        $off = $this->actingAs($this->ben)->getJson('/api/kit/console/numbers/renewals')->assertOk()->assertJsonPath('charging', false)->assertJsonPath('balance', null);
        $this->assertSame(['soon', 'soon', 'ok', 'ok'], array_column($off->json('numbers'), 'status'));
        $this->assertSame([null, null, null, null], array_column($off->json('numbers'), 'rent'));
    }

    public function test_owners_are_emailed_once_for_each_renewal_their_balance_will_not_cover(): void
    {
        $senders = app(Senders::class);
        $senders->putSiteUrl('https://console.acme.example');
        $senders->putFrom('hello@acme.example', null);
        $senders->put([['id' => 'down', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none']]]);
        PhoneNumber::query()->delete();
        $a = $this->number('918045671101', ['next_renewal_at' => now()->addDays(2), 'rent_usd' => '5.25']);
        $b = $this->number('918045671102', ['next_renewal_at' => now()->addDays(5), 'rent_usd' => '5.25']);
        $this->number('918045671103', ['next_renewal_at' => now()->addDays(20), 'rent_usd' => '5.25']); // short, but not within 7 days
        $renewals = app(NumberRenewals::class);
        $this->acme->forceFill(['balance' => '500.00'])->save(); // the first short by 22.90, the second by 545.80 (a running total)
        $this->artisan('kit:numbers')->assertSuccessful();
        $email = OutgoingEmail::query()->sole();
        $this->assertSame(['number_rent_short', 'ana@example.com'], [$email->kind, $email->to_address]); // owners only
        $this->assertSame(__('emails.kinds.number_rent_short.subject', ['brand' => config('kit.brand.name')]), $email->subject);
        $this->assertStringContainsString('+918045671101, +918045671102', $email->body['text']);
        $this->assertStringNotContainsString('+918045671103', $email->body['text']);
        $this->assertStringContainsString('545.80 INR', $email->body['text']);
        $this->assertStringContainsString('500.00 INR', $email->body['text']);
        $this->assertStringContainsString($a->next_renewal_at->toDateString(), $email->body['text']);
        // Once for that renewal.
        $this->assertSame(0, $renewals->warn($this->acme->fresh()));
        // Its next renewal, short again: once more, for it alone.
        $b->forceFill(['next_renewal_at' => now()->addDays(6)])->save();
        $this->assertSame(1, $renewals->warn($this->acme->fresh()));
        $this->assertSame(2, OutgoingEmail::query()->count());
        // Covered: none.
        $b->forceFill(['next_renewal_at' => now()->addDays(4)])->save();
        $this->acme->forceFill(['balance' => '5000'])->save();
        $this->assertSame(0, $renewals->warn($this->acme->fresh()));
        // Not for an account that is not active, nor while charging is off.
        $this->acme->forceFill(['balance' => '0', 'status' => 'suspended'])->save();
        $this->assertSame(0, $renewals->warn($this->acme->fresh()));
        $this->acme->forceFill(['status' => 'active'])->save();
        PriceList::query()->delete();
        $this->assertSame(0, $renewals->warn($this->acme->fresh()));
        $this->assertSame(2, OutgoingEmail::query()->count());
        $this->assertNotNull($a->fresh());
    }
}
