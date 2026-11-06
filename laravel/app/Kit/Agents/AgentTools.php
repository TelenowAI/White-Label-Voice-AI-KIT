<?php

namespace App\Kit\Agents;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * An agent's own API tools, in the kit's words, and its two small switches: a tool is a web address the agent may call during a call, with
 * the details it should fetch from the caller. As Telenow has them they are rows of `metadata.tools` (services/tool.rs ToolSpec), and a row
 * that fails to parse is dropped on a live call with no word to anyone, so everything is checked here and written in its exact types.
 *
 * Plain, a tool is POSTed its name and details as JSON (`endpoint`); with a `request` it sends that method, headers and body with its
 * `{placeholders}` filled in (`request`). A tool's name is what identifies it: a new name is a new tool, and takes no saved key with it.
 *
 * Other tools on the agent (a transfer, ending the call, ones the kit does not manage) are left as they are.
 *
 * Not here: transfer and hanging up (AgentSettings), look-ups (AgentLookups), hand-overs (AgentHandoff), keys (SavedKeys).
 */
final class AgentTools
{
    private const KEYS = ['tools', 'canAdjustVolume', 'sendCallerToTools'];

    /** What a row of the kit's sets at Telenow: the row's other keys stay. */
    private const WRITTEN = ['name', 'description', 'endpoint', 'request', 'bearer', 'parameters', 'handoff', 'timeoutSecs', 'requiresConfirmation', 'kind'];

    public function __construct(private readonly AgentRequest $requests, private readonly SavedKeys $keys) {}

    public function has(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'settings.canAdjustVolume' => ['sometimes', 'boolean'],
            'settings.sendCallerToTools' => ['sometimes', 'boolean'],
            'settings.tools' => ['sometimes', 'array', 'max:'.config('kit.agents.limits.tools_max')],
        ] + $this->rowRules('settings.tools.*');
    }

    /** @return array<string, list<mixed>> one tool's fields, at `$row` */
    public function rowRules(string $row): array
    {
        $l = config('kit.agents.limits');

        return [
            "{$row}.name" => ['required', 'string', 'max:'.$l['tool_name_max_length'], 'regex:'.$l['tool_name_pattern']],
            "{$row}.description" => ['required', 'string', 'max:'.$l['tool_description_max_length']],
            "{$row}.url" => ['required', 'string', 'max:'.$l['address_max_length']],
            "{$row}.token" => ['sometimes', 'nullable', 'string', 'max:'.$l['key_max_length']],
            "{$row}.request" => ['sometimes', 'nullable', 'array'],
            "{$row}.request.method" => ["required_with:{$row}.request", Rule::in(config('kit.agents.tool_methods'))],
            "{$row}.request.headers" => ['sometimes', 'array', 'max:'.$l['request_headers_max']],
            "{$row}.request.headers.*.name" => ['required', 'string', 'max:'.$l['header_name_max_length']],
            "{$row}.request.headers.*.value" => ['required', 'string', 'max:'.$l['key_max_length']],
            "{$row}.request.body" => ['sometimes', 'nullable', 'string', 'max:'.$l['request_body_max_length']],
            "{$row}.parameters" => ['sometimes', 'array', 'max:'.$l['tool_parameters_max']],
            "{$row}.parameters.*.name" => ['required', 'string', 'regex:'.$l['variable_pattern']],
            "{$row}.parameters.*.type" => ['required', Rule::in(config('kit.agents.tool_parameter_types'))],
            "{$row}.parameters.*.description" => ['sometimes', 'nullable', 'string', 'max:'.$l['tool_description_max_length']],
            "{$row}.parameters.*.required" => ['sometimes', 'boolean'],
            "{$row}.sayWhileRunning" => ['sometimes', 'nullable', 'string', 'max:'.$l['line_max_length']],
            "{$row}.timeoutSecs" => ['sometimes', 'nullable', 'integer', 'between:'.implode(',', $l['tool_timeout_seconds'])],
            "{$row}.askFirst" => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The tools in `$values`, checked against the agent as it stands, each in the shape `read` answers, with `sealed` for any key typed.
     *
     * @param  array<string, mixed>  $now  the agent's settings
     * @return list<array<string, mixed>>
     */
    public function checked(array $values, array $now): array
    {
        $errors = [];
        $names = [];
        $was = array_column($now['tools'] ?? [], null, 'name');
        $callerOn = (bool) ($values['sendCallerToTools'] ?? $now['sendCallerToTools'] ?? false);
        $rows = [];
        foreach (array_values($values['tools']) as $i => $tool) {
            $at = "settings.tools.{$i}";
            $name = $tool['name'];
            if (in_array($name, $names, true)) {
                $errors["{$at}.name"] = [__('agents.tool_name_twice')];
            } elseif (in_array($name, config('kit.agents.reserved_tool_names'), true)) {
                $errors["{$at}.name"] = [__('agents.tool_name_reserved')];
            }
            $names[] = $name;
            $rows[] = $this->checkedRow($tool, $was[$name] ?? null, $at, $callerOn, $errors);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    public function checkedRow(array $tool, ?array $was, string $at, bool $callerOn, array &$errors): array
    {
        $sealed = [];
        $problem = $this->requests->addressProblem($tool['url']);
        if ($problem !== null) {
            $errors["{$at}.url"] = [__("agents.{$problem}")];
        }
        $parameters = $this->checkedParameters($tool['parameters'] ?? [], $at, $errors);
        $request = null;
        $texts = ["{$at}.url" => $tool['url']];
        if (isset($tool['request'])) {
            $headers = $tool['request']['headers'] ?? [];
            $sealedHeaders = [];
            $checkedHeaders = $this->requests->headers($headers, $was['request']['headers'] ?? [], "{$at}.request.headers", $this->keys, $errors, $sealedHeaders);
            if ($sealedHeaders !== []) {
                $sealed['headers'] = $sealedHeaders;
            }
            $request = ['method' => $tool['request']['method'], 'headers' => $checkedHeaders, 'body' => $tool['request']['body'] ?? null];
            if ($request['method'] === 'GET' && $request['body'] !== null) {
                $errors["{$at}.request.body"] = [__('agents.request_get_body')];
            }
            foreach (array_values($headers) as $h => $header) {
                $texts["{$at}.request.headers.{$h}.value"] = $header['value'];
            }
            $texts["{$at}.request.body"] = (string) $request['body'];
        } elseif ($problem === null && $this->requests->placeholders($tool['url']) !== []) {
            $errors["{$at}.url"] = [__('agents.tool_plain_placeholder')];
        }
        if ($request !== null) {
            $this->requests->checkedPlaceholders($texts, fn (string $name) => $this->placeholderProblem($name, array_column($parameters, 'name'), $callerOn), $errors);
        }
        $given = array_key_exists('token', $tool);
        $token = $tool['token'] ?? null;
        if (is_string($token) && $this->keys->isMask($token)) {
            $errors["{$at}.token"] = [__('agents.key_unknown')];
        } elseif (is_string($token)) {
            $sealed['token'] = $this->keys->seal($token);
        }
        $hint = $given ? ($token === null ? null : $this->keys->mask($token)) : ($was['tokenHint'] ?? null);
        if ($hint !== null && $request !== null && in_array('authorization', array_map('strtolower', array_column($request['headers'], 'name')), true)) {
            $errors["{$at}.token"] = [__('agents.key_and_authorization')];
        }
        $row = $this->row($tool['name'], $tool['description'], $tool['url'], $request, $parameters, $hint, $tool['sayWhileRunning'] ?? null, isset($tool['timeoutSecs']) ? (int) $tool['timeoutSecs'] : null, (bool) ($tool['askFirst'] ?? false));
        if ($was !== null && $this->keepsKey($row + ['sealed' => $sealed]) && $this->keys->destinations($this->telenowRow($row, [])) !== $this->keys->destinations($this->telenowRow($was, []))) {
            $errors["{$at}.token"] = [__('agents.key_stays_with_address')];
        }

        return $row + ($sealed === [] ? [] : ['sealed' => $sealed]);
    }

    /** Whether this row, once saved, still relies on a key Telenow holds. */
    public function keepsKey(array $row): bool
    {
        $sealed = $row['sealed'] ?? [];
        if ($row['tokenHint'] !== null && ! isset($sealed['token'])) {
            return true;
        }
        foreach ($row['request']['headers'] ?? [] as $header) {
            if ($this->keys->isMask($header['value']) && ! isset($sealed['headers'][$header['name']])) {
                return true;
            }
        }

        return false;
    }

    private function placeholderProblem(string $name, array $parameters, bool $callerOn): ?string
    {
        if (in_array($name, config('kit.agents.caller_placeholders'), true)) {
            return ! $callerOn && in_array($name, config('kit.agents.caller_placeholders_identifying'), true) ? __('agents.placeholder_needs_caller', ['name' => $name]) : null;
        }

        return in_array($name, $parameters, true) ? null : __('agents.placeholder_unknown', ['name' => $name]);
    }

    /** @return list<array{name: string, type: string, description: ?string, required: bool}> */
    private function checkedParameters(array $rows, string $at, array &$errors): array
    {
        $checked = [];
        foreach (array_values($rows) as $i => $row) {
            if (in_array($row['name'], array_column($checked, 'name'), true)) {
                $errors["{$at}.parameters.{$i}.name"] = [__('agents.parameter_twice')];
            } elseif (in_array($row['name'], config('kit.agents.caller_placeholders'), true)) {
                $errors["{$at}.parameters.{$i}.name"] = [__('agents.parameter_reserved')];
            }
            $checked[] = ['name' => $row['name'], 'type' => $row['type'], 'description' => $row['description'] ?? null, 'required' => (bool) ($row['required'] ?? false)];
        }

        return $this->sortedParameters($checked);
    }

    private function sortedParameters(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /** The one shape of a tool, in one order, so what was read and what was sent compare as equal when they are. */
    private function row(string $name, string $description, string $url, ?array $request, array $parameters, ?string $hint, ?string $say, ?int $timeout, bool $askFirst): array
    {
        return ['name' => $name, 'description' => $description, 'url' => $url, 'request' => $request, 'parameters' => $parameters,
            'tokenHint' => $hint, 'sayWhileRunning' => $say, 'timeoutSecs' => $timeout, 'askFirst' => $askFirst];
    }

    /** @return array<string, mixed> the settings as Telenow's metadata has them */
    public function read(array $metadata): array
    {
        $rows = array_values(array_filter((array) ($metadata['tools'] ?? []), 'is_array'));
        $tools = [];
        foreach ($rows as $row) {
            if ($this->isOurs($row)) {
                $tools[] = $this->readRow($row);
            }
        }

        return [
            'tools' => $tools,
            'canAdjustVolume' => in_array('adjust_volume', array_column($rows, 'kind'), true),
            'sendCallerToTools' => is_array($metadata['callerIdentity'] ?? null) && ($metadata['callerIdentity']['enabled'] ?? false) === true,
        ];
    }

    private function isOurs(array $row): bool
    {
        return in_array($row['kind'] ?? 'http', ['http'], true) && (($row['endpoint'] ?? '') !== '' || is_array($row['request'] ?? null));
    }

    public function readRow(array $row): array
    {
        $request = null;
        if (is_array($row['request'] ?? null)) {
            $headers = [];
            foreach ((array) ($row['request']['headers'] ?? []) as $name => $value) {
                if (is_string($value)) {
                    $headers[] = ['name' => (string) $name, 'value' => $this->keys->secret((string) $name) ? (string) $this->keys->hint($value) : $value];
                }
            }
            $request = ['method' => (string) ($row['request']['method'] ?? 'POST'), 'headers' => $this->requests->sorted($headers), 'body' => is_string($row['request']['body'] ?? null) ? $row['request']['body'] : null];
        }
        $schema = is_array($row['parameters'] ?? null) ? $row['parameters'] : [];
        $required = (array) ($schema['required'] ?? []);
        $parameters = [];
        foreach ((is_array($schema['properties'] ?? null) ? $schema['properties'] : []) as $name => $property) {
            $property = is_array($property) ? $property : [];
            $parameters[] = ['name' => (string) $name, 'type' => is_string($property['type'] ?? null) ? $property['type'] : 'string',
                'description' => is_string($property['description'] ?? null) ? $property['description'] : null, 'required' => in_array($name, $required, true)];
        }
        $say = is_array($row['handoff'] ?? null) ? ($row['handoff']['source'] ?? null) : ($row['handoff'] ?? null);

        return $this->row((string) ($row['name'] ?? ''), (string) ($row['description'] ?? ''), (string) ($request === null ? $row['endpoint'] : ($row['request']['url'] ?? '')), $request, $this->sortedParameters($parameters),
            $this->keys->hint($row['bearer'] ?? null), is_string($say) ? $say : null, is_int($row['timeoutSecs'] ?? null) ? $row['timeoutSecs'] : null, ($row['requiresConfirmation'] ?? false) === true);
    }

    /** Put one setting in the metadata as Telenow reads it. */
    public function write(array &$metadata, string $key, mixed $value): void
    {
        match ($key) {
            'tools' => $this->writeTools($metadata, $value),
            'canAdjustVolume' => $this->writeVolume($metadata, $value),
            'sendCallerToTools' => $metadata['callerIdentity'] = ['enabled' => $value] + (is_array($metadata['callerIdentity'] ?? null) ? $metadata['callerIdentity'] : []),
        };
    }

    private function writeTools(array &$metadata, array $tools): void
    {
        $rows = array_values(array_filter((array) ($metadata['tools'] ?? []), 'is_array'));
        $ours = [];
        $others = [];
        foreach ($rows as $row) {
            if ($this->isOurs($row)) {
                $ours[$row['name'] ?? ''] = $row;
            } else {
                $others[] = $row;
            }
        }
        $metadata['tools'] = array_merge($others, array_map(fn (array $tool) => $this->telenowRow($tool, $ours[$tool['name']] ?? []), $tools));
    }

    private function writeVolume(array &$metadata, bool $on): void
    {
        $rows = array_values(array_filter((array) ($metadata['tools'] ?? []), fn ($row) => ! is_array($row) || ($row['kind'] ?? null) !== 'adjust_volume'));
        if ($on) {
            $rows[] = ['name' => 'adjust_volume', 'kind' => 'adjust_volume', 'description' => config('kit.agents.tool_descriptions.adjust_volume')];
        }
        $metadata['tools'] = $rows;
    }

    /**
     * A tool as Telenow's row, in the exact types ToolSpec parses. `$stored` is the row as read, whose masks stand for the keys kept. Compared
     * for where it sends, a row with no stored row and no key opened holds the same destinations.
     */
    public function telenowRow(array $tool, array $stored): array
    {
        $row = array_diff_key($stored, array_flip(self::WRITTEN));
        $row['name'] = $tool['name'];
        $row['description'] = $tool['description'];
        $row['parameters'] = $this->schema($tool['parameters']);
        $sealed = $tool['sealed'] ?? [];
        if ($tool['request'] === null) {
            $row['endpoint'] = $tool['url'];
        } else {
            $headers = [];
            foreach ($tool['request']['headers'] as $header) {
                $name = $header['name'];
                $headers[$name] = isset($sealed['headers'][$name]) ? $this->keys->open($sealed['headers'][$name]) : ($this->keys->isMask($header['value']) ? ($stored['request']['headers'][$name] ?? $header['value']) : $header['value']);
            }
            $row['request'] = ['method' => $tool['request']['method'], 'url' => $tool['url']] + ($headers === [] ? [] : ['headers' => $headers]) + ($tool['request']['body'] === null ? [] : ['body' => $tool['request']['body']]);
        }
        $bearer = isset($sealed['token']) ? $this->keys->open($sealed['token']) : ($tool['tokenHint'] === null ? null : ($stored['bearer'] ?? null));
        if ($bearer !== null) {
            $row['bearer'] = $bearer;
        }
        if ($tool['sayWhileRunning'] !== null) {
            $row['handoff'] = $tool['sayWhileRunning'];
        }
        if ($tool['timeoutSecs'] !== null) {
            $row['timeoutSecs'] = $tool['timeoutSecs'];
        }
        if ($tool['askFirst']) {
            $row['requiresConfirmation'] = true;
        }

        return $row;
    }

    /** An empty list of properties goes as `{}`: a model's API refuses `[]` there. */
    private function schema(array $parameters): array
    {
        $properties = [];
        foreach ($parameters as $parameter) {
            $properties[$parameter['name']] = ['type' => $parameter['type']] + ($parameter['description'] === null ? [] : ['description' => $parameter['description']]);
        }
        $required = array_column(array_filter($parameters, fn (array $p) => $p['required']), 'name');

        return ['type' => 'object', 'properties' => $properties === [] ? new \stdClass : $properties] + ($required === [] ? [] : ['required' => array_values($required)]);
    }
}
