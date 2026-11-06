<?php

namespace App\Kit\Calls;

use App\Models\Call;
use App\Models\CallFollowup;
use App\Models\CallNote;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * What members write on a call: notes, a wrap-up (a short label for how it went, from the account's own recent ones or new), and follow-ups (something to do, by when),
 * each on the member's own list until it is done. Any call of the account, an agent's or one placed from the browser; every member may write and see them, and any
 * member may mark a follow-up done, so a colleague can finish one. Nothing is changed or removed but a wrap-up, which is replaced.
 *
 * The kit's own, never sent to Telenow: its comments and wrap-ups work with the account's key but name the key's stand-in user, never the member (recorded,
 * contract_k9human.py), and a customer's team lives in the kit.
 *
 * What is typed comes trimmed, and empty as none, as every request to the kit does.
 *
 * Not here: the call itself (Calls), placing one (App\Kit\Softphone), the routes (CallWorkController).
 */
final class CallWork
{
    /** @return array{wrapup: ?array, notes: list<array>, followUps: list<array>, suggestions: list<string>, limits: array<string, int>} */
    public function of(Call $call, WorkspaceMember $member): array
    {
        $notes = CallNote::query()->with('author')->where('call_id', $call->id)->orderBy('created_at')->orderBy('id')->get();
        $followUps = CallFollowup::query()->with(['owner', 'finisher'])->where('call_id', $call->id)->orderBy('created_at')->orderBy('id')->get();
        $call->loadMissing('wrapupAuthor');

        return [
            'wrapup' => $call->wrapup === null ? null : ['label' => $call->wrapup, 'by' => $call->wrapupAuthor?->name, 'at' => $call->wrapup_at?->toIso8601String()],
            'notes' => $notes->map(fn (CallNote $n) => ['id' => $n->id, 'body' => $n->body, 'by' => $n->author?->name, 'mine' => (int) $n->user_id === (int) $member->user_id, 'at' => $n->created_at->toIso8601String()])->all(),
            'followUps' => $followUps->map(fn (CallFollowup $f) => $this->followUp($f, $member))->all(),
            'suggestions' => $this->suggestions($call->workspace),
            'limits' => ['noteMaxLength' => config('kit.calls.note_max_length'), 'wrapupMaxLength' => config('kit.calls.wrapup_max_length'), 'followUpMaxLength' => config('kit.calls.followup_max_length')],
        ];
    }

    public function addNote(Call $call, User $by, array $input): CallNote
    {
        $values = Validator::make($input, ['body' => ['required', 'string', 'max:'.config('kit.calls.note_max_length')]], [], ['body' => __('calls.fields.body')])->validate();
        if (CallNote::query()->where('call_id', $call->id)->count() >= config('kit.calls.notes_per_call_max')) {
            throw new CallWorkRefused(409, __('calls.too_many_notes'));
        }
        $note = new CallNote;
        $note->forceFill(['workspace_id' => $call->workspace_id, 'call_id' => $call->id, 'user_id' => $by->id, 'body' => $values['body']])->save();

        return $note;
    }

    /** A wrap-up, or none to take it off. */
    public function setWrapup(Call $call, User $by, array $input): void
    {
        $values = Validator::make($input, ['wrapup' => ['present', 'nullable', 'string', 'max:'.config('kit.calls.wrapup_max_length')]], [], ['wrapup' => __('calls.fields.wrapup')])->validate();
        $call->forceFill($values['wrapup'] === null
            ? ['wrapup' => null, 'wrapup_by' => null, 'wrapup_at' => null]
            : ['wrapup' => $values['wrapup'], 'wrapup_by' => $by->id, 'wrapup_at' => now()])->save();
    }

    public function addFollowUp(Call $call, User $by, array $input): CallFollowup
    {
        $values = Validator::make($input, [
            'body' => ['required', 'string', 'max:'.config('kit.calls.followup_max_length')],
            'dueAt' => ['required', 'date'],
        ], [], ['body' => __('calls.fields.followup'), 'dueAt' => __('calls.fields.dueAt')])->validate();
        if (CallFollowup::query()->where('call_id', $call->id)->count() >= config('kit.calls.followups_per_call_max')) {
            throw new CallWorkRefused(409, __('calls.too_many_followups'));
        }
        $followUp = new CallFollowup;
        $followUp->forceFill([
            'workspace_id' => $call->workspace_id, 'call_id' => $call->id, 'user_id' => $by->id, 'body' => $values['body'],
            'due_at' => Carbon::parse($values['dueAt'])->utc(),
        ])->save();

        return $followUp;
    }

    /** Done, by whoever says so, or open again. */
    public function setDone(CallFollowup $followUp, User $by, array $input): CallFollowup
    {
        $values = Validator::make($input, ['done' => ['required', 'boolean']], [], ['done' => __('calls.fields.done')])->validate();
        $followUp->forceFill($values['done']
            ? ['done_at' => $followUp->done_at ?? now(), 'done_by' => $followUp->done_by ?? $by->id]
            : ['done_at' => null, 'done_by' => null])->save();

        return $followUp;
    }

    /** A member's own follow-ups: those still to do soonest first, or those done latest first. */
    public function mine(WorkspaceMember $member, bool $done, int $page): LengthAwarePaginator
    {
        $query = CallFollowup::query()->with(['owner', 'finisher', 'call'])->where('workspace_id', $member->workspace_id)->where('user_id', $member->user_id);
        $query = $done ? $query->whereNotNull('done_at')->orderByDesc('done_at') : $query->whereNull('done_at')->orderBy('due_at');

        return $query->orderBy('id')->paginate(config('kit.calls.followups_per_page'), ['*'], 'page', $page);
    }

    /** @return array<string, mixed> one follow-up as the console shows it, with the call it is about */
    public function followUp(CallFollowup $f, WorkspaceMember $member): array
    {
        $call = $f->relationLoaded('call') ? $f->call : null;

        return [
            'id' => $f->id, 'body' => $f->body, 'dueAt' => $f->due_at->toIso8601String(), 'by' => $f->owner?->name, 'mine' => (int) $f->user_id === (int) $member->user_id,
            'doneAt' => $f->done_at?->toIso8601String(), 'doneBy' => $f->finisher?->name, 'createdAt' => $f->created_at->toIso8601String(),
        ] + ($call === null ? [] : ['call' => ['id' => $call->id, 'direction' => $call->direction, 'from' => $call->from_number, 'to' => $call->to_number, 'startedAt' => $call->started_at?->toIso8601String()]]);
    }

    /** @return list<string> the account's latest wrap-ups, each once, newest first */
    public function suggestions(Workspace $workspace): array
    {
        $recent = Call::query()->where('workspace_id', $workspace->id)->whereNotNull('wrapup_at')->orderByDesc('wrapup_at')
            ->limit(config('kit.calls.wrapup_suggestions_read'))->pluck('wrapup');

        return $recent->unique(fn (string $w) => mb_strtolower($w))->take(config('kit.calls.wrapup_suggestions'))->values()->all();
    }
}
