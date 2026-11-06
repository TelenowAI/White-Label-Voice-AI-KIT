<?php

namespace App\Kit\Agents;

use App\Kit\Settings\Settings;
use App\Kit\Sms\PhoneNumber;
use App\Models\Agent;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * How an agent behaves on a call and after it, in the kit's own words: its opening lines, recording, call limits, hanging up,
 * transfer to a person and post-call analysis. This class checks what a customer sends and turns it into Telenow's blobs and back.
 *
 * Telenow's document says only that `telephony_config`, `session_config` and `metadata` are objects, so this is the one place
 * that names the keys inside them, read from Telenow's source and recorded by contract_k1settings.py. What was recorded:
 * - a blob that is sent replaces the stored one whole, so a write starts from Telenow's current blob and changes only the keys
 *   that changed (writes), and sends only the blobs it touched;
 * - Telenow keeps whatever it is sent, over-limit values and unusable numbers too, so the kit checks them here;
 * - with nothing set Telenow starts no call limit, no silence hang-up and no way for the agent to hang up, and a phone call opens
 *   in silence. So a new agent gets config('kit.agents.new_agent_settings'), and an opening line is written `none` when there is
 *   none, so a browser test call behaves as a phone call will.
 *
 * How it speaks and listens is a table of its own (AgentBehaviour), and its tools, look-ups and hand-overs are classes of their own
 * (AgentTools, AgentLookups, AgentHandoff): all kept in the same blobs and written the same way, through here. Each provider's own settings
 * (AgentProviderSettings) live in that provider's blob and are written the same way, and so does its realtime voice (AgentRealtime, `s2s_config`).
 *
 * Not here: sending (Telenow/*), what a form shows (agents/AgentSettingsForm.tsx), taking transfer off when the operator turns it
 * off (AgentTransfers).
 */
final class AgentSettings
{
    /** Where an outbox body carries the settings that changed: they become blobs only when it is sent, from Telenow's blobs as they are then. */
    public const CHANGES = 'kitSettings';

    /** The operator's yes to customers setting up transfers (Admin → Catalog): none until it is given, see transferAllowed. */
    public const TRANSFER_ALLOWED = 'agents.transfer_allowed';

    private const TRANSFER_TOOL = 'transfer_to_human';

    public function __construct(private readonly Settings $store, private readonly AgentBehaviour $behaviour, private readonly AgentTools $tools, private readonly AgentLookups $lookups, private readonly AgentHandoff $handoff, private readonly AgentFlow $flow, private readonly SavedKeys $keys, private readonly AfterCallEmail $email, private readonly AgentProviderSettings $providerSettings, private readonly AgentRealtime $realtime) {}

    public function transferAllowed(): bool
    {
        return $this->store->get(self::TRANSFER_ALLOWED, false) === true;
    }

    /** @return array<string, mixed> an agent's settings: what was last written, and for the rest what Telenow's object says */
    public function of(Agent $agent): array
    {
        return ['emailAfterCall' => $this->email->of($agent)] + ($agent->settings ?? []) + $this->read($agent->telenow_snapshot ?? []);
    }

    /** @return array<string, mixed> what a new agent starts with, and what the customer gave on top */
    public function forNewAgent(array $given): array
    {
        return $given + config('kit.agents.new_agent_settings') + ['greetingInbound' => null, 'greetingOutbound' => null];
    }

    /**
     * The settings in `$input['settings']`, checked and tidied: only those sent. `$agent`: the agent they are for, none while it is being made,
     * when it has no tools to keep keys for and no id for another agent to hand over to, so those wait until it exists.
     *
     * @return array<string, mixed>
     */
    public function validated(array $input, ?Agent $agent = null): array
    {
        if (! array_key_exists('settings', $input)) {
            return [];
        }
        $limits = config('kit.agents.limits');
        $line = ['sometimes', 'nullable', 'string', 'max:'.$limits['line_max_length']];
        $key = ['required', 'string', 'max:'.$limits['analysis_key_max_length'], 'regex:'.$limits['analysis_key_pattern']];
        $seconds = fn (string $name) => 'between:'.$limits[$name][0].','.$limits[$name][1];
        $values = Validator::make($input, [
            'settings' => ['array'],
            'settings.greetingInbound' => $line,
            'settings.greetingOutbound' => $line,
            'settings.voicemailMessage' => $line,
            'settings.recording' => ['sometimes', 'boolean'],
            'settings.maxDurationSecs' => ['sometimes', 'integer', $seconds('max_duration_seconds')],
            'settings.maxDurationMessage' => $line,
            'settings.silenceHangupSecs' => ['sometimes', 'nullable', 'integer', $seconds('silence_seconds')],
            'settings.silenceHangupMessage' => $line,
            'settings.silenceCheckinSecs' => ['sometimes', 'nullable', 'integer', $seconds('silence_seconds')],
            'settings.silenceCheckinMessage' => $line,
            'settings.canEndCall' => ['sometimes', 'boolean'],
            'settings.transfer' => ['sometimes', 'array'],
            'settings.transfer.enabled' => ['required_with:settings.transfer', 'boolean'],
            'settings.transfer.destinations' => ['present_with:settings.transfer', 'array', 'max:'.$limits['transfer_destinations_max']],
            'settings.transfer.destinations.*.label' => ['nullable', 'string', 'max:'.$limits['transfer_label_max_length']],
            'settings.transfer.destinations.*.numbers' => ['required', 'array', 'min:1', 'max:'.$limits['transfer_numbers_max']],
            'settings.transfer.destinations.*.numbers.*' => ['required', 'string'],
            'settings.transfer.destinations.*.mode' => ['required', Rule::in(config('kit.agents.transfer_modes'))],
            'settings.transfer.message' => $line,
            'settings.transfer.noAnswerMessage' => $line,
            'settings.analysis' => ['sometimes', 'array'],
            'settings.analysis.enabled' => ['required_with:settings.analysis', 'boolean'],
            'settings.analysis.minDurationSec' => ['required_with:settings.analysis', 'integer', 'between:0,'.$limits['analysis_min_duration_max']],
            'settings.analysis.minCustomerTurns' => ['required_with:settings.analysis', 'integer', 'between:0,'.$limits['analysis_min_turns_max']],
            'settings.analysis.customFields' => ['present_with:settings.analysis', 'array', 'max:'.$limits['analysis_fields_max']],
            'settings.analysis.customFields.*.key' => $key,
            'settings.analysis.customFields.*.label' => ['nullable', 'string', 'max:'.$limits['analysis_label_max_length']],
            'settings.analysis.customFields.*.type' => ['required', Rule::in(config('kit.agents.analysis_field_types'))],
            'settings.analysis.customFields.*.description' => ['nullable', 'string', 'max:'.$limits['line_max_length']],
            'settings.analysis.qaCriteria' => ['present_with:settings.analysis', 'array', 'max:'.$limits['analysis_fields_max']],
            'settings.analysis.qaCriteria.*.key' => $key,
            'settings.analysis.qaCriteria.*.description' => ['nullable', 'string', 'max:'.$limits['line_max_length']],
        ] + $this->behaviour->rules() + $this->tools->rules() + $this->lookups->rules() + $this->handoff->rules(), [], $this->names())->validate()['settings'] ?? [];

        if ($agent === null) {
            $this->refuseWhatWaits($values);
        }
        $now = $agent === null ? [] : $this->of($agent);
        if (isset($values['tools'])) {
            $values['tools'] = $this->tools->checked($values, $now);
        }
        if (isset($values['lookups'])) {
            $values['lookups'] = $this->lookups->checked($values, $now);
        }
        if (isset($values['handoff'])) {
            $values['handoff'] = $this->handoff->checked($values['handoff'], $agent);
        }
        foreach (['canAdjustVolume', 'sendCallerToTools'] as $switch) {
            if (array_key_exists($switch, $values)) {
                $values[$switch] = (bool) $values[$switch];
            }
        }
        if (isset($values['sendCallerToTools']) && ! isset($values['tools']) && $values['sendCallerToTools'] === false) {
            $this->tools->checked(['tools' => $now['tools'] ?? [], 'sendCallerToTools' => false], $now);
        }
        $this->refuseAddressChangeAboveKeys($values, $now);

        if (isset($values['transfer'])) {
            $values['transfer'] = $this->checkedTransfer($values['transfer']);
        }
        if (isset($values['analysis'])) {
            $values['analysis'] = $this->checkedAnalysis($values['analysis']);
        }
        foreach (['recording', 'canEndCall'] as $switch) {
            if (array_key_exists($switch, $values)) {
                $values[$switch] = (bool) $values[$switch];
            }
        }
        foreach (['maxDurationSecs', 'silenceHangupSecs', 'silenceCheckinSecs'] as $number) {
            if (isset($values[$number])) {
                $values[$number] = (int) $values[$number];
            }
        }

        return $this->behaviour->tidied($values) + $values;
    }

    /** Whether saving `$value` changes the setting: a key typed always does, whatever it looks like when masked. */
    public function changed(array $now, string $key, mixed $value): bool
    {
        return $this->keys->without($value) !== ($now[$key] ?? null) || $this->keys->typed($value);
    }

    /** What may be kept of settings in the kit's own column and answered: no key typed. */
    public function public(array $settings): array
    {
        return array_map(fn ($value) => $this->keys->without($value), $settings);
    }

    /** @return list<array{agent: Agent, handoff: array}> the other agents handing over to `$agent` */
    /** @return array{flow: ?array, kept: list<string>} the agent's published steps with what may no longer stay taken off */
    public function cleanedFlow(Agent $agent): array
    {
        return $this->flow->cleaned($this->of($agent)['flow'] ?? null, $agent, $this);
    }

    public function handingOverTo(Agent $agent): array
    {
        return $this->handoff->pointingAt($agent, $this);
    }

    private function refuseWhatWaits(array $values): void
    {
        foreach (['tools', 'lookups', 'handoff'] as $key) {
            if (array_key_exists($key, $values)) {
                throw ValidationException::withMessages(["settings.{$key}" => [__('agents.tools_after_create')]]);
            }
        }
    }

    /**
     * Telenow also reads a web address anywhere directly in `metadata` as a place the keys beside it go: a voicemail line gaining or losing one makes
     * it refuse the whole save (recorded), and it is the one line the kit keeps there. So it is refused here while a key would have to stay.
     */
    private function refuseAddressChangeAboveKeys(array $values, array $now): void
    {
        if (! array_key_exists('voicemailMessage', $values)) {
            return;
        }
        $line = $this->tidy($values['voicemailMessage']);
        $old = $now['voicemailMessage'] ?? null;
        if ($line === $old || ! str_contains($line.$old, '://')) {
            return;
        }
        $tools = $values['tools'] ?? $now['tools'] ?? [];
        $lookups = $values['lookups'] ?? $now['lookups'] ?? [];
        if (array_filter($tools, $this->tools->keepsKey(...)) !== [] || array_filter($lookups, $this->lookups->keepsKey(...)) !== [] || $this->flow->keepsKey($now['flow'] ?? null)) {
            throw ValidationException::withMessages(['settings.voicemailMessage' => [__('agents.voicemail_address_keys')]]);
        }
    }

    /**
     * The settings as Telenow's object has them, each as Telenow will act on it (a key it has not got means what it means there).
     *
     * @return array<string, mixed>
     */
    public function read(array $object): array
    {
        $telephony = $this->blob($object['telephony_config'] ?? null);
        $session = $this->blob($object['session_config'] ?? null);
        $metadata = $this->blob($object['metadata'] ?? null);
        $checkin = $session['silenceCheckin'] ?? null;
        $transfer = $this->tool($metadata, 'transfer');
        $analysis = $this->blob($metadata['postCallAnalysis'] ?? null);

        return [
            'flow' => $this->flow->read($metadata, $this),
            'greetingInbound' => $this->opener($telephony, 'firstResponse', 'agentMsg'),
            'greetingOutbound' => $this->opener($telephony, 'firstResponseOutbound', 'agentMsgOutbound'),
            'voicemailMessage' => $this->line($metadata['voicemailMessage'] ?? null),
            'recording' => ($telephony['recording'] ?? true) !== false,
            'maxDurationSecs' => ($session['maxDuration'] ?? 0) > 0 ? (int) $session['maxDuration'] : null,
            'maxDurationMessage' => $this->line($session['maxDurationMessage'] ?? null),
            'silenceHangupSecs' => ($session['silenceHangupSecs'] ?? 0) > 0 ? (int) $session['silenceHangupSecs'] : null,
            'silenceHangupMessage' => $this->line($session['silenceHangupMessage'] ?? null),
            'silenceCheckinSecs' => is_array($checkin) && ($checkin['enabled'] ?? true) === false ? null
                : (int) ($this->blob($checkin)['secs'] ?? config('kit.agents.telenow_silence_checkin_seconds')),
            'silenceCheckinMessage' => $this->line($this->blob($checkin)['message'] ?? null),
            'canEndCall' => $this->tool($metadata, 'end_call') !== null,
            'transfer' => $this->readTransfer($transfer),
            'analysis' => [
                'enabled' => ($analysis['enabled'] ?? false) === true,
                'minDurationSec' => (int) ($analysis['minDurationSec'] ?? config('kit.agents.telenow_analysis_min_duration')),
                'minCustomerTurns' => (int) ($analysis['minCustomerTurns'] ?? config('kit.agents.telenow_analysis_min_turns')),
                'customFields' => array_values(array_map(fn ($f) => [
                    'key' => (string) ($f['key'] ?? ''), 'label' => $this->tidy($f['label'] ?? null), 'type' => (string) ($f['type'] ?? 'string'), 'description' => $this->tidy($f['description'] ?? null),
                ], $this->rows($analysis['customFields'] ?? null))),
                'qaCriteria' => array_values(array_map(fn ($c) => ['key' => (string) ($c['key'] ?? ''), 'description' => $this->tidy($c['description'] ?? null)], $this->rows($analysis['qaCriteria'] ?? null))),
            ],
            // An agent that can transfer always says it is an AI when asked, whatever is stored.
        ] + ($transfer === null ? [] : ['aiDisclosure' => true]) + $this->behaviour->read(['telephonyConfig' => $telephony, 'sessionConfig' => $session, 'metadata' => $metadata])
            + $this->tools->read($metadata) + $this->lookups->read($metadata) + $this->handoff->read($metadata) + $this->providerSettings->read($object) + $this->realtime->read($object);
    }

    /**
     * What to send so Telenow has `$changed`: only the blobs they live in, each `$object`'s own with only those keys changed.
     *
     * @param  array<string, mixed>  $object  the agent at Telenow, with a stored `{}` kept as `{}` (App\Kit\Support\Json::decode)
     * @param  array<string, mixed>  $changed
     * @return array<string, mixed> by write name
     */
    public function writes(array $object, array $changed): array
    {
        $telephony = $this->blob($object['telephony_config'] ?? null);
        $session = $this->blob($object['session_config'] ?? null);
        $metadata = $this->blob($object['metadata'] ?? null);
        $touched = [];
        $blobs = ['telephonyConfig' => &$telephony, 'sessionConfig' => &$session, 'metadata' => &$metadata];
        $providerBlobs = [];
        foreach ($changed as $key => $value) {
            if ($this->providerSettings->has($key)) {
                $this->providerSettings->write($providerBlobs, $object, $key, $value);

                continue;
            }
            if ($this->realtime->has($key)) {
                $this->realtime->write($providerBlobs, $object, $value);

                continue;
            }
            if ($this->behaviour->has($key)) {
                $touched += array_fill_keys($this->behaviour->write($blobs, $key, $value), true);

                continue;
            }
            if ($this->tools->has($key) || $this->lookups->has($key) || $this->handoff->has($key) || $this->flow->has($key)) {
                match (true) {
                    $this->tools->has($key) => $this->tools->write($metadata, $key, $value),
                    $this->lookups->has($key) => $this->lookups->write($metadata, $value),
                    $this->flow->has($key) => $this->flow->write($metadata, $value, $this),
                    default => $this->handoff->write($metadata, $value),
                };
                $touched['metadata'] = true;

                continue;
            }
            $touched[match ($key) {
                'greetingInbound' => $this->writeOpener($telephony, 'firstResponse', 'agentMsg', $value),
                'greetingOutbound' => $this->writeOpener($telephony, 'firstResponseOutbound', 'agentMsgOutbound', $value),
                'recording' => $this->set($telephony, 'recording', $value, 'telephonyConfig'),
                'voicemailMessage' => $this->setOrDrop($metadata, 'voicemailMessage', $value, 'metadata'),
                'maxDurationSecs' => $this->set($session, 'maxDuration', $value, 'sessionConfig'),
                'maxDurationMessage' => $this->setOrDrop($session, 'maxDurationMessage', $value, 'sessionConfig'),
                'silenceHangupSecs' => $this->setOrDrop($session, 'silenceHangupSecs', $value, 'sessionConfig'),
                'silenceHangupMessage' => $this->setOrDrop($session, 'silenceHangupMessage', $value, 'sessionConfig'),
                'silenceCheckinSecs' => $this->writeCheckinSeconds($session, $value),
                'silenceCheckinMessage' => $this->writeCheckinMessage($session, $value),
                'canEndCall' => $this->writeEndCall($metadata, $value),
                'transfer' => $this->writeTransfer($metadata, $value),
                'analysis' => $this->writeAnalysis($metadata, $value),
            }] = true;
        }

        return array_map(fn (array $blob) => $blob === [] ? new \stdClass : $blob, array_intersect_key(['telephonyConfig' => $telephony, 'sessionConfig' => $session, 'metadata' => $metadata], $touched) + $providerBlobs);
    }

    private function tidy(mixed $text): ?string
    {
        $text = is_string($text) ? trim($text) : '';

        return $text === '' ? null : $text;
    }

    /** A stored line: Telenow may keep it with its translations (`source`), and a customer changing it writes it plain. */
    public function line(mixed $stored): ?string
    {
        return $this->tidy(is_array($stored) ? ($stored['source'] ?? null) : $stored);
    }

    /** @return array<string, mixed> */
    private function blob(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private function opener(array $telephony, string $mode, string $text): ?string
    {
        return ($telephony[$mode] ?? null) === 'agent' ? $this->tidy($telephony[$text] ?? null) : null;
    }

    private function tool(array $metadata, string $kind): ?array
    {
        foreach ($this->rows($metadata['tools'] ?? null) as $tool) {
            if (($tool['kind'] ?? null) === $kind) {
                return $tool;
            }
        }

        return null;
    }

    public function readTransfer(?array $tool): array
    {
        $config = $this->blob($tool['config'] ?? null);

        return [
            'enabled' => $tool !== null,
            'destinations' => array_values(array_map(fn (array $d) => [
                'label' => $this->tidy($d['label'] ?? null),
                'numbers' => array_values(array_filter((array) ($d['numbers'] ?? []), 'is_string')),
                'mode' => in_array($d['mode'] ?? null, config('kit.agents.transfer_modes'), true) ? $d['mode'] : config('kit.agents.transfer_modes')[0],
            ], $this->rows($config['destinations'] ?? null))),
            'message' => $this->line($config['message'] ?? null),
            'noAnswerMessage' => $this->line($config['noAnswerMessage'] ?? null),
        ];
    }

    private function writeOpener(array &$telephony, string $mode, string $text, ?string $line): string
    {
        $telephony[$mode] = $line === null ? 'none' : 'agent';
        $telephony[$text] = $line ?? '';

        return 'telephonyConfig';
    }

    private function set(array &$blob, string $key, mixed $value, string $writtenAs): string
    {
        $blob[$key] = $value;

        return $writtenAs;
    }

    private function setOrDrop(array &$blob, string $key, mixed $value, string $writtenAs): string
    {
        if ($value === null) {
            unset($blob[$key]);
        } else {
            $blob[$key] = $value;
        }

        return $writtenAs;
    }

    /** Off is written `enabled: false`: with no block at all Telenow checks in anyway. */
    private function writeCheckinSeconds(array &$session, ?int $seconds): string
    {
        $block = $this->blob($session['silenceCheckin'] ?? null);
        if ($seconds === null) {
            unset($block['secs']);
            $block['enabled'] = false;
        } else {
            $block['enabled'] = true;
            $block['secs'] = $seconds;
        }
        $session['silenceCheckin'] = $block;

        return 'sessionConfig';
    }

    private function writeCheckinMessage(array &$session, ?string $line): string
    {
        $block = $this->blob($session['silenceCheckin'] ?? null);
        $this->setOrDrop($block, 'message', $line, 'sessionConfig');
        $session['silenceCheckin'] = $block === [] ? new \stdClass : $block;

        return 'sessionConfig';
    }

    /** Only the row of that kind changes: tools the kit does not know stay as they are. */
    private function withTool(array &$metadata, string $kind, ?array $row): void
    {
        $tools = array_values(array_filter((array) ($metadata['tools'] ?? []), fn ($t) => ! is_array($t) || ($t['kind'] ?? null) !== $kind));
        if ($row !== null) {
            $tools[] = $row;
        }
        $metadata['tools'] = $tools;
    }

    private function writeEndCall(array &$metadata, bool $on): string
    {
        $this->withTool($metadata, 'end_call', $on ? ['name' => 'end_call', 'kind' => 'end_call', 'description' => config('kit.agents.tool_descriptions.end_call')] : null);

        return 'metadata';
    }

    private function writeTransfer(array &$metadata, array $transfer): string
    {
        $this->withTool($metadata, 'transfer', $transfer['enabled']
            ? ['name' => self::TRANSFER_TOOL, 'kind' => 'transfer', 'description' => config('kit.agents.tool_descriptions.transfer'), 'config' => $this->transferConfig($transfer)] : null);

        return 'metadata';
    }

    /** @return array<string, mixed> a transfer as Telenow's `config` */
    public function transferConfig(array $transfer): array
    {
        return array_filter(['destinations' => array_map(fn (array $d) => array_filter($d, fn ($v) => $v !== null), $transfer['destinations']),
            'message' => $transfer['message'], 'noAnswerMessage' => $transfer['noAnswerMessage']], fn ($v) => $v !== null);
    }

    /** Telenow's other keys of the block (the model, the sections, the wording) stay as they are. */
    private function writeAnalysis(array &$metadata, array $analysis): string
    {
        $metadata['postCallAnalysis'] = array_merge($this->blob($metadata['postCallAnalysis'] ?? null), [
            'enabled' => $analysis['enabled'],
            'minDurationSec' => $analysis['minDurationSec'],
            'minCustomerTurns' => $analysis['minCustomerTurns'],
            'customFields' => array_map(fn (array $f) => array_filter($f, fn ($v) => $v !== null), $analysis['customFields']),
            'qaCriteria' => array_map(fn (array $c) => array_filter($c, fn ($v) => $v !== null), $analysis['qaCriteria']),
        ]);

        return 'metadata';
    }

    /** A transfer the agent can really make: numbers in E.164, and a label to pick by when there is more than one place to go. */
    public function checkedTransfer(array $transfer, string $at = 'settings.transfer'): array
    {
        if ($transfer['enabled'] && ! $this->transferAllowed()) {
            throw ValidationException::withMessages(["{$at}.enabled" => [__('agents.transfer_off')]]);
        }
        $errors = [];
        $destinations = [];
        $labels = [];
        $rows = array_values($transfer['destinations'] ?? []);
        foreach ($rows as $i => $d) {
            $numbers = [];
            foreach (array_values($d['numbers']) as $n => $number) {
                $normal = PhoneNumber::normalize($number);
                if ($normal === null) {
                    $errors["{$at}.destinations.{$i}.numbers.{$n}"] = [__('agents.transfer_number_shape')];
                } elseif (in_array($normal, $numbers, true)) {
                    $errors["{$at}.destinations.{$i}.numbers.{$n}"] = [__('agents.transfer_number_twice')];
                } else {
                    $numbers[] = $normal;
                }
            }
            $label = $this->tidy($d['label'] ?? null);
            if (count($rows) > 1 && $label === null) {
                $errors["{$at}.destinations.{$i}.label"] = [__('agents.transfer_label_needed')];
            } elseif ($label !== null && in_array(mb_strtolower($label), $labels, true)) {
                $errors["{$at}.destinations.{$i}.label"] = [__('agents.transfer_label_twice')];
            }
            $labels[] = mb_strtolower((string) $label);
            $destinations[] = ['label' => $label, 'numbers' => $numbers, 'mode' => $d['mode']];
        }
        if (($transfer['enabled'] ?? false) && $rows === []) {
            $errors["{$at}.destinations"] = [__('agents.transfer_nowhere')];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['enabled' => (bool) $transfer['enabled'], 'destinations' => $destinations,
            'message' => $this->tidy($transfer['message'] ?? null), 'noAnswerMessage' => $this->tidy($transfer['noAnswerMessage'] ?? null)];
    }

    private function checkedAnalysis(array $analysis): array
    {
        $errors = [];
        $fields = [];
        $criteria = [];
        foreach (['customFields' => 'fields', 'qaCriteria' => 'criteria'] as $list => $into) {
            $seen = [];
            foreach (array_values($analysis[$list] ?? []) as $i => $row) {
                if (in_array($row['key'], $seen, true)) {
                    $errors["settings.analysis.{$list}.{$i}.key"] = [__('agents.analysis_key_twice')];
                }
                $seen[] = $row['key'];
                ${$into}[] = $into === 'fields'
                    ? ['key' => $row['key'], 'label' => $this->tidy($row['label'] ?? null), 'type' => $row['type'], 'description' => $this->tidy($row['description'] ?? null)]
                    : ['key' => $row['key'], 'description' => $this->tidy($row['description'] ?? null)];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['enabled' => (bool) $analysis['enabled'], 'minDurationSec' => (int) $analysis['minDurationSec'], 'minCustomerTurns' => (int) $analysis['minCustomerTurns'],
            'customFields' => $fields, 'qaCriteria' => $criteria];
    }

    /** @return array<string, string> */
    private function names(): array
    {
        $names = [];
        foreach (__('agents.settings') as $path => $name) {
            $names["settings.{$path}"] = $name;
        }

        foreach (__('agents.tool_settings') as $path => $name) {
            $names["settings.{$path}"] = $name;
        }

        return $names + $this->behaviour->names();
    }
}
