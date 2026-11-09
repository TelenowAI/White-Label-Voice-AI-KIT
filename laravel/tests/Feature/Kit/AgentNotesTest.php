<?php

namespace Tests\Feature\Kit;

use App\Kit\Calls\AgentNotes;
use App\Models\Call;
use App\Models\CallAgentNote;
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
 * A manager telling the agent something during a live call (App\Kit\Calls\AgentNotes; contract/kit-api.yaml tellAgent, getCall's `canTellAgent` and
 * `agentNotes`): sent at once with the account's own key, kept and shown on the call with who wrote it; only managers, only live calls; Telenow's
 * refusals in the kit's words. Telenow answers as recorded on its live release (contract_k22notes.py: a web chat and a phone call with stand-ins).
 */
class AgentNotesTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private User $ana;

    private User $ben;

    private Workspace $acme;

    private Call $live;

    /** What a note answers next: a recording's name or a closure. */
    private string|\Closure $answer = 'addContextNote.next-turn';

    /** @var list<array{path: string, body: mixed, key: ?string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => (string) Str::uuid(), 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'owner']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ben->id, 'role' => 'member']);
        $this->live = $this->aCall('active');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $this->sent[] = ['path' => (string) parse_url($r->url(), PHP_URL_PATH), 'body' => $r->data(), 'key' => $r->header('X-API-Key')[0] ?? null];

            return $this->answer instanceof \Closure ? ($this->answer)() : RecordedTelenow::response($this->answer);
        });
    }

    private function aCall(string $status, ?Workspace $in = null): Call
    {
        $call = (new Call)->forceFill(['workspace_id' => ($in ?? $this->acme)->id, 'telenow_id' => (string) Str::uuid(), 'status' => $status, 'channel' => 'phone',
            'detail_state' => 'pending', 'reads' => 0]);
        $call->save();

        return $call;
    }

    private function tell(array $body, ?Call $call = null, ?User $who = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($who ?? $this->ana)->postJson('/api/kit/console/calls/'.($call ?? $this->live)->id.'/agent-notes', $body);
    }

    public function test_a_manager_tells_the_agent_and_the_call_keeps_what_it_was_told(): void
    {
        $got = $this->tell(['text' => '  Order 1042 shipped this morning.  '])->assertStatus(202);
        $this->assertSame(['by' => 'Ana', 'text' => 'Order 1042 shipped this morning.', 'sayNow' => false, 'delivery' => 'nextReply'],
            array_intersect_key($got->json('note'), array_flip(['by', 'text', 'sayNow', 'delivery'])));
        $this->assertSame([['/api/sessions/'.$this->live->telenow_id.'/context', ['text' => 'Order 1042 shipped this morning.', 'respond' => 'none'], self::KEY]],
            array_map(fn ($s) => [$s['path'], $s['body'], $s['key']], $this->sent));

        $this->answer = 'addContextNote.speaking-now';
        $this->tell(['text' => 'Tell them the clinic closes at 6 today.', 'sayNow' => true])->assertStatus(202)->assertJsonPath('note.delivery', 'sayingNow')->assertJsonPath('note.sayNow', true);
        $this->assertSame('when_idle', end($this->sent)['body']['respond']);
        $this->answer = fn () => Http::response(['success' => true, 'data' => ['noteId' => (string) Str::uuid(), 'key' => null, 'delivery' => 'held']], 202);
        $this->tell(['text' => 'They are on hold for billing.'])->assertStatus(202)->assertJsonPath('note.delivery', 'whenBack');

        // Another live call's note is that call's alone; called from code, a note is trimmed too.
        $other = $this->aCall('active');
        $this->answer = 'addContextNote.next-turn';
        app(AgentNotes::class)->tell(WorkspaceMember::query()->where('user_id', $this->ana->id)->firstOrFail(), $other, ['text' => '  On the other line.  ']);
        $this->assertSame('On the other line.', end($this->sent)['body']['text']);
        $this->assertSame(['On the other line.'], array_column($this->actingAs($this->ana)->getJson("/api/kit/console/calls/{$other->id}")->json('agentNotes'), 'text'));

        $asBen = $this->actingAs($this->ben)->getJson('/api/kit/console/calls/'.$this->live->id)->assertOk();
        $this->assertFalse($asBen->json('canTellAgent'), 'a member who does not manage the account');
        $this->assertSame(['They are on hold for billing.', 'Tell them the clinic closes at 6 today.', 'Order 1042 shipped this morning.'], array_column($asBen->json('agentNotes'), 'text'),
            'every member sees what the agent was told, newest first');
        $this->assertTrue($this->actingAs($this->ana)->getJson('/api/kit/console/calls/'.$this->live->id)->json('canTellAgent'));
        $this->assertStringNotContainsString($this->live->telenow_id, $asBen->getContent());
    }

    public function test_only_managers_and_only_live_calls_of_the_account(): void
    {
        $this->tell(['text' => 'Hello'], null, $this->ben)->assertForbidden()->assertExactJson(['message' => __('calls.note_only_managers')]);
        $ended = $this->aCall('ended');
        $this->tell(['text' => 'Hello'], $ended)->assertStatus(409)->assertExactJson(['message' => __('calls.listen_call_over')]);
        $this->assertFalse($this->actingAs($this->ana)->getJson("/api/kit/console/calls/{$ended->id}")->json('canTellAgent'));
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $this->tell(['text' => 'Hello'], $this->aCall('active', $other))->assertNotFound();
        $this->tell(['text' => '   '])->assertStatus(422)->assertJsonPath('errors.text.0', __('calls.note_empty'));
        $this->tell([])->assertStatus(422)->assertJsonValidationErrors(['text']);
        $this->tell(['text' => str_repeat('a', config('kit.calls.agent_note_max_length') + 1)])->assertStatus(422)->assertJsonValidationErrors(['text']);
        $this->tell(['text' => 'Hello', 'sayNow' => 'loud'])->assertStatus(422)->assertJsonValidationErrors(['sayNow']);
        $this->acme->forceFill(['telenow_key' => null])->save();
        $this->tell(['text' => 'Hello'])->assertStatus(409);
        $this->assertSame([], $this->sent, 'nothing sent');
        $this->assertSame(0, CallAgentNote::query()->count());
    }

    public function test_the_platform_s_refusals_in_the_kit_s_words_and_nothing_kept(): void
    {
        $this->answer = 'addContextNote.no-agent';
        $this->tell(['text' => 'Hello'])->assertStatus(409)->assertExactJson(['message' => __('calls.note_no_agent')]);
        $this->answer = fn () => Http::response(['error' => 'engine_unsupported', 'success' => false], 409);
        $this->tell(['text' => 'Hello'])->assertStatus(409)->assertExactJson(['message' => __('calls.note_not_taken')]);
        $this->answer = 'addContextNote.too-large';
        $this->tell(['text' => 'Hello'])->assertStatus(422)->assertJsonPath('errors.text.0', __('calls.note_too_long', ['max' => 24787]));
        $this->answer = 'addContextNote.empty';
        $this->tell(['text' => 'Hello'])->assertStatus(422)->assertJsonPath('errors.text.0', __('calls.note_empty'));
        $this->answer = fn () => throw new ConnectionException('unreachable');
        $this->tell(['text' => 'Hello'])->assertStatus(503)->assertExactJson(['message' => __('calls.note_unavailable')]);
        $this->assertSame(0, CallAgentNote::query()->count());

        foreach (['addContextNote.not-live', 'addContextNote.unknown-call'] as $over) {
            $this->live->forceFill(['next_read_at' => null])->save();
            $this->answer = $over;
            $this->tell(['text' => 'Hello'])->assertStatus(409)->assertExactJson(['message' => __('calls.listen_call_over')]);
            $this->assertNotNull($this->live->fresh()->next_read_at, 'over at the platform: read again soon');
        }
        $this->assertSame(0, CallAgentNote::query()->count());
        $this->assertSame([], $this->actingAs($this->ana)->getJson('/api/kit/console/calls/'.$this->live->id)->json('agentNotes'));
    }
}
