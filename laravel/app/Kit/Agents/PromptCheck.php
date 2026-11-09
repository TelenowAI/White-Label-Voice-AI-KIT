<?php

namespace App\Kit\Agents;

use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Agent;
use App\Models\Workspace;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The prompt check (contract/kit-api.yaml checkPrompt; owner's choice 11-09: a check and a preview, and a save with errors refused in the kit). An
 * agent's instructions and opening line checked as the voice platform checks them (`validatePrompt`, the account's own key, no model called):
 * conditional sections (`{#if {plan} == "gold"}` … `{#else}` … `{/if}`) that do not open and close properly are errors — Telenow refuses to save
 * such a prompt (recorded: `prompt_sections_malformed`) — and the rest are warnings (a near-miss tag, a condition on a name without braces or on a
 * variable nothing sets, a value that is none of a choice's, a section in a spoken line), each with its line. Given sample values, the
 * instructions as a call with them would get them, and which branch each section took.
 *
 * - check(): what the console's check shows. The agent's own declared variables, its tools' names and, for an agent with call steps, the flow's
 *   base prompt and what its steps capture are sent with it, so the findings are the agent's; a new agent has none of them.
 * - refuseBroken(): on a save that changes the instructions, before anything is sent: a ValidationException at `systemPrompt` with each error,
 *   when Telenow says the sections are broken. When the check cannot be made (the platform out of reach, or a prompt longer than it checks), the
 *   save goes on: Telenow's own save refuses what this would have.
 *
 * Not here: a call step's own instructions (AgentFlow, checked with the flow), the agent's save (Agents).
 */
final class PromptCheck
{
    public function __construct(private readonly TelenowClient $telenow, private readonly AgentSettings $settings) {}

    /**
     * @return array{checked: bool, valid: bool, errors: list<array>, warnings: list<array>, preview: ?array{text: string, sections: list<array{line: int, branch: ?int, branches: int}>}}
     */
    public function check(Workspace $workspace, ?Agent $agent, array $input): array
    {
        $c = config('kit.agents.prompt_check');
        $values = Validator::make($input, [
            'systemPrompt' => ['present', 'nullable', 'string', 'max:'.config('kit.agents.prompt_max_length')],
            'greeting' => ['sometimes', 'nullable', 'string', 'max:'.config('kit.agents.prompt_max_length')],
            'values' => ['sometimes', 'nullable', 'array', 'max:'.$c['values_max']],
            'values.*' => ['nullable', 'string', 'max:'.$c['value_max_length']],
            'channel' => ['sometimes', 'nullable', Rule::in($c['channels'])],
        ], [], __('agents.prompt_check.fields'))->validate();
        $prompt = (string) ($values['systemPrompt'] ?? '');
        $greeting = $values['greeting'] ?? null;
        if (mb_strlen($prompt) > $c['check_max_length'] || mb_strlen((string) $greeting) > $c['check_max_length']) {
            return ['checked' => false, 'valid' => true, 'errors' => [], 'warnings' => [], 'preview' => null];
        }
        $body = ['prompt' => $prompt] + ($greeting === null || $greeting === '' ? [] : ['greeting' => $greeting]) + $this->context($agent);
        if (array_key_exists('values', $values) && $values['values'] !== null) {
            $body['values'] = (object) array_filter($values['values'], fn ($v) => $v !== null);
            if (($values['channel'] ?? null) !== null) {
                $body['channel'] = $values['channel'];
            }
        }
        try {
            $answer = $this->telenow->validatePrompt((string) $workspace->telenow_key, $body);
        } catch (TelenowError $e) {
            if ($e->status === 400) {
                return ['checked' => false, 'valid' => true, 'errors' => [], 'warnings' => [], 'preview' => null]; // more than it checks
            }
            throw new AgentRefused($e->status === 401 ? 409 : 503, __($e->status === 401 ? 'agents.account_not_ready' : 'agents.prompt_check.unavailable'));
        }

        return [
            'checked' => true,
            'valid' => ($answer['valid'] ?? false) === true,
            'errors' => $this->findings($answer['errors'] ?? []),
            'warnings' => $this->findings($answer['warnings'] ?? []),
            'preview' => is_string($answer['rendered'] ?? null) ? ['text' => $answer['rendered'], 'sections' => array_map(fn (array $d) => [
                'line' => (int) ($d['line'] ?? 0),
                // Telenow counts branches from 0, and none taken is null: the kit's are counted from 1, as people count them.
                'branch' => is_int($d['branch'] ?? null) ? $d['branch'] + 1 : null,
                'branches' => (int) ($d['branches'] ?? 0),
            ], array_values(array_filter((array) ($answer['decisions'] ?? []), 'is_array')))] : null,
        ];
    }

    /** Refuse instructions whose sections Telenow would refuse, before they are saved. */
    public function refuseBroken(Workspace $workspace, ?Agent $agent, ?string $prompt): void
    {
        if ($prompt === null || ! str_contains($prompt, '{') || ! is_string($workspace->telenow_key)
            || mb_strlen($prompt) > config('kit.agents.prompt_check.check_max_length')) {
            return; // nothing a section could be made of, or nothing to check it with: Telenow's save checks it
        }
        try {
            $answer = $this->telenow->validatePrompt($workspace->telenow_key, ['prompt' => $prompt] + $this->context($agent));
        } catch (TelenowError) {
            return;
        }
        if (($answer['valid'] ?? true) === false) {
            throw ValidationException::withMessages(['systemPrompt' => array_column($this->findings($answer['errors'] ?? []), 'message') ?: [__('agents.prompt_check.broken')]]);
        }
    }

    /** What the agent itself brings to a check: its declared variables, its tools, and its call steps. */
    private function context(?Agent $agent): array
    {
        if ($agent === null) {
            return [];
        }
        $settings = $this->settings->of($agent);
        $body = [];
        $declared = array_values(array_filter((array) ($agent->telenow_snapshot['metadata']['variables'] ?? []), 'is_array'));
        if ($declared !== []) {
            $body['variables'] = $declared;
        }
        $tools = array_values(array_unique(array_filter(array_column((array) ($settings['tools'] ?? []), 'name'), 'is_string')));
        if ($tools !== []) {
            $body['tools'] = $tools;
        }
        $flow = $settings['flow'] ?? null;
        if (is_array($flow) && ($flow['steps'] ?? []) !== []) {
            $body['kind'] = 'flowBase';
            $captured = [];
            foreach ($flow['steps'] as $step) {
                foreach ((array) ($step['capture'] ?? []) as $capture) {
                    if (is_string($capture['name'] ?? null) && $capture['name'] !== '') {
                        $captured[] = $capture['name'];
                    }
                }
            }
            if ($captured !== []) {
                $body['writtenBySteps'] = array_values(array_unique($captured));
            }
        }

        return $body;
    }

    /** @return list<array{field: string, code: string, line: int, column: int, message: string}> Telenow's findings, under the kit's field names */
    private function findings(mixed $rows): array
    {
        return array_values(array_map(fn (array $f) => [
            'field' => ($f['field'] ?? 'prompt') === 'greeting' ? 'greeting' : 'systemPrompt',
            'code' => (string) ($f['code'] ?? ''),
            'line' => (int) ($f['line'] ?? 0),
            'column' => (int) ($f['column'] ?? 0),
            'message' => (string) ($f['message'] ?? ''),
        ], array_filter(is_array($rows) ? $rows : [], 'is_array')));
    }
}
