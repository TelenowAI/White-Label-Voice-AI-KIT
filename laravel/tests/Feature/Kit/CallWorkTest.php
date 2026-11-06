<?php

namespace Tests\Feature\Kit;

use App\Models\Call;
use App\Models\CallFollowup;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * What members write on a call (App\Kit\Calls\CallWork) through the kit's real routes: notes kept as written, a wrap-up replaced or taken off with the account's
 * recent ones offered, follow-ups on their writer's own list that a colleague can tick off, and one person's calls found by their number however it was written.
 * None of it reaches the voice platform, whose comments would name the account's key rather than the member (recorded: contract_k9human.py).
 */
class CallWorkTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $ben;

    private Workspace $acme;

    private Call $call;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => 'vai_test_customer_acme'])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'owner']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ben->id, 'role' => 'member']);
        $this->call = $this->aCall($this->acme, ['direction' => 'inbound', 'from_number' => '+14155550101', 'to_number' => '918045671001']);
        RecordedTelenow::fake();
    }

    private function aCall(Workspace $workspace, array $over = []): Call
    {
        $call = (new Call)->forceFill($over + ['workspace_id' => $workspace->id, 'telenow_id' => (string) Str::uuid(), 'status' => 'ended', 'started_at' => now(), 'detail_state' => 'final']);
        $call->save();

        return $call;
    }

    private function root(?Call $call = null): string
    {
        return '/api/kit/console/calls/'.($call ?? $this->call)->id;
    }

    public function test_notes_are_kept_as_written_by_whoever_wrote_them(): void
    {
        $this->actingAs($this->ben)->postJson($this->root().'/notes', ['body' => '  Wants a call back after five.  '])->assertCreated()
            ->assertJsonPath('notes.0.body', 'Wants a call back after five.')->assertJsonPath('notes.0.by', 'Ben')->assertJsonPath('notes.0.mine', true);
        $this->travel(1)->seconds();
        $this->actingAs($this->ana)->postJson($this->root().'/notes', ['body' => 'Called back, booked Thursday.'])->assertCreated();
        $work = $this->actingAs($this->ben)->getJson($this->root().'/work')->assertOk();
        $this->assertSame([['Wants a call back after five.', 'Ben', true], ['Called back, booked Thursday.', 'Ana', false]],
            array_map(fn (array $n) => [$n['body'], $n['by'], $n['mine']], $work->json('notes')), 'oldest first');

        foreach (['', '   ', str_repeat('x', config('kit.calls.note_max_length') + 1), ['a']] as $bad) {
            $this->actingAs($this->ben)->postJson($this->root().'/notes', ['body' => $bad])->assertUnprocessable()->assertJsonValidationErrors(['body']);
        }
        config(['kit.calls.notes_per_call_max' => 2]);
        $this->actingAs($this->ben)->postJson($this->root().'/notes', ['body' => 'One more'])->assertStatus(409)->assertExactJson(['message' => __('calls.too_many_notes')]);
        $this->assertSame(0, Http::recorded()->count(), 'the kit\'s own: nothing sent');
    }

    public function test_a_wrap_up_is_replaced_or_taken_off_and_the_accounts_recent_ones_are_offered(): void
    {
        $earlier = $this->aCall($this->acme);
        $theirs = $this->aCall(Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']));
        $theirs->forceFill(['wrapup' => 'Their secret label', 'wrapup_at' => now()])->save();
        $this->aCall($this->acme, ['wrapup' => 'BOOKED', 'wrapup_at' => now()->subHour()]);
        $this->actingAs($this->ana)->putJson($this->root($earlier).'/wrapup', ['wrapup' => 'Booked'])->assertOk();
        $this->travel(1)->seconds();
        $this->actingAs($this->ana)->putJson($this->root($earlier).'/wrapup', ['wrapup' => 'No answer'])->assertOk()->assertJsonPath('wrapup.label', 'No answer');
        $this->travel(1)->seconds();
        $this->actingAs($this->ben)->putJson($this->root().'/wrapup', ['wrapup' => ' booked '])->assertOk()
            ->assertJsonPath('wrapup', ['label' => 'booked', 'by' => 'Ben', 'at' => now()->toIso8601String()])
            ->assertJsonPath('suggestions', ['booked', 'No answer']);
        $this->actingAs($this->ana)->getJson($this->root())->assertJsonPath('wrapup', 'booked');
        $listed = $this->actingAs($this->ana)->getJson('/api/kit/console/calls')->json('calls');
        $this->assertSame('No answer', collect($listed)->firstWhere('id', $earlier->id)['wrapup']);

        config(['kit.calls.wrapup_suggestions' => 1]);
        $this->actingAs($this->ben)->getJson($this->root().'/work')->assertJsonPath('suggestions', ['booked']);
        foreach ([null, ''] as $none) {
            $this->actingAs($this->ben)->putJson($this->root().'/wrapup', ['wrapup' => 'Booked'])->assertOk();
            $this->actingAs($this->ben)->putJson($this->root().'/wrapup', ['wrapup' => $none])->assertOk()->assertJsonPath('wrapup', null);
            $this->assertSame([null, null, null], [$this->call->fresh()->wrapup, $this->call->fresh()->wrapup_by, $this->call->fresh()->wrapup_at]);
        }
        $this->actingAs($this->ben)->putJson($this->root().'/wrapup', ['wrapup' => str_repeat('x', config('kit.calls.wrapup_max_length') + 1)])->assertUnprocessable()->assertJsonValidationErrors(['wrapup']);
        $this->actingAs($this->ben)->putJson($this->root().'/wrapup', [])->assertUnprocessable()->assertJsonValidationErrors(['wrapup']);
    }

    public function test_follow_ups_are_on_their_writers_list_soonest_first_and_a_colleague_can_tick_one_off(): void
    {
        $add = fn (User $who, string $body, string $due) => $this->actingAs($who)->postJson($this->root().'/followups', ['body' => $body, 'dueAt' => $due]);
        // Three hours from now, against one hour from now written in a zone five and a half hours ahead: read as if UTC, the second would come last.
        $add($this->ben, 'Send the form', now()->addHours(3)->toIso8601String())->assertCreated()->assertJsonPath('followUps.0.mine', true);
        $ringAt = now()->addHour()->startOfSecond();
        $add($this->ben, 'Ring back', $ringAt->copy()->setTimezone('Asia/Kolkata')->toIso8601String())->assertCreated();
        $add($this->ana, 'Check the bill', now()->addMinutes(5)->toIso8601String())->assertCreated();

        $mine = $this->actingAs($this->ben)->getJson('/api/kit/console/followups')->assertOk()->assertJsonPath('total', 2);
        $this->assertSame(['Ring back', 'Send the form'], array_column($mine->json('followUps'), 'body'), 'only his, soonest first, whatever zone it was written in');
        $this->assertSame([$this->call->id, '+14155550101'], [$mine->json('followUps.0.call.id'), $mine->json('followUps.0.call.from')]);
        $this->assertSame($ringAt->toIso8601String(), $mine->json('followUps.0.dueAt'), 'the same moment, in UTC');

        $ring = CallFollowup::query()->where('body', 'Ring back')->sole();
        $this->actingAs($this->ana)->patchJson("/api/kit/console/followups/{$ring->id}", ['done' => true])->assertOk()
            ->assertJsonPath('doneBy', 'Ana')->assertJsonPath('by', 'Ben')->assertJsonPath('mine', false);
        $at = $ring->fresh()->done_at;
        $this->travel(1)->minutes();
        $this->actingAs($this->ben)->patchJson("/api/kit/console/followups/{$ring->id}", ['done' => true])->assertOk()->assertJsonPath('doneBy', 'Ana');
        $this->assertTrue($at->equalTo($ring->fresh()->done_at), 'done twice is done once');
        $this->assertSame(['Send the form'], array_column($this->actingAs($this->ben)->getJson('/api/kit/console/followups')->json('followUps'), 'body'));
        $this->assertSame(['Ring back'], array_column($this->actingAs($this->ben)->getJson('/api/kit/console/followups?done=true')->json('followUps'), 'body'));
        $this->actingAs($this->ben)->patchJson("/api/kit/console/followups/{$ring->id}", ['done' => false])->assertOk()->assertJsonPath('doneAt', null)->assertJsonPath('doneBy', null);

        $add($this->ben, '', now()->toIso8601String())->assertUnprocessable()->assertJsonValidationErrors(['body']);
        $add($this->ben, 'Soon', 'next tuesday-ish')->assertUnprocessable()->assertJsonValidationErrors(['dueAt']);
        $this->actingAs($this->ben)->postJson($this->root().'/followups', ['body' => 'No date'])->assertUnprocessable()->assertJsonValidationErrors(['dueAt']);
        $this->actingAs($this->ben)->patchJson("/api/kit/console/followups/{$ring->id}", ['done' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors(['done']);
        config(['kit.calls.followups_per_call_max' => 3]);
        $add($this->ben, 'One more', now()->toIso8601String())->assertStatus(409)->assertExactJson(['message' => __('calls.too_many_followups')]);
    }

    public function test_another_accounts_call_or_follow_up_is_not_found(): void
    {
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = $this->aCall($other);
        $follow = (new CallFollowup)->forceFill(['workspace_id' => $other->id, 'call_id' => $theirs->id, 'user_id' => $this->ben->id, 'body' => 'x', 'due_at' => now()]);
        $follow->save();
        $this->actingAs($this->ben)->getJson($this->root($theirs).'/work')->assertNotFound();
        $this->actingAs($this->ben)->postJson($this->root($theirs).'/notes', ['body' => 'x'])->assertNotFound();
        $this->actingAs($this->ben)->putJson($this->root($theirs).'/wrapup', ['wrapup' => 'x'])->assertNotFound();
        $this->actingAs($this->ben)->postJson($this->root($theirs).'/followups', ['body' => 'x', 'dueAt' => now()->toIso8601String()])->assertNotFound();
        $this->actingAs($this->ben)->patchJson("/api/kit/console/followups/{$follow->id}", ['done' => true])->assertNotFound();
        $this->actingAs($this->ben)->patchJson('/api/kit/console/followups/not-an-id', ['done' => true])->assertNotFound();
        $this->actingAs($this->ben)->getJson('/api/kit/console/followups')->assertJsonPath('total', 0);
        $this->assertNull($follow->fresh()->done_at);
    }

    public function test_one_persons_calls_are_found_by_their_number_however_it_was_written(): void
    {
        $outbound = $this->aCall($this->acme, ['direction' => 'outbound', 'from_number' => '918045671001', 'to_number' => '14155550101', 'placed_by' => $this->ana->id]);
        $this->aCall($this->acme, ['direction' => 'inbound', 'from_number' => '+14155550199', 'to_number' => '918045671001']);
        $this->aCall(Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']), ['from_number' => '+14155550101']);
        foreach (['+1 (415) 555-0101', '14155550101', '+14155550101'] as $typed) {
            $found = $this->actingAs($this->ben)->getJson('/api/kit/console/calls?'.http_build_query(['number' => $typed]))->assertOk()->assertJsonPath('total', 2);
            $this->assertEqualsCanonicalizing([$this->call->id, $outbound->id], array_column($found->json('calls'), 'id'));
        }
        $this->actingAs($this->ben)->getJson('/api/kit/console/calls?number=0101')->assertJsonPath('total', 0);
        $this->actingAs($this->ben)->getJson('/api/kit/console/calls?number=nobody')->assertJsonPath('total', 0);
        $this->actingAs($this->ben)->getJson('/api/kit/console/calls?placedBy=me')->assertJsonPath('total', 0);
        $this->actingAs($this->ana)->getJson('/api/kit/console/calls?placedBy=me')->assertJsonPath('total', 1)->assertJsonPath('calls.0.id', $outbound->id)
            ->assertJsonPath('calls.0.placedBy', ['name' => 'Ana', 'me' => true]);
        $outbound->forceFill(['to_number' => '+44 20 7946 0000'])->save();
        $this->assertSame('442079460000', $outbound->fresh()->to_digits, 'kept as digits on every save');
    }
}
