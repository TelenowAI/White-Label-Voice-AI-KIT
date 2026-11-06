<?php

namespace App\Kit\Sms;

use App\Kit\Brand\Brand;
use App\Models\OutgoingSms;
use Illuminate\Support\Str;

/**
 * Sending the kit's texts, by SMS or WhatsApp (SELF_HOSTED_KIT_PLAN.md §4.7). Each kind's words are in lang/<locale>/sms.php
 * (`:brand` is the operator's brand name). A text goes to the senders in order at once, the first that takes it wins; it is
 * never tried again later — it is a one-time code that would be stale by then. Each one is recorded (kind, number, how it
 * went, each failed sender's reason), never its words. A code goes to the driver beside the words, because WhatsApp sends a
 * code through an approved template and not as free text; a test sends a stand-in code, to prove that template too.
 *
 * Not here: the senders (SmsSenders, SmsSetup), who gets a code (App\Kit\Auth\PhoneCodes).
 */
final class Sms
{
    public function __construct(private readonly SmsSenders $senders, private readonly Brand $brand) {}

    public function on(): bool
    {
        return $this->senders->on();
    }

    /** How codes reach people now: `sms`, `whatsapp`, `mixed` (some of each in the line), or null when texts are off. */
    public function channel(): ?string
    {
        $channels = array_values(array_unique(array_map(fn (array $s) => $this->senders->driver($s['driver'])->channel(), $this->senders->all())));

        return match (count($channels)) {
            0 => null,
            1 => $channels[0],
            default => 'mixed',
        };
    }

    /** Send one text of a kind to one number (E.164). Answers how it went. */
    public function send(string $kind, string $to, array $vars = []): OutgoingSms
    {
        $text = $this->text($kind, $vars);
        $errors = [];
        $sent = false;
        foreach ($this->senders->all() as $i => $sender) {
            try {
                $this->senders->driver($sender['driver'])->send($sender['config'], new SmsMessage($to, $text, $vars['code'] ?? ($kind === 'test' ? self::testCode() : null)));
                $sent = true;
                break;
            } catch (SmsFailed $e) {
                $errors[] = __('sms.sender_failed', ['n' => $i + 1, 'reason' => Str::limit($e->getMessage(), config('kit.sms.error_max_length'))]);
            }
        }
        $sms = new OutgoingSms;
        $sms->forceFill([
            'kind' => $kind, 'to_number' => $to, 'status' => $sent ? 'sent' : 'failed', 'attempts' => count($errors) + ($sent ? 1 : 0),
            'last_error' => $errors === [] ? null : implode(' ', $errors), 'sent_at' => $sent ? now() : null,
        ])->save();

        return $sms;
    }

    /** Send one test text with this sender only (it may not be saved yet), keeping nothing. Throws SmsFailed. */
    public function test(string $driver, array $config, string $to): void
    {
        $this->senders->driver($driver)->send($config, new SmsMessage($to, $this->text('test'), self::testCode()));
    }

    /** What a test sends in place of a code: as long as a real one, and plainly not one. */
    private static function testCode(): string
    {
        return str_repeat('0', (int) config('kit.phone.code_length'));
    }

    private function text(string $kind, array $vars = []): string
    {
        return __("sms.kinds.{$kind}", $vars + ['brand' => $this->brand->current()['name']]);
    }
}
