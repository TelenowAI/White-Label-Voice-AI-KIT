<?php

namespace App\Kit\Agents;

use App\Kit\Calls\DialCheck;
use App\Kit\Console\ServicePause;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Agent;
use App\Models\Call;
use App\Models\PhoneNumber;
use App\Models\WorkspaceMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * An agent calling one person now, from an agent's page: a member gives the number, which of the account's numbers to call from (the agent's own unless another is picked),
 * the details the agent's words use, and, if they want, another opening line and what to do when a machine answers. The kit's server places the call with the account's own
 * key (`initiateCall`) and keeps it at once, with who asked for it, to be read like any other once it ends.
 *
 * What the kit checks before Telenow is asked:
 * - the account can use the service now (ServicePause), and the agent is saved at Telenow;
 * - the number, as a call from the browser is (App\Kit\Calls\DialCheck): Telenow dials whatever it is given, words included (recorded), so only a number with its + and
 *   country code goes, not on the do-not-call list, with consent on file while calls need it;
 * - the caller ID is one of the account's own live numbers, and the agent has one of its own when none is picked (Telenow refuses either otherwise: recorded);
 * - the details are ones the agent uses, and those it needs are filled.
 * Telenow checks its own lists again (403); the kit says so in its own words, since its refusals differ only in their English.
 *
 * Every call is sent with an Idempotency-Key of its own: a try after a lost answer gets the first answer, so nobody is called twice (recorded). Not through the outbox: a call
 * is now or never, and a person is waiting on the page. Charged like any call; one the plan cannot price waits on the operator's price list, as a campaign's would.
 *
 * Not here: a test call in the browser (TestCall), many people at once (campaigns), placing a call from the browser (App\Kit\Softphone).
 */
final class AgentCalls
{
    public function __construct(
        private readonly TelenowClient $telenow,
        private readonly ServicePause $pause,
        private readonly DialCheck $dial,
        private readonly AgentSettings $settings,
        private readonly AgentRequest $requests,
    ) {}

    /** @return array{callerIds: list<array{id: string, number: string}>, ownNumber: ?string, variables: list<array{name: string, required: bool}>, machineAnswers: list<string>, paused: ?string, limits: array<string, int>} what the form offers */
    public function form(Agent $agent): array
    {
        $c = config('kit.agent_calls');

        return [
            'callerIds' => $this->callerIds($agent)->map(fn (PhoneNumber $n) => ['id' => $n->id, 'number' => '+'.Call::digits($n->e164)])->values()->all(),
            'ownNumber' => $this->ownNumber($agent)?->id,
            'variables' => $this->variables($agent),
            'machineAnswers' => array_keys($c['machine_answers']),
            'paused' => $this->pause->for($agent->workspace)['message'] ?? null,
            'limits' => ['numberMaxLength' => $c['number_max_length'], 'variableValueMaxLength' => $c['variable_value_max_length'], 'openingLineMaxLength' => $c['opening_line_max_length']],
        ];
    }

    public function place(WorkspaceMember $member, Agent $agent, array $input): Call
    {
        $workspace = $agent->workspace;
        if ($agent->telenow_id === null) {
            throw new AgentRefused(409, __('agents.not_ready'));
        }
        $paused = $this->pause->for($workspace);
        if ($paused !== null) {
            throw new AgentRefused(409, $paused['message']);
        }
        $c = config('kit.agent_calls');
        $wanted = $this->variables($agent);
        $values = Validator::make($input, [
            'number' => ['required', 'string', 'max:'.$c['number_max_length']],
            'callerId' => ['nullable', 'string'],
            'variables' => ['nullable', 'array'],
            'variables.*' => ['nullable', 'string', 'max:'.$c['variable_value_max_length']],
            'openingLine' => ['nullable', 'string', 'max:'.$c['opening_line_max_length']],
            'machine' => ['nullable', 'string', Rule::in(array_keys($c['machine_answers']))],
        ], [], ['number' => __('agents.call.fields.number'), 'callerId' => __('agents.call.fields.callerId'), 'variables' => __('agents.call.fields.variables'),
            'openingLine' => __('agents.call.fields.openingLine'), 'machine' => __('agents.call.fields.machine')])->validate();
        $given = array_filter($values['variables'] ?? [], fn ($v) => is_string($v) && $v !== '');
        $errors = [];
        foreach (array_keys($given) as $name) {
            if (! in_array($name, array_column($wanted, 'name'), true)) {
                $errors["variables.{$name}"] = [__('agents.call.not_a_detail')];
            }
        }
        foreach ($wanted as $variable) {
            if ($variable['required'] && ! isset($given[$variable['name']])) {
                $errors["variables.{$variable['name']}"] = [__('agents.call.details_missing')];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $to = $this->dial->callable($workspace, 'number', $values['number']);
        $from = isset($values['callerId'])
            ? (Str::isUuid($values['callerId']) ? $this->callerIds($agent)->firstWhere('id', $values['callerId']) : null)
            : $this->ownNumber($agent);
        if ($from === null) {
            throw ValidationException::withMessages(['callerId' => [__(isset($values['callerId']) ? 'agents.call.pick_caller_id' : 'agents.call.agent_has_no_number')]]);
        }
        $body = ['agentId' => $agent->telenow_id, 'mobileNumber' => $to, 'fromNumberId' => $from->telenow_id]
            + ($given === [] ? [] : ['variables' => $given])
            + (isset($values['openingLine']) ? ['firstResponse' => $values['openingLine']] : [])
            + (isset($values['machine']) ? ['machineDetection' => $c['machine_answers'][$values['machine']]] : []);
        $answer = $this->send($workspace->telenow_key, $body);
        $id = $answer['sessionId'] ?? null;
        if (! is_string($id) || ! Str::isUuid($id)) {
            throw new AgentRefused(503, __('agents.call.unavailable'));
        }
        $call = Call::query()->where('telenow_id', $id)->first() ?? (new Call)->forceFill(['workspace_id' => $workspace->id, 'telenow_id' => $id]);
        $call->forceFill(['requested_by' => $member->user_id] + ($call->exists ? [] : [
            'agent_id' => $agent->id,
            'status' => $answer['status'] ?? null,
            'direction' => $c['kept_as']['direction'],
            'channel' => $c['kept_as']['channel'],
            'from_number' => $from->e164,
            'to_number' => $to,
            'started_at' => now(),
            'detail_state' => 'pending',
            'reads' => 0,
            'next_read_at' => now()->addSeconds(config('kit.calls.reread_base_seconds')),
        ]))->save();

        return $call;
    }

    /** @return list<array{name: string, required: bool}> the details an agent's words use: those declared on it first (with whether it needs them), then each `{placeholder}` of its instructions and outbound opening line, but those Telenow or a look-up fills */
    public function variables(Agent $agent): array
    {
        $settings = $this->settings->of($agent);
        $filled = [];
        foreach ((array) ($settings['lookups'] ?? []) as $lookup) {
            foreach ((array) ($lookup['fills'] ?? []) as $fill) {
                $filled[] = $fill['variable'] ?? null;
            }
        }
        $skip = array_merge(config('kit.agents.platform_variables'), $filled);
        $out = [];
        foreach ((array) ($agent->telenow_snapshot['metadata']['variables'] ?? []) as $declared) {
            $name = is_array($declared) ? ($declared['name'] ?? null) : null;
            if (is_string($name) && ! in_array($name, $skip, true) && ! isset($out[$name])) {
                $out[$name] = ['name' => $name, 'required' => ($declared['required'] ?? false) === true];
            }
        }
        $texts = [(string) $agent->system_prompt, (string) ($this->settings->line($settings['greetingOutbound'] ?? null) ?? '')];
        foreach ($texts as $text) {
            foreach (array_keys($this->requests->placeholders($text)) as $name) {
                if (! in_array($name, $skip, true) && ! isset($out[$name])) {
                    $out[$name] = ['name' => $name, 'required' => false];
                }
            }
        }

        return array_values($out);
    }

    /** Sent once, and once more with the same key if the answer was lost on the way. */
    private function send(string $key, array $body): array
    {
        $idempotency = (string) Str::uuid();
        try {
            try {
                return $this->telenow->initiateCall($key, $body, $idempotency);
            } catch (TelenowError $e) {
                if ($e->status !== 0) {
                    throw $e;
                }

                return $this->telenow->initiateCall($key, $body, $idempotency);
            }
        } catch (TelenowError $e) {
            throw match ($e->status) {
                400 => ValidationException::withMessages(['variables' => [__('agents.call.details_missing')]]),
                403 => ValidationException::withMessages(['number' => [__('agents.call.refused')]]),
                404 => new AgentRefused(409, __('agents.not_ready')),
                429 => new AgentRefused(429, __('agents.call.caller_id_busy')),
                default => new AgentRefused(503, __('agents.call.unavailable')),
            };
        }
    }

    /** The account's numbers a call can come from: its own, not being released, known to the voice platform. */
    private function callerIds(Agent $agent): Collection
    {
        return PhoneNumber::query()->where('workspace_id', $agent->workspace_id)->whereNull('released_at')->whereNotNull('telenow_id')->orderBy('e164')->get();
    }

    private function ownNumber(Agent $agent): ?PhoneNumber
    {
        return PhoneNumber::query()->where('workspace_id', $agent->workspace_id)->where('agent_id', $agent->id)->whereNull('released_at')->whereNotNull('telenow_id')->orderBy('e164')->first();
    }
}
