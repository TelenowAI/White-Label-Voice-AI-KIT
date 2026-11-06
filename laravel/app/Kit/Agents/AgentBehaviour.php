<?php

namespace App\Kit\Agents;

use App\Kit\Setup\Field;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rule;

/**
 * How an agent speaks and listens, in the kit's own words: one table of settings, each with where it lives in Telenow's blobs and
 * what kind of value it is. The checks, the reading, the writing and the form a customer sees all come from that table, so a
 * setting is added here, and in lang/en/agents.php for its words, and nowhere else.
 *
 * Telenow parses `session_config` and `telephony_config` whole, and one key of the wrong type turns every setting of that blob off
 * for the call. So what is written here has exactly the type Telenow's source declares (types/config.rs), recorded by
 * contract_k1settings.py. `absent` is what Telenow does when the key is not there, so a form shows what will really happen.
 *
 * Kinds: `switch` true or false, written either way; `onlyOn` written only when on, since these are off by not being there;
 * `choice` one of config('kit.agents.choices.<choices>'), left out for none when `absent` is null; `level` a word that stands for
 * a number (config('kit.agents.interruption_levels')); `preset` a choice that also switches on its block; `number` a whole number
 * within config('kit.agents.limits.<limits>'), left out when empty; `percent` the same, written as 0 to 1; `line` text.
 *
 * Left out on purpose: `turnTaking.tier`, a surcharge per minute; `transport` webrtc, which may charge; `delayedResponse` and
 * `defend`, gated on Telenow's own flags; `followup`, the uploads of a background sound. The caller's identity to tools waits for
 * the tools stage.
 *
 * Not here: opening lines, limits, transfer, analysis (AgentSettings, which calls this), sending (Telenow/*), drawing the form
 * (agents/AgentBehaviourForm.tsx).
 */
final class AgentBehaviour
{
    private const SESSION = 'sessionConfig';

    private const TELEPHONY = 'telephonyConfig';

    private const METADATA = 'metadata';

    private const TABLE = [
        'speaking' => [
            'tone' => ['blob' => self::SESSION, 'path' => 'behavior.tone', 'kind' => 'choice', 'choices' => 'tones', 'absent' => 'professional'],
            'personaGender' => ['blob' => self::SESSION, 'path' => 'personaGender', 'kind' => 'choice', 'choices' => 'genders', 'absent' => null, 'also' => [self::METADATA, 'gender']],
            'naturalFillers' => ['blob' => self::SESSION, 'path' => 'behavior.naturalFillers', 'kind' => 'switch', 'absent' => false],
            'highEmpathy' => ['blob' => self::SESSION, 'path' => 'behavior.highEmpathy', 'kind' => 'switch', 'absent' => false],
            'echoVerification' => ['blob' => self::SESSION, 'path' => 'behavior.echoVerification', 'kind' => 'switch', 'absent' => false],
            'natoPhonetic' => ['blob' => self::SESSION, 'path' => 'behavior.natoPhonetic', 'kind' => 'switch', 'absent' => false],
            'speechNormalization' => ['blob' => self::SESSION, 'path' => 'behavior.speechNormalization', 'kind' => 'switch', 'absent' => false],
            'smartMatching' => ['blob' => self::SESSION, 'path' => 'behavior.smartMatching', 'kind' => 'switch', 'absent' => false],
            'scopeBoundaries' => ['blob' => self::SESSION, 'path' => 'behavior.scopeBoundaries', 'kind' => 'switch', 'absent' => false],
            'aiDisclosure' => ['blob' => self::SESSION, 'path' => 'behavior.aiDisclosureWhenAsked', 'kind' => 'switch', 'absent' => true],
        ],
        'listening' => [
            'interruptions' => ['blob' => self::SESSION, 'path' => 'bargeInSensitivity', 'kind' => 'level', 'absent' => 'normal'],
            'rememberInterruptions' => ['blob' => self::SESSION, 'path' => 'rememberInterruptions', 'kind' => 'switch', 'absent' => false],
            'noisyRoom' => ['blob' => self::SESSION, 'path' => 'directedSpeechGate', 'kind' => 'onlyOn', 'absent' => false],
            'listeningSounds' => ['blob' => self::SESSION, 'path' => 'backchannel.enabled', 'kind' => 'switch', 'absent' => false],
            'ignoreSpeechBeforeGreeting' => ['blob' => self::TELEPHONY, 'path' => 'ignoreSpeechBeforeGreeting', 'kind' => 'onlyOn', 'absent' => false],
        ],
        'sounds' => [
            'thinkingFillers' => ['blob' => self::SESSION, 'path' => 'fillers.enabled', 'kind' => 'switch', 'absent' => false],
            'backgroundSound' => ['blob' => self::SESSION, 'path' => 'backgroundSound', 'kind' => 'preset', 'choices' => 'background_sounds', 'absent' => null],
            'backgroundVolume' => ['blob' => self::SESSION, 'path' => 'backgroundSound.volume', 'kind' => 'percent', 'limits' => 'background_volume_percent', 'absent' => 50, 'shownWith' => 'backgroundSound'],
            'ringInbound' => ['blob' => self::SESSION, 'path' => 'ringSound.inbound', 'kind' => 'switch', 'absent' => false],
            'ringOutbound' => ['blob' => self::SESSION, 'path' => 'ringSound.outbound', 'kind' => 'switch', 'absent' => false],
            'ringSeconds' => ['blob' => self::SESSION, 'path' => 'ringSound.secs', 'kind' => 'number', 'limits' => 'ring_sound_seconds', 'absent' => 4],
        ],
        'memory' => [
            'contextMode' => ['blob' => self::SESSION, 'path' => 'contextMode', 'kind' => 'choice', 'choices' => 'context_modes', 'absent' => 'window'],
            'contextTurns' => ['blob' => self::SESSION, 'path' => 'contextWindow', 'kind' => 'number', 'limits' => 'context_turns', 'absent' => 10, 'showWhen' => ['contextMode', ['window', 'summary']]],
            'callerMemory' => ['blob' => self::METADATA, 'path' => 'callerMemory.enabled', 'kind' => 'switch', 'absent' => false],
            'callerMemoryDays' => ['blob' => self::METADATA, 'path' => 'callerMemory.ttlDays', 'kind' => 'number', 'limits' => 'caller_memory_days', 'absent' => 90, 'showWhen' => ['callerMemory', ['1']]],
        ],
        'keypad' => [
            'keypad' => ['blob' => self::SESSION, 'path' => 'keypadInput.enabled', 'kind' => 'switch', 'absent' => false],
            'keypadWaitMs' => ['blob' => self::SESSION, 'path' => 'keypadInput.timeoutMs', 'kind' => 'number', 'limits' => 'keypad_wait_ms', 'absent' => 2500, 'showWhen' => ['keypad', ['1']]],
            'keypadEndKey' => ['blob' => self::SESSION, 'path' => 'keypadInput.terminationKey', 'kind' => 'choice', 'choices' => 'keypad_keys', 'absent' => null, 'showWhen' => ['keypad', ['1']]],
            'keypadDigits' => ['blob' => self::SESSION, 'path' => 'keypadInput.digitLimit', 'kind' => 'number', 'limits' => 'keypad_digits', 'absent' => 0, 'showWhen' => ['keypad', ['1']]],
            'optOut' => ['blob' => self::SESSION, 'path' => 'optOut', 'kind' => 'switch', 'absent' => true],
        ],
        'answering' => [
            'fastOpener' => ['blob' => self::TELEPHONY, 'path' => 'fastOpener', 'kind' => 'onlyOn', 'absent' => false],
            'greetingDelaySecs' => ['blob' => self::TELEPHONY, 'path' => 'greetingDelaySecs', 'kind' => 'number', 'limits' => 'greeting_delay_seconds', 'absent' => 0],
            'ringDurationSecs' => ['blob' => self::TELEPHONY, 'path' => 'ringDurationSecs', 'kind' => 'number', 'limits' => 'ring_duration_seconds', 'absent' => null],
            'callScreen' => ['blob' => self::TELEPHONY, 'path' => 'callScreen.enabled', 'kind' => 'switch', 'absent' => false],
            'callScreenMessage' => ['blob' => self::TELEPHONY, 'path' => 'callScreen.message', 'kind' => 'line', 'absent' => null, 'showWhen' => ['callScreen', ['1']]],
            'ivrHangup' => ['blob' => self::TELEPHONY, 'path' => 'ivrHangup.enabled', 'kind' => 'switch', 'absent' => false],
        ],
    ];

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->rows());
    }

    /** @return array<string, list<mixed>> validation rules, under `settings.` */
    public function rules(): array
    {
        $rules = [];
        foreach ($this->rows() as $key => $row) {
            $rules["settings.{$key}"] = match ($row['kind']) {
                'switch', 'onlyOn' => ['sometimes', 'boolean'],
                'choice', 'preset' => ['sometimes', ...($row['absent'] === null ? ['nullable'] : []), Rule::in(config("kit.agents.choices.{$row['choices']}"))],
                'level' => ['sometimes', Rule::in(array_keys(config('kit.agents.interruption_levels')))],
                'number', 'percent' => ['sometimes', 'nullable', 'integer', 'between:'.implode(',', config("kit.agents.limits.{$row['limits']}"))],
                'line' => ['sometimes', 'nullable', 'string', 'max:'.config('kit.agents.limits.line_max_length')],
            };
        }

        return $rules;
    }

    /** @return array<string, string> what a refusal calls each setting */
    public function names(): array
    {
        $names = [];
        foreach (array_keys($this->rows()) as $key) {
            $names["settings.{$key}"] = __("agents.behaviour.fields.{$key}.label");
        }

        return $names;
    }

    /**
     * The checked settings of `$values` in their exact types: only those sent.
     *
     * @return array<string, mixed>
     */
    public function tidied(array $values): array
    {
        $tidied = [];
        foreach ($this->rows() as $key => $row) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $value = $values[$key];
            $tidied[$key] = match ($row['kind']) {
                'switch', 'onlyOn' => (bool) $value,
                'number', 'percent' => $value === null ? null : (int) $value,
                default => $value,
            };
        }

        return $tidied;
    }

    /**
     * Each setting as Telenow will act on it.
     *
     * @param  array<string, array>  $blobs  by write name
     * @return array<string, mixed>
     */
    public function read(array $blobs): array
    {
        $values = [];
        foreach ($this->rows() as $key => $row) {
            $stored = $this->at($blobs[$row['blob']] ?? [], $row['path']);
            $values[$key] = match ($row['kind']) {
                'switch', 'onlyOn' => is_bool($stored) ? $stored : $row['absent'],
                'choice' => in_array($stored, config("kit.agents.choices.{$row['choices']}"), true) ? $stored : $row['absent'],
                'preset' => $this->readPreset($stored, $row),
                'level' => $this->readLevel($stored, $row),
                'number' => is_numeric($stored) ? (int) $stored : $row['absent'],
                'percent' => is_numeric($stored) ? (int) round($stored * 100) : $row['absent'],
                'line' => is_string($stored) && trim($stored) !== '' ? trim($stored) : null,
            };
        }

        return $values;
    }

    /**
     * Put one setting in its blobs. A block left with nothing in it goes, since an empty PHP array would be sent as a list.
     *
     * @param  array<string, array>  $blobs  by write name
     * @return list<string> the blobs it touched
     */
    public function write(array &$blobs, string $key, mixed $value): array
    {
        $row = $this->rows()[$key];
        if ($row['kind'] === 'preset') {
            $block = is_array($blobs[$row['blob']][$row['path']] ?? null) ? $blobs[$row['blob']][$row['path']] : [];
            $blobs[$row['blob']][$row['path']] = ['enabled' => $value !== null] + ($value === null ? [] : ['preset' => $value]) + $block;

            return [$row['blob']];
        }
        $this->put($blobs[$row['blob']], $row['path'], match (true) {
            $row['kind'] === 'onlyOn' => $value === true ? true : null,
            $row['kind'] === 'level' => config('kit.agents.interruption_levels')[$value],
            $row['kind'] === 'percent' && $value !== null => $value / 100,
            default => $value,
        });
        if (isset($row['also'])) {
            $this->put($blobs[$row['also'][0]], $row['also'][1], $value);

            return [$row['blob'], $row['also'][0]];
        }

        return [$row['blob']];
    }

    /** @return list<array{id: string, title: string, description: string, fields: list<array>}> the form, by group */
    public function form(): array
    {
        $groups = [];
        foreach (self::TABLE as $group => $rows) {
            $fields = [];
            foreach ($rows as $key => $row) {
                $fields[] = $this->field($key, $row)->toArray();
            }
            $groups[] = ['id' => $group, 'title' => __("agents.behaviour.groups.{$group}.title"), 'description' => __("agents.behaviour.groups.{$group}.description"), 'fields' => $fields];
        }

        return $groups;
    }

    private function field(string $key, array $row): Field
    {
        $help = Lang::has("agents.behaviour.fields.{$key}.help") ? __("agents.behaviour.fields.{$key}.help") : null;
        $options = [];
        if (in_array($row['kind'], ['choice', 'preset'], true)) {
            $options = array_map(fn (string $v) => ['value' => $v, 'label' => $this->choiceLabel($row['choices'], $v)], config("kit.agents.choices.{$row['choices']}"));
            if ($row['absent'] === null) {
                array_unshift($options, ['value' => '', 'label' => __("agents.behaviour.fields.{$key}.none")]);
            }
        } elseif ($row['kind'] === 'level') {
            $options = array_map(fn (string $v) => ['value' => $v, 'label' => __("agents.behaviour.choices.interruption_levels.{$v}")], array_keys(config('kit.agents.interruption_levels')));
        }
        $shownWith = isset($row['shownWith']) ? ['field' => $row['shownWith'], 'values' => config("kit.agents.choices.{$this->rows()[$row['shownWith']]['choices']}")] : null;
        $showWhen = isset($row['showWhen']) ? ['field' => $row['showWhen'][0], 'values' => $row['showWhen'][1]] : $shownWith;

        return new Field(
            name: $key,
            label: __("agents.behaviour.fields.{$key}.label"),
            type: match ($row['kind']) {
                'switch', 'onlyOn' => 'checkbox',
                'choice', 'preset', 'level' => 'select',
                'number', 'percent' => 'number',
                'line' => 'text',
            },
            help: $help,
            options: $options,
            showWhen: $showWhen,
        );
    }

    /** A key on a phone is its own name in every language; a word has its translation. */
    private function choiceLabel(string $choices, string $value): string
    {
        $words = "agents.behaviour.choices.{$choices}.{$value}";

        return Lang::has($words) ? __($words) : $value;
    }

    private function readPreset(mixed $block, array $row): ?string
    {
        if (! is_array($block) || ($block['enabled'] ?? false) !== true) {
            return $row['absent'];
        }
        $presets = config("kit.agents.choices.{$row['choices']}");

        return in_array($block['preset'] ?? null, $presets, true) ? $block['preset'] : $presets[0];
    }

    /** Telenow takes any number from 0 to 1: only 0 means it cannot be interrupted, and the nearest word stands for any other. */
    private function readLevel(mixed $stored, array $row): string
    {
        if (! is_numeric($stored)) {
            return $row['absent'];
        }
        $levels = config('kit.agents.interruption_levels');
        $candidates = array_filter($levels, fn ($number) => ($number > 0) === ($stored > 0));
        uasort($candidates, fn ($a, $b) => abs($a - $stored) <=> abs($b - $stored));

        return array_key_first($candidates);
    }

    private function at(array $blob, string $path): mixed
    {
        $value = $blob;
        foreach (explode('.', $path) as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /** Set one place in a blob, or with null take it out; a block left with nothing in it goes too. */
    private function put(array &$blob, string $path, mixed $value): void
    {
        [$first, $rest] = array_pad(explode('.', $path, 2), 2, null);
        if ($rest === null) {
            if ($value === null) {
                unset($blob[$first]);
            } else {
                $blob[$first] = $value;
            }

            return;
        }
        $block = is_array($blob[$first] ?? null) ? $blob[$first] : [];
        $this->put($block, $rest, $value);
        if ($block === []) {
            unset($blob[$first]);
        } else {
            $blob[$first] = $block;
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        return array_merge(...array_values(self::TABLE));
    }
}
