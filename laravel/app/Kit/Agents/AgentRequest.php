<?php

namespace App\Kit\Agents;

/**
 * What a tool or a look-up sends, checked before it is saved: Telenow stores all of it as sent and only finds out on a live call, where a
 * bad row is dropped in silence. The address, its `{placeholders}` as Telenow reads them (utils/variables.rs `walk`) and its headers.
 *
 * Telenow checks the address again when it sends, resolving the name, so this is the early word and not the guard: the kit never calls a
 * customer's address itself.
 *
 * Not here: saved keys and where they may go (SavedKeys), the rows themselves (AgentTools, AgentLookups).
 */
final class AgentRequest
{
    /**
     * The placeholders Telenow will fill in `$text`, each once. `{{` and `}}` are literal; a `{` is not one if another `{` or a new line
     * comes before its `}`, or if what is inside could not be a name, so a JSON body's own braces are left alone.
     *
     * @return array<string, ?string> name => its filter, if it has one
     */
    public function placeholders(string $text): array
    {
        $found = [];
        $length = strlen($text);
        $i = 0;
        while ($i < $length) {
            $c = $text[$i];
            if (($c === '{' || $c === '}') && ($text[$i + 1] ?? '') === $c) {
                $i += 2;

                continue;
            }
            $i++;
            if ($c !== '{') {
                continue;
            }
            $inner = '';
            $closed = false;
            while ($i < $length && $text[$i] !== '{' && $text[$i] !== "\n") {
                $next = $text[$i++];
                if ($next === '}') {
                    $closed = true;
                    break;
                }
                $inner .= $next;
            }
            [$name, $filter] = array_pad(array_map('trim', explode('|', trim($inner), 2)), 2, null);
            if ($closed && $this->isName($name)) {
                $found[$name] ??= $filter;
            }
        }

        return $found;
    }

    private function isName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_ .-]{0,63}$/', $name) === 1;
    }

    /** @return ?string why this address cannot be used, as a line in lang/en/agents.php */
    public function addressProblem(string $url): ?string
    {
        if (preg_match('/[\s\\\\]/', $url) === 1) {
            return 'address_shape';
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        if ($host === '') {
            return 'address_shape';
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            return 'address_https';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'address_login';
        }
        if (str_contains($host, '{') || str_contains($host, '}')) {
            return 'address_placeholder_in_host';
        }

        return $this->hostIsPublic($host) ? null : 'address_private';
    }

    /** A name is resolved when the request goes, so only what can be told from the text is told here. */
    private function hostIsPublic(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        $labels = explode('.', $host);
        $last = end($labels);
        if (count($labels) < 2 || preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', $last) === 1) {
            return false;
        }

        return ! in_array($last, config('kit.agents.private_host_endings'), true);
    }

    /**
     * Headers checked and tidied, sorted as Telenow stores them. A secret one holds its mask here, and what was typed for it goes in
     * `$sealed`; a mask handed back must be the one this row had.
     *
     * @param  list<array{name: string, value: string}>  $was  the row's headers as they stand
     * @param  array<string, list<string>>  $errors
     * @param  array<string, string>  $sealed
     * @return list<array{name: string, value: string}>
     */
    public function headers(array $rows, array $was, string $at, SavedKeys $keys, array &$errors, array &$sealed): array
    {
        $checked = [];
        $seen = [];
        $stored = array_column($was, 'value', 'name');
        foreach (array_values($rows) as $i => $row) {
            $name = $row['name'];
            $lower = strtolower($name);
            if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1 || in_array($lower, config('kit.agents.blocked_request_headers'), true)) {
                $errors["{$at}.{$i}.name"] = [__('agents.header_name')];
            } elseif (in_array($lower, $seen, true)) {
                $errors["{$at}.{$i}.name"] = [__('agents.header_twice')];
            }
            $seen[] = $lower;
            $value = $row['value'];
            if (preg_match('/[\r\n]/', $value) === 1) {
                $errors["{$at}.{$i}.value"] = [__('agents.header_value')];
            }
            if ($keys->isMask($value)) {
                if (! $keys->secret($name) || ($stored[$name] ?? null) !== $value) {
                    $errors["{$at}.{$i}.value"] = [__('agents.key_unknown')];
                }
            } elseif ($keys->secret($name)) {
                $sealed[$name] = $keys->seal($value);
                $value = $keys->mask($value);
            }
            $checked[] = ['name' => $name, 'value' => $value];
        }

        return $this->sorted($checked);
    }

    /** @return list<array{name: string, value: string}> */
    public function sorted(array $headers): array
    {
        usort($headers, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $headers;
    }

    /**
     * Whether any text of this request reads a placeholder this request has no right to, each refused beside its field.
     *
     * @param  array<string, string>  $texts  field path => its text
     * @param  \Closure(string): ?string  $problem  why a placeholder name cannot be used, if so
     * @param  array<string, list<string>>  $errors
     * @return array<string, ?string> every placeholder read
     */
    public function checkedPlaceholders(array $texts, \Closure $problem, array &$errors): array
    {
        $all = [];
        foreach ($texts as $path => $text) {
            foreach ($this->placeholders($text) as $name => $filter) {
                $all[$name] ??= $filter;
                if ($filter !== null && ! in_array($filter, config('kit.agents.template_filters'), true)) {
                    $errors[$path][] = __('agents.placeholder_filter', ['filter' => $filter]);
                }
                $why = $problem($name);
                if ($why !== null) {
                    $errors[$path][] = $why;
                }
            }
        }

        return $all;
    }
}
