<?php

namespace App\Kit\Agents;

use App\Kit\Email\Emails;
use App\Kit\Email\Senders;
use App\Models\Agent;
use App\Models\Call;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Emailing a finished call's summary to people on the customer's team, through the operator's own sender, once the call is read as far as it will be. Telenow's own after-call email goes through a
 * Google or Microsoft account signed in on its dashboard, which a customer here never sees, so this is the kit's. It only goes to members of the account, who can see the call anyway: never to an
 * address a customer types, which would let anyone send mail from the operator's domain. The operator words it in Admin -> Email -> Templates.
 *
 * Each call is told once (`after_call_emailed_at`), and an old call first read late tells nobody (config('kit.agents.after_call_email_max_age_hours')).
 *
 * Not here: reading calls (App\Kit\Calls\Calls, which calls send), sending email (App\Kit\Email).
 */
final class AfterCallEmail
{
    public function __construct(private readonly Emails $emails, private readonly Senders $senders) {}

    /** @return array{recipients: list<int>, onlyAnswered: bool} */
    public function of(Agent $agent): array
    {
        $saved = is_array($agent->after_call_email) ? $agent->after_call_email : [];

        return ['recipients' => array_values(array_map('intval', (array) ($saved['recipients'] ?? []))), 'onlyAnswered' => ($saved['onlyAnswered'] ?? true) !== false];
    }

    /** @return ?array{recipients: list<int>, onlyAnswered: bool} what `$input['settings']` sets, null when it says nothing of this */
    public function validated(array $input, Agent $agent): ?array
    {
        if (! array_key_exists('emailAfterCall', (array) ($input['settings'] ?? []))) {
            return null;
        }
        $at = 'settings.emailAfterCall';
        $values = Validator::make($input, [
            $at => ['array'],
            "{$at}.recipients" => ['present', 'array', 'max:'.config('kit.agents.limits.after_call_email_recipients_max')],
            "{$at}.recipients.*" => ['integer'],
            "{$at}.onlyAnswered" => ['sometimes', 'boolean'],
        ], [], __('agents.email_fields'))->validate()['settings']['emailAfterCall'];
        $can = $this->reachable($agent->workspace_id, array_map('intval', $values['recipients']));
        $errors = [];
        foreach (array_values($values['recipients']) as $i => $id) {
            if (! isset($can[(int) $id])) {
                $errors["{$at}.recipients.{$i}"] = [__('agents.email_not_a_member')];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $recipients = array_values(array_unique(array_map('intval', $values['recipients'])));
        sort($recipients);

        return ['recipients' => $recipients, 'onlyAnswered' => (bool) ($values['onlyAnswered'] ?? true)];
    }

    /** Tell this agent's chosen people about a call that is now read as far as it will be. */
    public function send(Call $call): void
    {
        $agent = $call->agent;
        if ($agent === null || ! $this->emails->on()) {
            return;
        }
        $setting = $this->of($agent);
        if ($setting['recipients'] === [] || ($setting['onlyAnswered'] && ! $this->answered($call)) || $call->started_at === null
            || $call->started_at->lt(now()->subHours(config('kit.agents.after_call_email_max_age_hours')))) {
            return;
        }
        // Claimed first: a read happening twice at once tells people once.
        if (Call::query()->whereKey($call->id)->whereNull('after_call_emailed_at')->update(['after_call_emailed_at' => now()]) !== 1) {
            return;
        }
        $vars = $this->vars($agent, $call);
        foreach ($this->reachable($agent->workspace_id, $setting['recipients']) as $member) {
            $this->emails->send('call_summary', $member->user->email, ['name' => $member->user->name] + $vars, $this->senders->link("console/calls/{$call->id}"));
        }
    }

    /** A person was on it: it lasted, and no machine picked up. Telenow's outcome is an open set of words (`answered`, `no-answer`, `busy`, `failed`, `machine`, or none on a browser call), so it cannot say. */
    private function answered(Call $call): bool
    {
        return (int) $call->duration_sec > 0 && ! str_starts_with((string) $call->answered_by, config('kit.agents.machine_answer_prefix'));
    }

    /** @return array<int, WorkspaceMember> the members among `$ids` with an address to send to, by user: someone who left or signed up by phone has none here */
    private function reachable(string $workspaceId, array $ids): array
    {
        return WorkspaceMember::query()->with('user')->where('workspace_id', $workspaceId)->whereIn('user_id', $ids)->get()
            ->filter(fn (WorkspaceMember $m) => is_string($m->user?->email) && $m->user->email !== '')->keyBy('user_id')->all();
    }

    /** @return array<string, string> */
    private function vars(Agent $agent, Call $call): array
    {
        $summary = is_string($call->analysis['summary'] ?? null) ? trim($call->analysis['summary']) : '';
        $number = $call->direction === 'outbound' ? $call->to_number : $call->from_number;
        $seconds = (int) $call->duration_sec;
        $outcome = (string) $call->outcome;
        $words = (array) __('emails.call_outcomes');

        return [
            'account' => $agent->workspace->name,
            'agent' => $agent->name,
            'caller' => is_string($number) && $number !== '' ? $number : __('emails.call_no_number'),
            'when' => $call->started_at->format(config('kit.email.date_format')),
            'duration' => __('emails.call_duration', ['minutes' => (int) floor($seconds / 60), 'seconds' => $seconds % 60]),
            'outcome' => $outcome === '' ? '' : __('emails.call_outcome', ['outcome' => $words[$outcome] ?? str_replace(['_', '-'], ' ', $outcome)]),
            'summary' => $summary !== '' ? $summary : __('emails.call_no_summary'),
        ];
    }
}
