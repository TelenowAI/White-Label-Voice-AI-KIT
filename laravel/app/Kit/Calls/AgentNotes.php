<?php

namespace App\Kit\Calls;

use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Call;
use App\Models\CallAgentNote;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A manager telling the agent something during one of the account's live calls (console → a live call → Tell the agent; contract/kit-api.yaml
 * tellAgent). Telenow carries it (`addContextNote`, with the account's own key), recorded on its live release (contract_k22notes.py):
 *
 * - the agent sees it from its next reply, as background it was given, never as something the caller said; it moves nothing in call steps;
 * - asked to say it now, on a phone call the agent says it in its own words as soon as nobody is speaking (`speaking_now`); in a chat it waits for
 *   the next reply; while the agent is away (the caller on hold, a transfer that may come back) it waits until the agent has the call again;
 * - refused for a call that is over, one a person has for good (a call placed from the Phone page, say), an agent that cannot take notes, and a note
 *   longer than fits beside the call so far (how much fits depends on the agent's model: Telenow says, and so does the kit).
 *
 * Only the account's managers may, as with listening in (config('kit.team.managers')). Each note is kept (`call_agent_notes`) and shown on the
 * call's page with who wrote it: what the agent was told is the team's business. Sent at once, never queued: a note for a call that ended is no note.
 * It costs only the model's reading of it (in the call's own charge). Notes add up for the call; none replaces another.
 *
 * Not here: listening in (ListenIn), the kit's own notes on a call (CallWork).
 */
final class AgentNotes
{
    /** Telenow's word for when the agent sees a note, and the kit's. */
    private const DELIVERY = ['next_turn' => 'nextReply', 'held' => 'whenBack', 'speaking_now' => 'sayingNow'];

    public function __construct(private readonly TelenowClient $telenow) {}

    /** Whether this member may tell the agent on this call now: they manage the account and the call is on. */
    public function mayTell(WorkspaceMember $member, Call $call): bool
    {
        return in_array($member->role, config('kit.team.managers'), true) && ! in_array($call->status, config('kit.calls.ended_statuses'), true);
    }

    /** @return array{by: ?string, at: string, text: string, sayNow: bool, delivery: string} the note as kept */
    public function tell(WorkspaceMember $member, Call $call, array $input): array
    {
        if (! in_array($member->role, config('kit.team.managers'), true)) {
            throw new AgentNoteRefused(403, __('calls.note_only_managers'));
        }
        $values = Validator::make($input, [
            'text' => ['required', 'string', 'max:'.config('kit.calls.agent_note_max_length')],
            'sayNow' => ['sometimes', 'boolean'],
        ], ['text.required' => __('calls.note_empty')], __('calls.note_fields'))->validate();
        $text = trim($values['text']);
        if ($text === '') {
            throw ValidationException::withMessages(['text' => [__('calls.note_empty')]]);
        }
        if (in_array($call->status, config('kit.calls.ended_statuses'), true)) {
            throw new AgentNoteRefused(409, __('calls.listen_call_over'));
        }
        $workspace = $call->workspace;
        if (! is_string($workspace->telenow_key) || ! is_string($call->telenow_id)) {
            throw new AgentNoteRefused(409, __('calls.account_not_ready'));
        }
        $sayNow = (bool) ($values['sayNow'] ?? false);
        try {
            $answer = $this->telenow->addContextNote($workspace->telenow_key, $call->telenow_id, $text, $sayNow);
        } catch (TelenowError $e) {
            throw match (true) {
                $e->status === 413 && is_int($e->body['maxChars'] ?? null) => ValidationException::withMessages(['text' => [__('calls.note_too_long', ['max' => $e->body['maxChars']])]]),
                $e->status === 400 => ValidationException::withMessages(['text' => [__('calls.note_empty')]]),
                $e->status === 409 && $e->getMessage() === 'no_agent' => new AgentNoteRefused(409, __('calls.note_no_agent')),
                $e->status === 409 && $e->getMessage() === 'engine_unsupported' => new AgentNoteRefused(409, __('calls.note_not_taken')),
                in_array($e->status, [404, 409], true) => $this->over($call),
                default => new AgentNoteRefused(503, __('calls.note_unavailable')),
            };
        }
        $note = (new CallAgentNote)->forceFill(['workspace_id' => $call->workspace_id, 'call_id' => $call->id, 'user_id' => $member->user_id, 'text' => $text,
            'say_now' => $sayNow, 'delivery' => self::DELIVERY[$answer['delivery'] ?? ''] ?? 'nextReply']);
        $note->save();

        return self::present($note->load('author'));
    }

    /** @return list<array{by: ?string, at: string, text: string, sayNow: bool, delivery: string}> what the agent was told on a call, newest first */
    public function of(Call $call): array
    {
        return CallAgentNote::query()->with('author')->where('call_id', $call->id)->orderByDesc('created_at')->orderByDesc('id')
            ->limit(config('kit.calls.agent_notes_shown'))->get()->map(self::present(...))->all();
    }

    /** The call is over at the platform: read it again soon, so the kit's copy says so too. */
    private function over(Call $call): AgentNoteRefused
    {
        if ($call->detail_state === 'pending') {
            $call->forceFill(['next_read_at' => now()])->save();
        }

        return new AgentNoteRefused(409, __('calls.listen_call_over'));
    }

    private static function present(CallAgentNote $n): array
    {
        return ['by' => $n->author?->name, 'at' => $n->created_at->toIso8601String(), 'text' => $n->text, 'sayNow' => $n->say_now, 'delivery' => $n->delivery];
    }
}
