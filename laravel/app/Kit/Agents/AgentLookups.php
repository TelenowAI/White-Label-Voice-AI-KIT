<?php

namespace App\Kit\Agents;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * An agent's pre-call look-ups, in the kit's words: before the call opens, Telenow asks the customer's own address about the person and fills
 * call variables from the answer, so the opening line and the instructions can use them. As Telenow has them they are `metadata.precallLookups`
 * (services/integrations.rs PrecallLookup), parsed as a whole list: one bad row drops every look-up of the agent, in silence.
 *
 * What was recorded (contract_k3tools.py) and read:
 * - a look-up runs with the call's `{placeholders}` filled in, and is skipped when the one that says who is blank, so it never asks about nobody;
 * - it has at most 1.5 s on a call being answered and 4 s on one being placed; a slow or failed one fills nothing and the call goes on;
 * - a variable nobody filled reads as empty, so a line can say "Hi ," unless it has a fallback: that is `metadata.variables`, kept only for
 *   the variables look-ups fill, and any other declared there stays.
 *
 * Other kinds of look-up on the agent are left as they are.
 *
 * Not here: tools (AgentTools), keys (SavedKeys), the request checks (AgentRequest).
 */
final class AgentLookups
{
    public function __construct(private readonly AgentRequest $requests, private readonly SavedKeys $keys) {}

    public function has(string $key): bool
    {
        return $key === 'lookups';
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $l = config('kit.agents.limits');
        $row = 'settings.lookups.*';

        return [
            'settings.lookups' => ['sometimes', 'array', 'max:'.$l['lookups_max']],
            "{$row}.name" => ['required', 'string', 'max:'.$l['analysis_label_max_length']],
            "{$row}.url" => ['required', 'string', 'max:'.$l['address_max_length']],
            "{$row}.method" => ['required', Rule::in(config('kit.agents.lookup_methods'))],
            "{$row}.token" => ['sometimes', 'nullable', 'string', 'max:'.$l['key_max_length']],
            "{$row}.headers" => ['sometimes', 'array', 'max:'.$l['request_headers_max']],
            "{$row}.headers.*.name" => ['required', 'string', 'max:'.$l['header_name_max_length']],
            "{$row}.headers.*.value" => ['required', 'string', 'max:'.$l['key_max_length']],
            "{$row}.body" => ['sometimes', 'nullable', 'string', 'max:'.$l['request_body_max_length']],
            "{$row}.fills" => ['required', 'array', 'min:1', 'max:'.$l['lookup_fills_max']],
            "{$row}.fills.*.variable" => ['required', 'string', 'regex:'.$l['variable_pattern']],
            "{$row}.fills.*.path" => ['required', 'string', 'max:'.$l['lookup_path_max_length'], 'regex:'.$l['lookup_path_pattern']],
            "{$row}.fills.*.fallback" => ['sometimes', 'nullable', 'string', 'max:'.$l['line_max_length']],
        ];
    }

    /**
     * @param  array<string, mixed>  $now  the agent's settings
     * @return list<array<string, mixed>>
     */
    public function checked(array $values, array $now): array
    {
        $errors = [];
        $was = array_column($now['lookups'] ?? [], null, 'name');
        $names = [];
        $variables = [];
        $rows = [];
        foreach (array_values($values['lookups']) as $i => $lookup) {
            $at = "settings.lookups.{$i}";
            if (in_array($lookup['name'], $names, true)) {
                $errors["{$at}.name"] = [__('agents.lookup_name_twice')];
            }
            $names[] = $lookup['name'];
            $rows[] = $this->checkedRow($lookup, $was[$lookup['name']] ?? null, $at, $variables, $errors);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    private function checkedRow(array $lookup, ?array $was, string $at, array &$variables, array &$errors): array
    {
        $problem = $this->requests->addressProblem($lookup['url']);
        if ($problem !== null) {
            $errors["{$at}.url"] = [__("agents.{$problem}")];
        }
        $body = $lookup['body'] ?? null;
        if ($lookup['method'] === 'GET' && $body !== null) {
            $errors["{$at}.body"] = [__('agents.request_get_body')];
        }
        $headers = $lookup['headers'] ?? [];
        $sealed = [];
        $sealedHeaders = [];
        $checkedHeaders = $this->requests->headers($headers, $was['headers'] ?? [], "{$at}.headers", $this->keys, $errors, $sealedHeaders);
        if ($sealedHeaders !== []) {
            $sealed['headers'] = $sealedHeaders;
        }
        $texts = ["{$at}.url" => $lookup['url'], "{$at}.body" => (string) $body];
        foreach (array_values($headers) as $h => $header) {
            $texts["{$at}.headers.{$h}.value"] = $header['value'];
        }
        $read = $this->requests->checkedPlaceholders($texts, fn (string $name) => null, $errors);
        if ($read === []) {
            $errors["{$at}.url"][] = __('agents.lookup_says_nobody');
        }
        $given = array_key_exists('token', $lookup);
        $token = $lookup['token'] ?? null;
        if (is_string($token) && $this->keys->isMask($token)) {
            $errors["{$at}.token"] = [__('agents.key_unknown')];
        } elseif (is_string($token)) {
            $sealed['token'] = $this->keys->seal($token);
        }
        $hint = $given ? ($token === null ? null : $this->keys->mask($token)) : ($was['tokenHint'] ?? null);
        if ($hint !== null && in_array('authorization', array_map('strtolower', array_column($checkedHeaders, 'name')), true)) {
            $errors["{$at}.token"] = [__('agents.key_and_authorization')];
        }
        $fills = [];
        foreach (array_values($lookup['fills']) as $f => $fill) {
            $variable = $fill['variable'];
            if (in_array($variable, config('kit.agents.platform_variables'), true)) {
                $errors["{$at}.fills.{$f}.variable"] = [__('agents.variable_reserved')];
            } elseif (in_array($variable, $variables, true)) {
                $errors["{$at}.fills.{$f}.variable"] = [__('agents.variable_twice')];
            }
            $variables[] = $variable;
            $fills[] = ['variable' => $variable, 'path' => $fill['path'], 'fallback' => $fill['fallback'] ?? null];
        }
        $row = $this->row($lookup['name'], $lookup['url'], $lookup['method'], $checkedHeaders, $body, $hint, $fills);
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
        foreach ($row['headers'] as $header) {
            if ($this->keys->isMask($header['value']) && ! isset($sealed['headers'][$header['name']])) {
                return true;
            }
        }

        return false;
    }

    private function row(string $name, string $url, string $method, array $headers, ?string $body, ?string $hint, array $fills): array
    {
        usort($fills, fn (array $a, array $b) => strcmp($a['variable'], $b['variable']));

        return ['name' => $name, 'url' => $url, 'method' => $method, 'headers' => $headers, 'body' => $body, 'tokenHint' => $hint, 'fills' => $fills];
    }

    /** @return array<string, mixed> */
    public function read(array $metadata): array
    {
        $fallbacks = [];
        foreach ((array) ($metadata['variables'] ?? []) as $declared) {
            if (is_array($declared) && is_string($declared['name'] ?? null) && is_string($declared['default'] ?? null)) {
                $fallbacks[$declared['name']] = $declared['default'];
            }
        }
        $lookups = [];
        foreach ((array) ($metadata['precallLookups'] ?? []) as $row) {
            if (is_array($row) && $this->isOurs($row)) {
                $lookups[] = $this->readRow($row, $fallbacks);
            }
        }

        return ['lookups' => $lookups];
    }

    private function isOurs(array $row): bool
    {
        return ($row['kind'] ?? null) === 'http' && is_array($row['request'] ?? null);
    }

    private function readRow(array $row, array $fallbacks): array
    {
        $request = $row['request'];
        $headers = [];
        foreach ((array) ($request['headers'] ?? []) as $name => $value) {
            if (is_string($value)) {
                $headers[] = ['name' => (string) $name, 'value' => $this->keys->secret((string) $name) ? (string) $this->keys->hint($value) : $value];
            }
        }
        $fills = [];
        foreach ((is_array($row['map'] ?? null) ? $row['map'] : []) as $variable => $path) {
            if (is_string($path)) {
                $fills[] = ['variable' => (string) $variable, 'path' => $path, 'fallback' => $fallbacks[$variable] ?? null];
            }
        }

        return $this->row((string) ($row['name'] ?? ''), (string) ($request['url'] ?? ''), strtoupper((string) ($request['method'] ?? 'GET')), $this->requests->sorted($headers),
            is_string($request['body'] ?? null) ? $request['body'] : null, $this->keys->hint($row['bearer'] ?? null), $fills);
    }

    public function write(array &$metadata, array $lookups): void
    {
        $rows = array_values(array_filter((array) ($metadata['precallLookups'] ?? []), 'is_array'));
        $ours = [];
        $others = [];
        $filled = [];
        foreach ($rows as $row) {
            if ($this->isOurs($row)) {
                $ours[$row['name'] ?? ''] = $row;
                array_push($filled, ...array_keys(is_array($row['map'] ?? null) ? $row['map'] : []));
            } else {
                $others[] = $row;
            }
        }
        $metadata['precallLookups'] = array_merge($others, array_map(fn (array $lookup) => $this->telenowRow($lookup, $ours[$lookup['name']] ?? []), $lookups));
        if ($metadata['precallLookups'] === []) {
            unset($metadata['precallLookups']);
        }
        $this->writeFallbacks($metadata, $filled, $lookups);
    }

    /** The variables look-ups filled are written afresh; any other declared on the agent stays as it is. */
    private function writeFallbacks(array &$metadata, array $filled, array $lookups): void
    {
        $declared = array_values(array_filter((array) ($metadata['variables'] ?? []), fn ($row) => ! is_array($row) || ! in_array($row['name'] ?? null, $filled, true)));
        foreach ($lookups as $lookup) {
            foreach ($lookup['fills'] as $fill) {
                if ($fill['fallback'] !== null) {
                    $declared = array_values(array_filter($declared, fn ($row) => ! is_array($row) || ($row['name'] ?? null) !== $fill['variable']));
                    $declared[] = ['name' => $fill['variable'], 'required' => false, 'default' => $fill['fallback']];
                }
            }
        }
        if ($declared === []) {
            unset($metadata['variables']);
        } else {
            $metadata['variables'] = $declared;
        }
    }

    /** A look-up as Telenow's row: its first caller placeholder, else the first it reads, says who it is about. */
    private function telenowRow(array $lookup, array $stored): array
    {
        $texts = implode("\n", [$lookup['url'], ...array_column($lookup['headers'], 'value'), (string) $lookup['body']]);
        $read = array_keys($this->requests->placeholders($texts));
        $callers = array_values(array_intersect($read, config('kit.agents.caller_placeholders')));
        $sealed = $lookup['sealed'] ?? [];
        $headers = [];
        foreach ($lookup['headers'] as $header) {
            $name = $header['name'];
            $headers[$name] = isset($sealed['headers'][$name]) ? $this->keys->open($sealed['headers'][$name]) : ($this->keys->isMask($header['value']) ? ($stored['request']['headers'][$name] ?? $header['value']) : $header['value']);
        }
        $map = [];
        foreach ($lookup['fills'] as $fill) {
            $map[$fill['variable']] = $fill['path'];
        }
        $bearer = isset($sealed['token']) ? $this->keys->open($sealed['token']) : ($lookup['tokenHint'] === null ? null : ($stored['bearer'] ?? null));

        return ['kind' => 'http', 'name' => $lookup['name'], 'key' => $callers[0] ?? $read[0] ?? null,
            'request' => ['method' => $lookup['method'], 'url' => $lookup['url']] + ($headers === [] ? [] : ['headers' => $headers]) + ($lookup['body'] === null ? [] : ['body' => $lookup['body']]),
            'map' => $map] + ($bearer === null ? [] : ['bearer' => $bearer]);
    }
}
