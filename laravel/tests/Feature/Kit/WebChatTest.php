<?php

namespace Tests\Feature\Kit;

use App\Kit\Chat\WebChats;
use App\Kit\Settings\Settings;
use App\Models\Agent;
use App\Models\AgentWebChat;
use App\Models\Call;
use App\Models\ChatConversation;
use App\Models\PriceList;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Web chat (App\Kit\Chat; contract/kit-api.yaml getAgentWebChat, updateAgentWebChat, getChatBox, sendChatMessage, getChatMessages,
 * endChatConversation, saveCatalogCuration webChatAllowed): the operator's yes, a customer's chat box and its websites, a visitor's conversation
 * passed on to Telenow's chat API with the account's own key and answered in this platform's words, the limits, ending conversations (kit:chats),
 * and a chat read as a call that has no recording to wait for. Telenow answers as recorded on its live release (contract_k15chat.py).
 */
class WebChatTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const OPERATOR = 'vai_test_operator_admin';

    private const AGENT = '7a1c0e5d-2b3f-4c6a-9d8e-1f2a3b4c5d6e';

    private const SESSION = 'cd59dbbd-b19e-5940-8eb3-b1fa23d56392';

    private const SITE = 'https://www.clinic.example';

    private User $staff;

    private User $ben;

    private Workspace $acme;

    private Agent $agent;

    /** @var list<array{method: string, path: string, body: array, key: ?string}> what reached Telenow's chat API */
    private array $sent = [];

    /** What chatSend answers next: recordings' names or closures (none left: the first turn). */
    private array $turns = [];

    /** What chatEnd answers next (none left: ended). */
    private array $ends = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.api_key', self::OPERATOR, secret: true);
        app(Settings::class)->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ben->id, 'role' => 'member']);
        $this->agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Front desk']);
        $this->agent->forceFill(['telenow_id' => self::AGENT, 'sync_state' => 'synced', 'is_active' => true])->save();
        $this->answering();
    }

    private function answering(): void
    {
        $recorded = RecordedTelenow::responder();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use ($recorded) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if (! str_starts_with($path, '/api/v1/chat')) {
                return $recorded($r);
            }
            $this->sent[] = ['method' => $r->method(), 'path' => $path, 'body' => $r->data(), 'key' => $r->header('X-API-Key')[0] ?? null];
            if ($path === '/api/v1/chat') {
                $next = array_shift($this->turns) ?? 'chatSend.first-turn';

                return $next instanceof \Closure ? $next($r) : RecordedTelenow::response($next);
            }
            if (str_ends_with($path, '/messages')) {
                return RecordedTelenow::response('chatMessages.transcript');
            }
            $next = array_shift($this->ends) ?? 'chatEnd.ended';

            return $next instanceof \Closure ? $next($r) : RecordedTelenow::response($next);
        });
    }

    /** A first turn as recorded, starting the session `$session`. */
    private static function started(string $session): \Closure
    {
        return function () use ($session) {
            $body = RecordedTelenow::body('chatSend.first-turn');
            $body['sessionId'] = $session;

            return Http::response($body);
        };
    }

    private function allow(bool $allowed = true): void
    {
        app(WebChats::class)->allow($allowed);
    }

    private function save(array $input): TestResponse
    {
        return $this->actingAs($this->ben)->putJson("/api/kit/console/agents/{$this->agent->id}/web-chat", $input);
    }

    private function on(array $websites = [self::SITE]): string
    {
        $this->allow();
        $this->save(['enabled' => true, 'websites' => $websites])->assertOk();

        return (string) AgentWebChat::query()->where('agent_id', $this->agent->id)->value('public_key');
    }

    private function visitor(string $method, string $path, array $body = [], ?string $origin = self::SITE): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders($origin === null ? [] : ['Origin' => $origin])->json($method, $path, $body);
    }

    private function say(string $key, string $message, ?string $conversation = null, ?string $origin = self::SITE): TestResponse
    {
        return $this->visitor('POST', "/api/kit/chat/{$key}/messages", ['message' => $message] + ($conversation === null ? [] : ['conversation' => $conversation]), $origin);
    }

    private function pricing(array $rules): void
    {
        PriceList::create(['id' => 'standard', 'name' => 'Standard', 'definition' => ['id' => 'standard', 'currency' => 'INR', 'decimals' => 2, 'rounding' => 'half-up', 'billingIncrementSeconds' => 60, 'rules' => $rules]]);
        $settings = app(Settings::class);
        $settings->put('pricing.currency', 'INR');
        $settings->put('pricing.usd_rate', '83.00');
        $settings->put('pricing.default_plan', 'standard');
        $this->acme->forceFill(['balance' => '500'])->save();
    }

    public function test_none_is_on_until_the_operator_offers_web_chat(): void
    {
        $this->actingAs($this->ben)->getJson("/api/kit/console/agents/{$this->agent->id}/web-chat")->assertOk()
            ->assertExactJson(['allowed' => false, 'priced' => true, 'enabled' => false, 'websites' => [], 'title' => null, 'greeting' => null, 'snippet' => null]);
        $this->save(['enabled' => true, 'websites' => [self::SITE]])->assertStatus(409)->assertExactJson(['message' => __('chat.not_allowed')]);
        $this->save(['enabled' => false, 'websites' => [self::SITE], 'title' => 'Ask us'])->assertOk()->assertJsonPath('snippet', null)->assertJsonPath('websites', [self::SITE]);
        $this->assertNull(AgentWebChat::query()->value('public_key'), 'no key until it is first turned on');

        // The operator offers it (Admin → Catalog), recorded in the audit log; taking it back stops every chat box at once.
        $catalog = $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->assertOk();
        $this->assertFalse($catalog->json('webChatAllowed'));
        $this->assertSame([], $catalog->json('webChatUnpriced'));
        $this->artisan('kit:catalog')->assertSuccessful();
        $offer = ['kinds' => ['llm' => ['openai' => ['offered' => true]], 'stt' => ['deepgram' => ['offered' => true]], 'tts' => ['telenow' => ['offered' => true, 'name' => 'Studio voices']]],
            'defaults' => ['llmProvider' => 'openai', 'llmModel' => 'gpt-4o-mini', 'sttProvider' => 'deepgram', 'ttsProvider' => 'telenow', 'ttsVoice' => 'vikram-en-in']];
        $this->actingAs($this->staff)->putJson('/api/kit/admin/catalog', $offer + ['webChatAllowed' => true])->assertOk()->assertJsonPath('webChatAllowed', true);
        $this->assertTrue(app(WebChats::class)->allowed());
        $this->assertDatabaseHas('audit_events', ['action' => 'chat.allowed']);
        $key = $this->on();
        $this->visitor('GET', "/api/kit/chat/{$key}")->assertOk();
        $this->allow(false);
        $this->visitor('GET', "/api/kit/chat/{$key}")->assertNotFound()->assertExactJson(['message' => __('chat.not_found')]);
        $this->say($key, 'Hello')->assertNotFound();
        $this->assertSame([], $this->sent);
    }

    public function test_a_customer_turns_a_chat_box_on_for_their_websites(): void
    {
        $this->allow();
        $saved = $this->save(['enabled' => true, 'websites' => ['https://WWW.Clinic.example/', 'http://localhost:5500', 'https://clinic.example:443', 'https://www.clinic.example'],
            'title' => '  Ask the clinic ', 'greeting' => 'Hi! How can we help?'])->assertOk();
        $this->assertSame(['https://www.clinic.example', 'http://localhost:5500', 'https://clinic.example'], $saved->json('websites'), 'as a browser names them, once each');
        $this->assertSame('Ask the clinic', $saved->json('title'));
        $key = (string) AgentWebChat::query()->value('public_key');
        $this->assertSame(config('kit.chat.key_length'), strlen($key));
        $this->assertSame('<script src="'.url('chat/widget.js').'" data-key="'.$key.'" async></script>', $saved->json('snippet'));
        $this->assertTrue($saved->json('enabled'));

        // Turned off and on again: the same key, so the code on the customer's pages keeps working.
        $this->save(['enabled' => false, 'websites' => [self::SITE]])->assertOk()->assertJsonPath('enabled', false);
        $this->save(['enabled' => true, 'websites' => [self::SITE]])->assertOk();
        $this->assertSame($key, AgentWebChat::query()->value('public_key'));

        // Another account's agent is not there for Ben.
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = Agent::create(['workspace_id' => $other->id, 'name' => 'Theirs']);
        $this->actingAs($this->ben)->getJson("/api/kit/console/agents/{$theirs->id}/web-chat")->assertNotFound();
    }

    public function test_a_website_is_its_address_alone(): void
    {
        $this->allow();
        $bad = ['http://clinic.example', 'https://clinic.example/contact', 'https://clinic.example/?a=1', 'https://clinic.example/#top', 'clinic.example',
            'https://ana@clinic.example', 'https://203.0.113.5', 'ftp://clinic.example', 'http://127.0.0.2', 'https://nodot'];
        $errors = $this->save(['enabled' => true, 'websites' => $bad])->assertStatus(422)->json('errors');
        $this->assertSame(array_map(fn (int $i) => "websites.{$i}", array_keys($bad)), array_keys($errors));
        $this->assertSame(__('chat.website_invalid'), $errors['websites.0'][0]);
        $this->save(['enabled' => true, 'websites' => []])->assertStatus(422)->assertJsonPath('errors.websites.0', __('chat.websites_required'));
        $many = array_map(fn (int $i) => "https://site{$i}.example", range(1, config('kit.chat.websites_max') + 1));
        $this->save(['enabled' => true, 'websites' => $many])->assertStatus(422)->assertJsonValidationErrors(['websites']);
        $this->save(['enabled' => true, 'websites' => ['https://bücher.example']])->assertOk()->assertJsonPath('websites', ['https://xn--bcher-kva.example']);
        $this->save(['enabled' => true, 'websites' => [self::SITE], 'title' => str_repeat('t', config('kit.chat.title_max_length') + 1)])->assertStatus(422)->assertJsonValidationErrors(['title']);
        $this->assertSame([], $this->sent);
    }

    public function test_turning_one_on_needs_the_agent_on_the_platform_and_a_price_for_a_chat(): void
    {
        $this->allow();
        $this->agent->forceFill(['telenow_id' => null])->save();
        $this->save(['enabled' => true, 'websites' => [self::SITE]])->assertStatus(409)->assertExactJson(['message' => __('chat.agent_not_ready')]);
        $this->agent->forceFill(['telenow_id' => self::AGENT])->save();

        // Customers charged, on a plan that prices phone calls only: no chat box, and the operator sees which plan.
        $this->pricing([['id' => 'phone', 'match' => ['item' => 'component', 'callType' => 'agent_voice', 'component' => 'telephony'], 'price' => ['perMinute' => '2.50']]]);
        $this->actingAs($this->ben)->getJson("/api/kit/console/agents/{$this->agent->id}/web-chat")->assertJsonPath('priced', false);
        $this->save(['enabled' => true, 'websites' => [self::SITE]])->assertStatus(409)->assertExactJson(['message' => __('chat.not_priced')]);
        $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->assertJsonPath('webChatUnpriced', [['id' => 'standard', 'name' => 'Standard']]);

        PriceList::query()->whereKey('standard')->update(['definition' => json_encode(['id' => 'standard', 'currency' => 'INR', 'decimals' => 2, 'rounding' => 'half-up', 'billingIncrementSeconds' => 60,
            'rules' => [['id' => 'chat', 'match' => ['item' => 'call', 'channel' => 'chat'], 'price' => ['markupPercent' => '40']]]])]);
        $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->assertJsonPath('webChatUnpriced', []);
        $this->save(['enabled' => true, 'websites' => [self::SITE]])->assertOk()->assertJsonPath('priced', true);
    }

    public function test_a_visitor_chats_with_the_agent_through_this_platform(): void
    {
        $key = $this->on();
        $this->save(['enabled' => true, 'websites' => [self::SITE], 'greeting' => 'Hi! How can we help?'])->assertOk();
        $box = $this->visitor('GET', "/api/kit/chat/{$key}")->assertOk()->assertHeader('Access-Control-Allow-Origin');
        $this->assertSame(['title' => 'Front desk', 'greeting' => 'Hi! How can we help?', 'messageMaxLength' => config('kit.chat.message_max_length')],
            array_intersect_key($box->json(), array_flip(['title', 'greeting', 'messageMaxLength'])), "no title shows the agent's name");
        $this->assertSame(__('chat.words'), $box->json('words'));
        $this->assertNull($box->json('logoUrl'));
        \Illuminate\Support\Facades\Storage::fake(config('kit.agents.logo.disk'));
        app(\App\Kit\Agents\AgentLogos::class)->set($this->agent, \Tests\Support\TestImages::file('logo.png', \Tests\Support\TestImages::png(48, 48)));
        $logo = $this->visitor('GET', "/api/kit/chat/{$key}")->assertOk()->json('logoUrl');
        $this->assertSame(url('/api/kit/media/agent-logos/'.$this->agent->id.'/'.basename($this->agent->fresh()->logo)), $logo, "the agent's logo, from this platform");
        $this->assertSame(config('kit.brand.colors')['primary'], $box->json('colors.primary'));

        $first = $this->say($key, '  Hello, are you open on Saturday?  ')->assertOk();
        $token = $first->json('conversation');
        $this->assertSame(config('kit.chat.conversation_token_length'), strlen($token));
        $this->assertSame(RecordedTelenow::body('chatSend.first-turn')['reply'], $first->json('reply'));
        $this->assertSame(['conversation', 'reply'], array_keys($first->json()));
        $conversation = ChatConversation::query()->sole();
        $this->assertSame(['method' => 'POST', 'path' => '/api/v1/chat', 'body' => ['agentId' => self::AGENT, 'identifier' => 'kit-'.$conversation->id, 'input' => 'Hello, are you open on Saturday?'], 'key' => self::KEY], $this->sent[0]);
        $this->assertSame(hash('sha256', $token), $conversation->token_hash, 'only its hash is kept');
        $this->assertSame([self::SITE, self::SESSION, 1], [$conversation->website, $conversation->telenow_id, $conversation->messages]);
        $call = Call::query()->sole();
        $this->assertSame([$this->acme->id, $this->agent->id, self::SESSION, 'chat', 'web', 'active', 'pending'],
            [$call->workspace_id, $call->agent_id, $call->telenow_id, $call->channel, $call->direction, $call->status, $call->detail_state]);
        $this->assertSame($call->id, $conversation->call_id);

        $this->turns = ['chatSend.next-turn'];
        $next = $this->say($key, 'Thanks, and on Sunday?', $token)->assertOk()->assertExactJson(['conversation' => $token, 'reply' => RecordedTelenow::body('chatSend.next-turn')['reply']]);
        $this->assertSame(['agentId' => self::AGENT, 'identifier' => 'kit-'.$conversation->id, 'input' => 'Thanks, and on Sunday?', 'sessionId' => self::SESSION], $this->sent[1]['body']);
        $this->assertSame(2, $conversation->fresh()->messages);

        // The conversation again on another page: the visitor's and the agent's, oldest first.
        $said = $this->visitor('GET', "/api/kit/chat/{$key}/messages?conversation={$token}")->assertOk();
        $recorded = RecordedTelenow::body('chatMessages.transcript')['messages'];
        $this->assertSame(['visitor', 'agent'], [$said->json('messages.0.role'), $said->json('messages.1.role')]);
        $this->assertSame(array_column($recorded, 'content'), array_column($said->json('messages'), 'text'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $said->json('messages.0.at'));

        // Nothing of the voice platform reaches the visitor.
        foreach ([$box, $first, $next, $said] as $answer) {
            $this->assertStringNotContainsString(self::SESSION, $answer->getContent());
            $this->assertStringNotContainsString(self::AGENT, $answer->getContent());
        }
    }

    public function test_a_chat_box_answers_only_on_its_websites(): void
    {
        $key = $this->on([self::SITE, 'https://shop.clinic.example']);
        $this->visitor('GET', "/api/kit/chat/{$key}", [], null)->assertForbidden()->assertExactJson(['message' => __('chat.website_not_allowed')]);
        $this->visitor('GET', "/api/kit/chat/{$key}", [], 'https://elsewhere.example')->assertForbidden();
        $this->visitor('GET', "/api/kit/chat/{$key}", [], 'null')->assertForbidden();
        $this->say($key, 'Hello', null, 'https://elsewhere.example')->assertForbidden();
        $this->visitor('GET', '/api/kit/chat/no-such-key')->assertNotFound()->assertExactJson(['message' => __('chat.not_found')]);
        $this->visitor('GET', '/api/kit/chat/'.str_repeat('k', 300))->assertNotFound();
        $this->assertSame([], $this->sent);

        // The browser asks first; the answer lets the script read every answer.
        $this->withHeaders(['Origin' => self::SITE, 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'content-type'])
            ->options("/api/kit/chat/{$key}/messages")->assertNoContent()->assertHeader('Access-Control-Allow-Origin');

        // A conversation goes on only from the website it started on.
        $token = $this->say($key, 'Hello')->assertOk()->json('conversation');
        $this->say($key, 'Again', $token, 'https://shop.clinic.example')->assertForbidden();
        $this->visitor('GET', "/api/kit/chat/{$key}/messages?conversation={$token}", [], 'https://shop.clinic.example')->assertForbidden();
        $this->visitor('POST', "/api/kit/chat/{$key}/end", ['conversation' => $token], 'https://shop.clinic.example')->assertForbidden();
        $this->say($key, 'Again', 'not-a-conversation')->assertNotFound()->assertExactJson(['message' => __('chat.no_conversation')]);
        $this->visitor('GET', "/api/kit/chat/{$key}/messages")->assertNotFound();
        $this->assertCount(1, $this->sent);

        // Another chat box's conversation is not this one's.
        $theirs = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Sales']);
        $theirs->forceFill(['telenow_id' => '9b9b9b9b-2b3f-4c6a-9d8e-1f2a3b4c5d6e', 'sync_state' => 'synced', 'is_active' => true])->save();
        $this->actingAs($this->ben)->putJson("/api/kit/console/agents/{$theirs->id}/web-chat", ['enabled' => true, 'websites' => [self::SITE]])->assertOk();
        $other = (string) AgentWebChat::query()->where('agent_id', $theirs->id)->value('public_key');
        $this->say($other, 'Hello', $token)->assertNotFound();
    }

    public function test_a_chat_box_of_an_agent_that_takes_no_calls_does_not_answer(): void
    {
        $key = $this->on();
        foreach ([['is_active' => false], ['telenow_id' => null], ['deleted_at' => now()]] as $change) {
            $this->agent->forceFill($change)->save();
            $this->visitor('GET', "/api/kit/chat/{$key}")->assertNotFound();
            $this->agent->forceFill(['is_active' => true, 'telenow_id' => self::AGENT, 'deleted_at' => null])->save();
        }
        $this->save(['enabled' => false, 'websites' => [self::SITE]])->assertOk();
        $this->say($key, 'Hello')->assertNotFound();
        $this->assertSame([], $this->sent);
    }

    public function test_a_message_is_checked_before_it_goes(): void
    {
        $key = $this->on();
        $this->say($key, '   ')->assertStatus(422)->assertJsonPath('errors.message.0', __('chat.message_required'));
        $this->say($key, str_repeat('a', config('kit.chat.message_max_length') + 1))->assertStatus(422)->assertJsonPath('errors.message.0', __('chat.message_too_long'));
        $this->visitor('POST', "/api/kit/chat/{$key}/messages", ['message' => ['not', 'text']])->assertStatus(422);
        $this->say($key, str_repeat('a', config('kit.chat.message_max_length')))->assertOk();

        // The account is paused (here, suspended): it cannot answer, and nothing goes.
        $sent = count($this->sent);
        $this->acme->forceFill(['status' => Workspace::SUSPENDED])->save();
        $this->say($key, 'Hello')->assertStatus(503)->assertExactJson(['message' => __('chat.unavailable')]);
        $this->acme->forceFill(['status' => 'active'])->save();

        // Customers charged: out of credit, or on a plan with no price for a chat (the plan changed after it was turned on).
        $this->pricing([['id' => 'chat', 'match' => ['item' => 'call', 'channel' => 'chat'], 'price' => ['markupPercent' => '40']]]);
        $this->acme->forceFill(['balance' => '0'])->save();
        $this->say($key, 'Hello')->assertStatus(503);
        $this->acme->forceFill(['balance' => '500'])->save();
        PriceList::query()->whereKey('standard')->update(['definition' => json_encode(['id' => 'standard', 'currency' => 'INR', 'decimals' => 2, 'rounding' => 'half-up', 'billingIncrementSeconds' => 60, 'rules' => []])]);
        $this->say($key, 'Hello')->assertStatus(503);
        $this->assertCount($sent, $this->sent);
    }

    public function test_the_voice_platforms_refusals_reach_the_visitor_in_this_platforms_words(): void
    {
        $key = $this->on();
        $token = $this->say($key, 'Hello')->assertOk()->json('conversation');
        $this->turns = ['chatSend.in-progress'];
        $this->say($key, 'Hello again?', $token)->assertStatus(409)->assertExactJson(['message' => __('chat.still_answering')]);
        foreach (['chatSend.at-limit', 'chatSend.suspended', 'chatSend.agent-paused', 'chatSend.not-this-visitor', 'chatSend.no-session'] as $refusal) {
            $this->turns = [$refusal];
            $answer = $this->say($key, 'Hello again?', $token)->assertStatus(503)->assertExactJson(['message' => __('chat.unavailable')]);
            $this->assertStringNotContainsString('sub-account', $answer->getContent(), $refusal);
        }
        $this->turns = [fn () => throw new ConnectionException('unreachable')];
        $this->say($key, 'Hello again?', $token)->assertStatus(503);
        $this->turns = [self::started('5a8b551e-1712-412d-923e-820c399fbb6f')];
        $this->say($key, 'Hello again?', $token)->assertStatus(503)->assertExactJson(['message' => __('chat.unavailable')]);
        $this->assertSame(self::SESSION, ChatConversation::query()->sole()->telenow_id, "another session's answer is not this conversation's");
        $this->turns = ['chatSend.model-fails'];
        $this->say($key, 'Please fail this one', $token)->assertOk()->assertJsonPath('reply', RecordedTelenow::body('chatSend.model-fails')['reply']);

        // Ended there (recorded: also when the account is suspended): ended here too, already ended there, and the script starts afresh.
        $this->turns = ['chatSend.ended'];
        $this->say($key, 'Are you still there?', $token)->assertStatus(410)->assertExactJson(['message' => __('chat.ended')]);
        $conversation = ChatConversation::query()->sole();
        $this->assertSame('platform', $conversation->end_reason);
        $this->assertNotNull($conversation->telenow_ended_at);
        $this->say($key, 'Hello?', $token)->assertStatus(410);
        $this->visitor('GET', "/api/kit/chat/{$key}/messages?conversation={$token}")->assertStatus(410);
        $this->assertCount(0, array_filter($this->sent, fn (array $s) => str_ends_with($s['path'], '/end')), 'Telenow ended it itself');
    }

    public function test_messages_too_fast_or_too_many_are_refused(): void
    {
        config(['kit.chat.messages_per_minute' => 2, 'kit.chat.conversations_per_hour' => 2, 'kit.chat.messages_max' => 3]);
        $key = $this->on();
        $this->turns = [self::started(self::SESSION), self::started('5a8b551e-1712-412d-923e-820c399fbb6f')];
        $one = $this->say($key, 'Hello')->assertOk()->json('conversation');
        $this->say($key, 'Hello')->assertOk();
        $this->say($key, 'Hello')->assertStatus(429)->assertExactJson(['message' => __('chat.too_fast')]);
        $this->travel(2)->minutes();
        $this->say($key, 'A third conversation')->assertStatus(429);
        $this->turns = ['chatSend.next-turn', 'chatSend.next-turn'];
        $this->say($key, 'Same conversation', $one)->assertOk();
        $this->say($key, 'Same conversation', $one)->assertOk();
        $this->travel(2)->minutes();
        $this->say($key, 'One too many', $one)->assertStatus(410)->assertExactJson(['message' => __('chat.too_many_messages')]);
        $this->assertSame('limit', ChatConversation::query()->where('telenow_id', self::SESSION)->value('end_reason'), 'ended, and its place at the platform given back');
        $this->assertSame('/api/v1/chat/'.self::SESSION.'/end', end($this->sent)['path']);
        $this->say($key, 'After the end', $one)->assertStatus(410);
        $this->assertCount(5, $this->sent);

        // Another address has its own count; all of an account's chat boxes share one.
        config(['kit.chat.account_messages_per_minute' => 5]);
        $this->travel(2)->hours();
        $this->turns = [self::started('6b6b6b6b-1712-412d-923e-820c399fbb6f'), self::started('7c7c7c7c-1712-412d-923e-820c399fbb6f')];
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->say($key, 'From elsewhere')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])->say($key, 'From elsewhere')->assertOk();
        config(['kit.chat.account_messages_per_minute' => 2]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->say($key, 'One more')->assertStatus(429);
    }

    public function test_conversations_end_and_the_voice_platform_is_told(): void
    {
        $key = $this->on();
        $token = $this->say($key, 'Hello')->assertOk()->json('conversation');
        $this->visitor('POST', "/api/kit/chat/{$key}/end", ['conversation' => $token])->assertOk()->assertExactJson(['ended' => true]);
        $this->assertSame(['POST', '/api/v1/chat/'.self::SESSION.'/end', self::KEY], [end($this->sent)['method'], end($this->sent)['path'], end($this->sent)['key']]);
        $conversation = ChatConversation::query()->sole();
        $this->assertSame('visitor', $conversation->end_reason);
        $this->assertNotNull($conversation->telenow_ended_at);
        $this->assertTrue(Call::query()->sole()->next_read_at->lte(now()), 'its call is read soon');
        $sent = count($this->sent);
        $this->visitor('POST', "/api/kit/chat/{$key}/end", ['conversation' => $token])->assertOk();
        $this->assertCount($sent, $this->sent, 'ended once');
        $this->say($key, 'Hello?', $token)->assertStatus(410);
        $this->visitor('POST', "/api/kit/chat/{$key}/end", [])->assertStatus(422);
        $this->visitor('POST', "/api/kit/chat/{$key}/end", ['conversation' => 'nope'])->assertNotFound();

        // Nobody wrote for a while: kit:chats ends it, and tells the voice platform until it has it ended.
        $this->turns = [self::started('5a8b551e-1712-412d-923e-820c399fbb6f')];
        $this->say($key, 'Hello')->assertOk();
        $this->travel(config('kit.chat.idle_minutes') - 1)->minutes();
        $this->artisan('kit:chats')->assertSuccessful();
        $this->assertNull(ChatConversation::query()->where('telenow_id', '5a8b551e-1712-412d-923e-820c399fbb6f')->value('ended_at'), 'not idle long enough');
        $this->travel(2)->minutes();
        $this->ends = [fn () => throw new ConnectionException('unreachable')];
        $this->artisan('kit:chats')->assertSuccessful();
        $idle = ChatConversation::query()->where('telenow_id', '5a8b551e-1712-412d-923e-820c399fbb6f')->sole();
        $this->assertSame(['idle', null], [$idle->end_reason, $idle->telenow_ended_at]);
        $this->ends = ['chatEnd.not-a-chat'];
        $this->artisan('kit:chats')->assertSuccessful();
        $this->assertNotNull($idle->fresh()->telenow_ended_at, 'one the platform does not have is ended');

        // Its chat box turned off: an open conversation ends.
        $this->turns = [self::started('835e1b81-19a8-4d3a-8565-1bd3c94d5c90')];
        $this->say($key, 'Hello')->assertOk();
        $this->save(['enabled' => false, 'websites' => [self::SITE]])->assertOk();
        $this->artisan('kit:chats')->assertSuccessful();
        $off = ChatConversation::query()->where('telenow_id', '835e1b81-19a8-4d3a-8565-1bd3c94d5c90')->sole();
        $this->assertSame('off', $off->end_reason);
        $this->assertNotNull($off->telenow_ended_at);

        // Web chat no longer offered: every open conversation ends.
        $this->save(['enabled' => true, 'websites' => [self::SITE]])->assertOk();
        $this->turns = [self::started('6b6b6b6b-1712-412d-923e-820c399fbb6f')];
        $this->say($key, 'Hello')->assertOk();
        $this->allow(false);
        $this->artisan('kit:chats')->assertSuccessful();
        $this->assertSame('off', ChatConversation::query()->where('telenow_id', '6b6b6b6b-1712-412d-923e-820c399fbb6f')->value('end_reason'));
    }

    public function test_a_chat_is_read_as_a_call_with_no_recording_to_wait_for(): void
    {
        $key = $this->on();
        $this->say($key, 'Hello')->assertOk();
        $org = $this->acme->telenow_sub_account_id;
        $calls = RecordedTelenow::responder();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use ($calls, $org) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);

            return match ($path) {
                "/api/orgs/{$org}/calls/".self::SESSION => RecordedTelenow::response('getCall.chat-ended'),
                "/api/orgs/{$org}/calls/".self::SESSION.'/cost' => RecordedTelenow::response('getCallCost.chat'),
                default => $calls($r),
            };
        });
        $this->assertSame('pending', RecordedTelenow::body('getCall.chat-ended')['data']['completeness']['recording'], 'Telenow never stops waiting for it');
        $call = app(\App\Kit\Calls\Calls::class)->read($this->acme, self::SESSION);
        $this->assertSame(['final', 'ended', 'chat'], [$call->detail_state, $call->status, $call->channel]);
        $this->assertNotEmpty($call->transcript);

        // A phone call in the same state still waits for its recording.
        $phone = RecordedTelenow::body('getCall.chat-ended');
        $phone['data']['channel'] = 'telephony';
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => Http::response($phone));
        $call->forceFill(['detail_state' => 'pending', 'reads' => 0])->save();
        $this->assertSame('pending', app(\App\Kit\Calls\Calls::class)->read($this->acme, self::SESSION)->detail_state);
    }
}
