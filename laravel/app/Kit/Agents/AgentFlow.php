<?php

namespace App\Kit\Agents;

use App\Models\Agent;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * An agent's call steps, in the kit's words: steps, each one part of the call, and exits between them, each with a condition. As Telenow has it this is one JSON graph, `metadata.flow`
 * (recorded by contract_k6flow.py), which it stores as sent and, if a rule is broken or one field has the wrong type, discards on every call with the agent quietly running on its own settings.
 * So the graph is only ever built here, field by field from what config('kit.agents.flow') allows: nothing a customer sends reaches Telenow unless a line below writes it, which also keeps out what must
 * not be set this way, like a step's own model or a transfer tool inside a step.
 *
 * - A draft (`drafted`) checks only that fields are of the right kind, since half-made work is the point of one. Publishing (`checked`) checks the lot.
 * - Keys in a Function step are a tool's: sealed when typed, and held by Telenow after. Telenow keeps a key only while every place in its step and at the top of `metadata` reads the same, so a step has no
 *   line of its own and a step's name cannot hold a web address. A key typed into a draft stays sealed in it until published.
 * - cleaned() takes off what may no longer stay: a transfer step while the operator has them off, and a hand-over to an agent since deleted. A step cannot just go, since exits point at it and one dangling
 *   exit loses the whole graph, so it becomes an ending, saying its no-answer line if it had one.
 *
 * Not here: drafts and publishing (AgentFlows), a tool's fields and checks (AgentTools), a transfer's (AgentSettings), a hand-over's (AgentHandoff).
 */
final class AgentFlow
{
    public function __construct(private readonly AgentTools $tools, private readonly AgentHandoff $handoff, private readonly SavedKeys $keys) {}

    public function has(string $key): bool
    {
        return $key === 'flow';
    }

    /**
     * Steps being worked on: right kinds of field, every step readable, nothing more asked. A key typed is sealed here.
     *
     * @return array<string, mixed>
     */
    public function drafted(array $input, ?array $draft, ?array $published): array
    {
        $flow = $this->shaped($input, false);
        $given = array_values((array) ($input['steps'] ?? []));
        foreach ($flow['steps'] as $i => $step) {
            if (is_array($step['tool'])) {
                $name = (string) ($step['tool']['name'] ?? '');
                $flow['steps'][$i]['tool'] = $this->sealed($given[$i]['tool'] ?? [], $step['tool'], $this->toolIn($draft, $name), $this->toolIn($published, $name));
            }
        }

        return $flow;
    }

    /**
     * Steps ready to publish, in the one shape `read` answers.
     *
     * @return array<string, mixed>
     */
    public function checked(array $input, Agent $agent, ?array $draft, array $now, AgentSettings $settings): array
    {
        $flow = $this->shaped($input, true);
        $errors = [];
        $ids = array_column($flow['steps'], 'id');
        if ($flow['steps'] === []) {
            $errors['flow.steps'] = [__('agents.flow_no_steps')];
        }
        foreach (array_count_values($ids) as $id => $count) {
            if ($count > 1) {
                $errors['flow.steps.'.array_search($id, $ids, true).'.id'] = [__('agents.flow_id_twice')];
            }
        }
        $kinds = array_column($flow['steps'], 'kind', 'id');
        if ($flow['start'] === null || ! isset($kinds[$flow['start']]) || $kinds[$flow['start']] === 'note') {
            $errors['flow.start'] = [__('agents.flow_start')];
        }
        $names = [];
        $given = array_values((array) ($input['steps'] ?? []));
        foreach ($flow['steps'] as $i => $step) {
            $flow['steps'][$i] = $this->checkedStep($step, $given[$i] ?? [], "flow.steps.{$i}", $agent, $draft, $now, $settings, $names, $errors);
        }
        $this->checkExits($flow['exits'], $kinds, $errors);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $flow;
    }

    /** @return array<string, mixed> every field in its one shape, or a refusal beside the field */
    private function shaped(array $input, bool $strict): array
    {
        $l = config('kit.agents.flow.limits');
        $top = Validator::make($input, [
            'start' => ['sometimes', 'nullable', 'string', 'regex:'.$l['id_pattern']],
            'steps' => ['present', 'array', 'max:'.$l['steps_max']],
            'exits' => ['present', 'array', 'max:'.$l['exits_max']],
        ], [], ['start' => __('agents.flow_fields.start'), 'steps' => __('agents.flow_fields.steps'), 'exits' => __('agents.flow_fields.exits')]);
        if ($top->fails()) {
            throw ValidationException::withMessages($this->at('flow', $top->errors()->toArray()));
        }
        $errors = [];
        $steps = [];
        foreach (array_values($input['steps']) as $i => $step) {
            $step = is_array($step) ? $step : [];
            $kind = is_string($step['kind'] ?? null) ? $step['kind'] : '';
            $v = Validator::make($step, $this->stepRules($kind, $strict), ['name.not_regex' => __('agents.flow_name_address')], (array) __('agents.flow_fields'));
            if ($v->fails()) {
                $errors += $this->at("flow.steps.{$i}", $v->errors()->toArray());

                continue;
            }
            $steps[] = $this->step($v->validated());
        }
        $exits = [];
        foreach (array_values($input['exits']) as $i => $exit) {
            $exit = is_array($exit) ? $exit : [];
            $v = Validator::make($exit, $this->exitRules(is_string($exit['when']['kind'] ?? null) ? $exit['when']['kind'] : '', $strict), [], (array) __('agents.flow_fields'));
            if ($v->fails()) {
                $errors += $this->at("flow.exits.{$i}", $v->errors()->toArray());

                continue;
            }
            $exits[] = $this->exit($v->validated());
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['start' => $input['start'] ?? null, 'steps' => $steps, 'exits' => $exits];
    }

    /** @return array<string, list<string>> */
    private function at(string $prefix, array $errors): array
    {
        $out = [];
        foreach ($errors as $field => $messages) {
            $out["{$prefix}.{$field}"] = $messages;
        }

        return $out;
    }

    /** @return array<string, list<mixed>> */
    private function stepRules(string $kind, bool $strict): array
    {
        $f = config('kit.agents.flow');
        $l = $f['limits'];
        $a = config('kit.agents.limits');
        $need = $strict ? 'required' : 'nullable';
        $line = ['sometimes', 'nullable', 'string', 'max:'.$a['line_max_length']];
        $rules = [
            'id' => ['required', 'string', 'regex:'.$l['id_pattern']],
            'kind' => ['required', Rule::in($f['kinds'])],
            'name' => ['sometimes', 'nullable', 'string', 'max:'.$l['name_max_length'], 'not_regex:/:\/\//'],
            'x' => ['sometimes', 'nullable', 'numeric'],
            'y' => ['sometimes', 'nullable', 'numeric'],
            'anywhere' => ['sometimes', 'nullable', 'array'],
            'anywhere.when' => ['required_with:anywhere', 'string', 'max:'.$l['condition_max_length']],
            'anywhere.examples' => ['sometimes', 'array', 'max:'.$l['examples_max']],
            'anywhere.examples.*' => ['string', 'max:'.$l['condition_max_length']],
            'anywhere.goBack' => ['sometimes', 'boolean'],
        ];
        if (in_array($kind, $f['instruction_kinds'], true)) {
            $rules['instructions'] = [$strict && in_array($kind, $f['instructions_needed'], true) ? 'required' : 'nullable', 'string', 'max:'.config('kit.agents.prompt_max_length')];
            $rules['replaceInstructions'] = ['sometimes', 'boolean'];
        }
        if (in_array($kind, $f['line_kinds'], true)) {
            $rules['line'] = in_array($kind, $f['line_needed'], true) && $strict ? ['required', 'string', 'max:'.$a['line_max_length']] : $line;
        }
        if (in_array($kind, $f['continue_kinds'], true)) {
            $rules['continueAfterLine'] = ['sometimes', 'boolean'];
        }
        if (in_array($kind, $f['capture_kinds'], true)) {
            $rules += [
                'capture' => ['sometimes', 'array', 'max:'.$l['capture_max']],
                'capture.*.name' => [$need, 'string', 'regex:'.$a['variable_pattern']],
                'capture.*.type' => ['required', Rule::in($f['capture_types'])],
                'capture.*.description' => ['sometimes', 'nullable', 'string', 'max:'.$a['line_max_length']],
                'capture.*.values' => ['sometimes', 'array', 'max:'.$l['capture_values_max']],
                'capture.*.values.*' => ['string', 'max:'.$l['name_max_length']],
                'capture.*.required' => ['sometimes', 'boolean'],
                'capture.*.format' => ['sometimes', 'nullable', Rule::in($f['capture_formats'])],
            ];
        }
        if ($kind === 'dtmf') {
            $rules += ['keypad' => ['sometimes', 'array'], 'keypad.routeOnly' => ['sometimes', 'boolean'], 'keypad.only' => ['sometimes', 'boolean']];
        }
        if ($kind === 'tool') {
            $tool = $this->tools->rowRules('tool');
            $tool['tool.description'] = ['sometimes', 'nullable', 'string', 'max:'.$a['tool_description_max_length']];
            if (! $strict) {
                $tool = array_map(fn (array $rule) => array_map(fn ($r) => $r === 'required' ? 'nullable' : $r, $rule), $tool);
            }
            $rules += ['tool' => [$need, 'array']] + $tool + [
                'results' => ['sometimes', 'array', 'max:'.$l['results_max']],
                'results.*.path' => [$need, 'string', 'regex:'.$l['result_path_pattern']],
                'results.*.variable' => [$need, 'string', 'regex:'.$a['variable_pattern']],
                'sends' => ['sometimes', 'array', 'max:'.$l['sends_max']],
                'sends.*' => ['string', 'regex:'.$a['variable_pattern']],
            ];
        }
        if ($kind === 'code') {
            $rules += [
                'code' => [$need, 'array'],
                'code.source' => [$need, 'string', 'max:'.$l['code_max_length']],
                'code.timeoutMs' => ['sometimes', 'nullable', 'integer', 'between:'.implode(',', $l['code_timeout_ms'])],
                'code.keep' => ['sometimes', 'array', 'max:'.$l['results_max']],
                'code.keep.*.field' => [$need, 'string', 'regex:'.$a['variable_pattern']],
                'code.keep.*.variable' => [$need, 'string', 'regex:'.$a['variable_pattern']],
            ];
        }
        if ($kind === 'transfer') {
            $rules += [
                'transfer' => [$need, 'array'],
                'transfer.destinations' => ['sometimes', 'array', 'max:'.$a['transfer_destinations_max']],
                'transfer.destinations.*.label' => ['nullable', 'string', 'max:'.$a['transfer_label_max_length']],
                'transfer.destinations.*.numbers' => ['sometimes', 'array', 'max:'.$a['transfer_numbers_max']],
                'transfer.destinations.*.numbers.*' => ['nullable', 'string'],
                'transfer.destinations.*.mode' => ['sometimes', Rule::in(config('kit.agents.transfer_modes'))],
                'transfer.message' => $line,
                'transfer.noAnswerMessage' => $line,
            ];
        }
        if ($kind === 'agent') {
            $rules += ['handoff' => [$need, 'array']] + $this->handoff->rowRules('handoff');
        }

        return $rules;
    }

    /** @return array<string, list<mixed>> */
    private function exitRules(string $kind, bool $strict): array
    {
        $f = config('kit.agents.flow');
        $l = $f['limits'];
        $need = $strict ? 'required' : 'nullable';
        $rules = [
            'id' => ['required', 'string', 'regex:'.$l['id_pattern']],
            'from' => ['required', 'string', 'regex:'.$l['id_pattern']],
            'to' => ['required', 'string', 'regex:'.$l['id_pattern']],
            'label' => ['sometimes', 'nullable', 'string', 'max:'.$l['name_max_length']],
            'when' => ['required', 'array'],
            'when.kind' => ['required', Rule::in($f['exit_kinds'])],
        ];
        $field = match ($kind) {
            'equation' => ['when.expr' => [$need, 'string', 'max:'.$l['condition_max_length']]],
            'ai' => ['when.describe' => [$need, 'string', 'max:'.$l['condition_max_length']]],
            'dtmf' => ['when.digit' => [$need, 'string', 'regex:'.$l['digit_pattern']]],
            'tool_result' => ['when.match' => ['sometimes', 'nullable', 'string', 'max:'.$l['name_max_length']]],
            default => [],
        };

        return $rules + $field;
    }

    /** The one shape of a step, in one order, so what was read and what was sent compare as equal when they are. */
    private function step(array $s): array
    {
        return [
            'id' => $s['id'], 'kind' => $s['kind'], 'name' => $this->text($s['name'] ?? null),
            'x' => $this->place($s['x'] ?? null), 'y' => $this->place($s['y'] ?? null),
            'instructions' => $this->text($s['instructions'] ?? null), 'replaceInstructions' => (bool) ($s['replaceInstructions'] ?? false),
            'line' => $this->text($s['line'] ?? null), 'continueAfterLine' => (bool) ($s['continueAfterLine'] ?? false),
            'capture' => array_map(fn (array $c) => ['name' => $c['name'] ?? null, 'type' => $c['type'], 'description' => $this->text($c['description'] ?? null),
                'values' => $c['type'] === 'enum' ? array_values($c['values'] ?? []) : [], 'required' => (bool) ($c['required'] ?? false), 'format' => $c['type'] === 'string' ? ($c['format'] ?? null) : null], array_values($s['capture'] ?? [])),
            'keypad' => $s['kind'] === 'dtmf' ? ['routeOnly' => (bool) ($s['keypad']['routeOnly'] ?? false), 'only' => (bool) ($s['keypad']['only'] ?? false)] : null,
            'anywhere' => isset($s['anywhere']['when']) ? ['when' => $s['anywhere']['when'], 'examples' => array_values($s['anywhere']['examples'] ?? []), 'goBack' => (bool) ($s['anywhere']['goBack'] ?? false)] : null,
            'tool' => $s['tool'] ?? null, 'results' => array_values($s['results'] ?? []), 'sends' => array_values($s['sends'] ?? []),
            'code' => isset($s['code']) ? ['source' => $s['code']['source'] ?? null, 'timeoutMs' => isset($s['code']['timeoutMs']) ? (int) $s['code']['timeoutMs'] : null, 'keep' => array_values($s['code']['keep'] ?? [])] : null,
            'transfer' => isset($s['transfer']) ? ['destinations' => array_values($s['transfer']['destinations'] ?? []), 'message' => $this->text($s['transfer']['message'] ?? null), 'noAnswerMessage' => $this->text($s['transfer']['noAnswerMessage'] ?? null)] : null,
            'handoff' => isset($s['handoff']) ? ['agents' => array_values($s['handoff']['agents'] ?? []), 'message' => $this->text($s['handoff']['message'] ?? null)] : null,
        ];
    }

    private function exit(array $e): array
    {
        $when = $e['when'];

        return ['id' => $e['id'], 'from' => $e['from'], 'to' => $e['to'], 'label' => $this->text($e['label'] ?? null),
            'when' => ['kind' => $when['kind'], 'expr' => $when['expr'] ?? null, 'describe' => $when['describe'] ?? null, 'digit' => $when['digit'] ?? null, 'match' => $when['kind'] === 'tool_result' ? ($when['match'] ?? 'ok') : null]];
    }

    /** Where a step sits on the map, as one kind of number: a whole one comes back from JSON as an integer, and would read as changed. */
    private function place(mixed $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }
        $at = round((float) $value, 2);

        return $at === floor($at) ? (int) $at : $at;
    }

    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /** One step, ready to publish: what its kind needs, and the checks a tool, transfer or hand-over has anywhere else. */
    private function checkedStep(array $step, array $input, string $at, Agent $agent, ?array $draft, array $now, AgentSettings $settings, array &$names, array &$errors): array
    {
        $seen = [];
        foreach ($step['capture'] as $c => $capture) {
            if (in_array($capture['name'], $seen, true)) {
                $errors["{$at}.capture.{$c}.name"] = [__('agents.flow_capture_twice')];
            }
            $seen[] = $capture['name'];
            if ($capture['type'] === 'enum' && $capture['values'] === []) {
                $errors["{$at}.capture.{$c}.values"] = [__('agents.flow_capture_values')];
            }
        }
        if ($step['kind'] === 'tool') {
            $name = $step['tool']['name'];
            if (in_array($name, $names, true)) {
                $errors["{$at}.tool.name"] = [__('agents.tool_name_twice')];
            } elseif (in_array($name, config('kit.agents.reserved_tool_names'), true)) {
                $errors["{$at}.tool.name"] = [__('agents.tool_name_reserved')];
            }
            $names[] = $name;
            $inDraft = $this->toolIn($draft, $name);
            // A draft read back says nothing of a key, so one taken away there would otherwise come back from what is in use.
            if ($inDraft !== null && $inDraft['tokenHint'] === null && ! array_key_exists('token', $step['tool'])) {
                $step['tool']['token'] = null;
            }
            $was = isset($inDraft['sealed']) ? $inDraft : ($this->toolIn($now['flow'] ?? null, $name) ?? $inDraft);
            $row = $this->tools->checkedRow(['description' => $step['tool']['description'] ?? $step['name'] ?? $name] + $step['tool'], $was, "{$at}.tool", (bool) ($now['sendCallerToTools'] ?? false), $errors);
            $step['tool'] = $this->withSealed($row, $input['tool'] ?? [], $inDraft);
        }
        if ($step['kind'] === 'transfer') {
            try {
                $transfer = $settings->checkedTransfer(['enabled' => true] + $step['transfer'], "{$at}.transfer");
                $step['transfer'] = array_diff_key($transfer, ['enabled' => true]);
            } catch (ValidationException $e) {
                $errors += $e->errors();
            }
        }
        if ($step['kind'] === 'agent') {
            try {
                $step['handoff'] = $this->handoff->checked($step['handoff'], $agent, "{$at}.handoff");
                if ($step['handoff']['agents'] === []) {
                    $errors["{$at}.handoff.agents"] = [__('agents.flow_handoff_nobody')];
                }
            } catch (ValidationException $e) {
                $errors += $e->errors();
            }
        }

        return $step;
    }

    /** @param  array<string, string>  $kinds  each step's kind, by id */
    private function checkExits(array $exits, array $kinds, array &$errors): void
    {
        $ids = [];
        $digits = [];
        foreach ($exits as $i => $exit) {
            $at = "flow.exits.{$i}";
            if (in_array($exit['id'], $ids, true)) {
                $errors["{$at}.id"] = [__('agents.flow_id_twice')];
            }
            $ids[] = $exit['id'];
            if (! isset($kinds[$exit['from']]) || in_array($kinds[$exit['from']], config('kit.agents.flow.no_exits_from'), true)) {
                $errors["{$at}.from"] = [__('agents.flow_exit_from')];
            }
            if (! isset($kinds[$exit['to']]) || $kinds[$exit['to']] === 'note') {
                $errors["{$at}.to"] = [__('agents.flow_exit_to')];
            }
            if ($exit['when']['kind'] === 'dtmf') {
                $key = $exit['from'].'|'.$exit['when']['digit'];
                if (in_array($key, $digits, true)) {
                    $errors["{$at}.when.digit"] = [__('agents.flow_digit_twice')];
                }
                $digits[] = $key;
            }
        }
    }

    /** A tool of a draft: a key typed sealed at once, and one sealed before kept while nothing replaced it. */
    private function sealed(array $given, array $row, ?array $inDraft, ?array $published): array
    {
        $sealed = [];
        unset($row['token']);
        $token = $given['token'] ?? null;
        if (array_key_exists('token', $given) && $token === null) {
            $row['tokenHint'] = null;
        } elseif (is_string($token) && ! $this->keys->isMask($token)) {
            $sealed['token'] = $this->keys->seal($token);
            $row['tokenHint'] = $this->keys->mask($token);
        } else {
            $row['tokenHint'] = $inDraft['tokenHint'] ?? $published['tokenHint'] ?? null;
            if (isset($inDraft['sealed']['token'])) {
                $sealed['token'] = $inDraft['sealed']['token'];
            }
        }
        foreach ($row['request']['headers'] ?? [] as $h => $header) {
            if (! is_string($header['value'] ?? null) || ! $this->keys->secret((string) ($header['name'] ?? ''))) {
                continue;
            }
            if (! $this->keys->isMask($header['value'])) {
                $sealed['headers'][$header['name']] = $this->keys->seal($header['value']);
                $row['request']['headers'][$h]['value'] = $this->keys->mask($header['value']);
            } elseif (isset($inDraft['sealed']['headers'][$header['name']])) {
                $sealed['headers'][$header['name']] = $inDraft['sealed']['headers'][$header['name']];
            }
        }

        $row['parameters'] = array_map(fn (array $p) => ['name' => $p['name'] ?? null, 'type' => $p['type'] ?? 'string', 'description' => $p['description'] ?? null, 'required' => (bool) ($p['required'] ?? false)], array_values($row['parameters'] ?? []));
        if (isset($row['request'])) {
            $row['request'] = ['method' => $row['request']['method'], 'headers' => array_values($row['request']['headers'] ?? []), 'body' => $row['request']['body'] ?? null];
        }

        return $row + ['name' => null, 'description' => null, 'url' => null, 'request' => null, 'sayWhileRunning' => null, 'timeoutSecs' => null, 'askFirst' => false] + ($sealed === [] ? [] : ['sealed' => $sealed]);
    }

    /** A checked tool, keeping what a draft sealed that was not typed again. */
    private function withSealed(array $row, array $given, ?array $inDraft): array
    {
        $from = $inDraft['sealed'] ?? [];
        if (isset($from['token']) && ! array_key_exists('token', $given)) {
            $row['sealed']['token'] = $from['token'];
        }
        foreach ($row['request']['headers'] ?? [] as $header) {
            if (isset($from['headers'][$header['name']]) && ! isset($row['sealed']['headers'][$header['name']])) {
                $row['sealed']['headers'][$header['name']] = $from['headers'][$header['name']];
            }
        }

        return $row;
    }

    private function toolIn(?array $flow, string $name): ?array
    {
        foreach ($flow['steps'] ?? [] as $step) {
            if (($step['tool']['name'] ?? null) === $name) {
                return $step['tool'];
            }
        }

        return null;
    }

    /** Whether saved steps rely on a key Telenow holds, so that the top of `metadata` must keep reading the same. */
    public function keepsKey(?array $flow): bool
    {
        foreach ($flow['steps'] ?? [] as $step) {
            if (is_array($step['tool'] ?? null) && $this->tools->keepsKey($step['tool'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * What may no longer stay taken off, each as an ending, with its exits gone and no jump to it from anywhere.
     *
     * @return array{flow: ?array, kept: list<string>}
     */
    public function cleaned(?array $flow, Agent $agent, AgentSettings $settings): array
    {
        if ($flow === null) {
            return ['flow' => null, 'kept' => []];
        }
        $kept = [];
        $ended = [];
        $targets = array_merge(...[[], ...array_map(fn (array $s) => array_column($s['handoff']['agents'] ?? [], 'agentId'), $flow['steps'])]);
        $alive = $targets === [] ? [] : Agent::query()->where('workspace_id', $agent->workspace_id)->whereNull('deleted_at')->whereNotNull('telenow_id')->whereIn('id', $targets)->pluck('id')->all();
        foreach ($flow['steps'] as $i => $step) {
            $line = null;
            if ($step['kind'] === 'transfer' && ! $settings->transferAllowed()) {
                $kept[] = 'transfer';
                $line = $step['transfer']['noAnswerMessage'] ?? null;
            } elseif ($step['kind'] === 'agent') {
                $left = array_values(array_filter($step['handoff']['agents'] ?? [], fn (array $t) => in_array($t['agentId'], $alive, true)));
                if (count($left) === count($step['handoff']['agents'] ?? [])) {
                    continue;
                }
                $kept[] = 'handoff';
                if ($left !== []) {
                    $flow['steps'][$i]['handoff']['agents'] = $left;

                    continue;
                }
            } else {
                continue;
            }
            $ended[] = $step['id'];
            $flow['steps'][$i] = array_replace($step, ['kind' => 'end', 'line' => $line, 'anywhere' => null, 'transfer' => null, 'handoff' => null]);
        }
        $flow['exits'] = array_values(array_filter($flow['exits'], fn (array $e) => ! in_array($e['from'], $ended, true)));

        return ['flow' => $flow, 'kept' => array_values(array_unique($kept))];
    }

    /** @return ?array<string, mixed> the steps as Telenow's metadata has them, in the kit's words; none for an agent on its own settings */
    public function read(array $metadata, AgentSettings $settings): ?array
    {
        $graph = $metadata['flow'] ?? null;
        if (! is_array($graph) || ! is_array($graph['nodes'] ?? null)) {
            return null;
        }
        $steps = array_values(array_map(fn (array $n) => $this->readNode($n, $settings), array_filter($graph['nodes'], 'is_array')));
        $edges = array_values(array_filter((array) ($graph['edges'] ?? []), 'is_array'));
        usort($edges, fn (array $a, array $b) => (int) ($a['priority'] ?? 0) <=> (int) ($b['priority'] ?? 0));

        return ['start' => is_string($graph['startNodeId'] ?? null) ? $graph['startNodeId'] : null, 'steps' => $steps, 'exits' => array_map(fn (array $e) => $this->readEdge($e), $edges)];
    }

    private function readNode(array $n, AgentSettings $settings): array
    {
        $config = is_array($n['config'] ?? null) ? $n['config'] : [];
        $kind = (string) ($n['kind'] ?? 'conversation');
        $tool = null;
        if ($kind === 'tool') {
            $tool = $this->tools->readRow($config);
        }
        $transfer = $kind === 'transfer' ? array_diff_key($settings->readTransfer($n), ['enabled' => true]) : null;

        return [
            'id' => (string) ($n['id'] ?? ''), 'kind' => $kind, 'name' => is_string($n['name'] ?? null) ? $n['name'] : null,
            'x' => $this->place($n['position']['x'] ?? null), 'y' => $this->place($n['position']['y'] ?? null),
            'instructions' => is_string($n['prompt'] ?? null) ? $n['prompt'] : null, 'replaceInstructions' => ($n['promptMode'] ?? null) === 'replace',
            'line' => $settings->line($n['entryMessage'] ?? null), 'continueAfterLine' => ($n['continueAfterEntry'] ?? false) === true,
            'capture' => array_map(fn (array $c) => ['name' => (string) ($c['name'] ?? ''), 'type' => (string) ($c['type'] ?? 'string'), 'description' => is_string($c['description'] ?? null) ? $c['description'] : null,
                'values' => array_values((array) ($c['values'] ?? [])), 'required' => ($c['required'] ?? false) === true, 'format' => is_string($c['format'] ?? null) ? $c['format'] : null], array_values(array_filter((array) ($n['extract'] ?? []), 'is_array'))),
            'keypad' => $kind === 'dtmf' ? ['routeOnly' => ($n['guards']['dtmfRouteOnly'] ?? false) === true, 'only' => ($n['guards']['keypadOnly'] ?? false) === true] : null,
            'anywhere' => ($n['isGlobal'] ?? false) === true && is_string($n['globalCondition'] ?? null) ? ['when' => $n['globalCondition'], 'examples' => array_values((array) ($n['globalExamples'] ?? [])), 'goBack' => ($n['globalGoBack'] ?? false) === true] : null,
            'tool' => $tool, 'results' => $kind === 'tool' ? array_map(fn (array $r) => ['path' => (string) ($r['path'] ?? ''), 'variable' => (string) ($r['variable'] ?? '')], array_values(array_filter((array) ($config['config']['responseMap'] ?? []), 'is_array'))) : [],
            'sends' => $kind === 'tool' ? array_values((array) ($n['sends'] ?? [])) : [],
            'code' => $kind === 'code' ? ['source' => is_string($config['source'] ?? null) ? $config['source'] : null, 'timeoutMs' => is_int($config['timeoutMs'] ?? null) ? $config['timeoutMs'] : null, 'keep' => array_map(fn (array $k) => ['field' => (string) ($k['field'] ?? ''), 'variable' => (string) ($k['variable'] ?? '')], array_values(array_filter((array) ($config['storeFields'] ?? []), 'is_array')))] : null,
            'transfer' => $transfer,
            'handoff' => $kind === 'agent' ? $this->handoff->readConfig($config) : null,
        ];
    }

    private function readEdge(array $e): array
    {
        $when = is_array($e['condition'] ?? null) ? $e['condition'] : [];
        $kind = (string) ($when['kind'] ?? 'always');

        return ['id' => (string) ($e['id'] ?? ''), 'from' => (string) ($e['source'] ?? ''), 'to' => (string) ($e['target'] ?? ''), 'label' => is_string($e['label'] ?? null) ? $e['label'] : null,
            'when' => ['kind' => $kind, 'expr' => is_string($when['expr'] ?? null) ? $when['expr'] : null, 'describe' => is_string($when['describe'] ?? null) ? $when['describe'] : null, 'digit' => is_string($when['digit'] ?? null) ? $when['digit'] : null, 'match' => is_string($when['match'] ?? null) ? $when['match'] : null]];
    }

    /** Put the steps in the metadata as Telenow reads them, or none to go back to the agent's own settings. */
    public function write(array &$metadata, ?array $flow, AgentSettings $settings): void
    {
        $stored = [];
        foreach ((array) ($metadata['flow']['nodes'] ?? []) as $node) {
            if (is_array($node) && is_string($node['config']['name'] ?? null)) {
                $stored[$node['config']['name']] = $node['config'];
            }
        }
        $metadata['flow'] = $flow === null ? null : $this->graph($flow, $stored, $settings);
    }

    /** @return array<string, mixed> the steps with no key in them, for Telenow's check */
    public function forChecking(array $flow, AgentSettings $settings): array
    {
        return $this->graph($this->keys->without($flow), [], $settings);
    }

    /**
     * The graph, in the exact types Telenow parses: one wrong type loses all of it. An exit's priority is its place among those of its step.
     *
     * @param  array<string, array>  $stored  each Function step's tool as read, whose masks stand for the keys kept
     */
    private function graph(array $flow, array $stored, AgentSettings $settings): array
    {
        $place = [];
        $edges = [];
        foreach ($flow['exits'] as $exit) {
            $place[$exit['from']] = ($place[$exit['from']] ?? -1) + 1;
            $when = ['kind' => $exit['when']['kind']] + array_filter(['expr' => $exit['when']['expr'], 'describe' => $exit['when']['describe'], 'digit' => $exit['when']['digit'], 'match' => $exit['when']['match']], fn ($v) => $v !== null);
            $edges[] = ['id' => $exit['id'], 'source' => $exit['from'], 'target' => $exit['to'], 'priority' => $place[$exit['from']]] + ($exit['label'] === null ? [] : ['label' => $exit['label']]) + ['condition' => $when];
        }

        return ['schema' => config('kit.agents.flow.schema'), 'startNodeId' => (string) $flow['start'],
            'nodes' => array_map(fn (array $step) => $this->node($step, $stored, $settings), $flow['steps']), 'edges' => $edges];
    }

    private function node(array $s, array $stored, AgentSettings $settings): array
    {
        $node = ['id' => $s['id'], 'kind' => $s['kind']] + ($s['name'] === null ? [] : ['name' => $s['name']]);
        if ($s['x'] !== null && $s['y'] !== null) {
            $node['position'] = ['x' => $s['x'], 'y' => $s['y']];
        }
        if ($s['instructions'] !== null) {
            $node['prompt'] = $s['instructions'];
        }
        if ($s['replaceInstructions']) {
            $node['promptMode'] = 'replace';
        }
        if ($s['line'] !== null) {
            $node['entryMessage'] = $s['line'];
        }
        if ($s['continueAfterLine']) {
            $node['continueAfterEntry'] = true;
        }
        if ($s['capture'] !== []) {
            $node['extract'] = array_map(fn (array $c) => ['name' => $c['name'], 'type' => $c['type']] + array_filter(['description' => $c['description'], 'format' => $c['format']], fn ($v) => $v !== null)
                + ($c['values'] === [] ? [] : ['values' => $c['values']]) + ($c['required'] ? ['required' => true] : []), $s['capture']);
        }
        if ($s['keypad'] !== null && ($s['keypad']['routeOnly'] || $s['keypad']['only'])) {
            $node['guards'] = ($s['keypad']['routeOnly'] ? ['dtmfRouteOnly' => true] : []) + ($s['keypad']['only'] ? ['keypadOnly' => true] : []);
        }
        if ($s['anywhere'] !== null) {
            $node += ['isGlobal' => true, 'globalCondition' => $s['anywhere']['when']] + ($s['anywhere']['examples'] === [] ? [] : ['globalExamples' => $s['anywhere']['examples']]) + ($s['anywhere']['goBack'] ? ['globalGoBack' => true] : []);
        }
        if ($s['tool'] !== null) {
            $tool = $this->tools->telenowRow($s['tool'], $stored[$s['tool']['name']] ?? []);
            unset($tool['config']);
            $node['config'] = $tool + ($s['results'] === [] ? [] : ['config' => ['responseMap' => $s['results']]]);
            if ($s['results'] !== []) {
                $node['saves'] = array_map(fn (array $r) => ['name' => $r['variable']], $s['results']);
            }
            if ($s['sends'] !== []) {
                $node['sends'] = $s['sends'];
            }
        }
        if ($s['code'] !== null) {
            $node['config'] = ['language' => config('kit.agents.flow.code_language'), 'source' => (string) $s['code']['source']] + ($s['code']['timeoutMs'] === null ? [] : ['timeoutMs' => $s['code']['timeoutMs']])
                + ($s['code']['keep'] === [] ? [] : ['storeFields' => $s['code']['keep']]);
        }
        if ($s['transfer'] !== null) {
            $node['config'] = $settings->transferConfig($s['transfer']);
        }
        if ($s['handoff'] !== null) {
            $node['config'] = $this->handoff->config($s['handoff']);
        }

        return $node;
    }
}
