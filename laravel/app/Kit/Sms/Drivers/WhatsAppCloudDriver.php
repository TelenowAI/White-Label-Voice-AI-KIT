<?php

namespace App\Kit\Sms\Drivers;

use App\Kit\Setup\Field;
use App\Kit\Sms\SmsDriver;
use App\Kit\Sms\SmsFailed;
use App\Kit\Sms\SmsMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sending by WhatsApp through Meta's Cloud API (POST <graph>/<version>/<phone number id>/messages). WhatsApp only starts a
 * conversation from an approved template, so nothing here is free text:
 * - a code goes through the operator's Authentication template, whose copy button repeats it (Meta wants it in both);
 * - a notice goes through their Utility template with one variable, the kit's words, when they have one; without it this
 *   driver fails the message and the next sender in the line gets it.
 * The number goes without its `+`. A message counts as sent only when Meta answers with its id. The address and version
 * come from config('kit.sms.whatsapp_cloud').
 *
 * Not here: which sender goes first (SmsSenders), the words and the codes (App\Kit\Sms\Sms, App\Kit\Auth\PhoneCodes).
 */
final class WhatsAppCloudDriver implements SmsDriver
{
    private const TEMPLATE = '/^[a-z0-9_]{1,512}$/';

    public function fields(array $config): array
    {
        $text = fn (string $key) => __("sms.drivers.whatsapp_cloud.{$key}");

        return [
            new Field('phoneNumberId', $text('fields.phoneNumberId'), 'text', true, $config['phoneNumberId'] ?? null, $text('help.phoneNumberId')),
            new Field('accessToken', $text('fields.accessToken'), 'secret', true, null, $text('help.accessToken'), [], null, ($config['accessToken'] ?? '') !== ''),
            new Field('codeTemplate', $text('fields.codeTemplate'), 'text', true, $config['codeTemplate'] ?? null, $text('help.codeTemplate')),
            new Field('language', $text('fields.language'), 'text', true, $config['language'] ?? config('kit.sms.whatsapp_cloud.default_language'), $text('help.language')),
            new Field('noticeTemplate', $text('fields.noticeTemplate'), 'text', false, $config['noticeTemplate'] ?? null, $text('help.noticeTemplate')),
        ];
    }

    public function rules(): array
    {
        return [
            'phoneNumberId' => ['required', 'string', 'regex:/^[0-9]{1,40}$/'],
            'accessToken' => ['required', 'string', 'max:2000'],
            'codeTemplate' => ['required', 'string', 'regex:'.self::TEMPLATE],
            'language' => ['required', 'string', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
            'noticeTemplate' => ['nullable', 'string', 'regex:'.self::TEMPLATE],
        ];
    }

    public function secrets(): array
    {
        return ['accessToken'];
    }

    public function label(array $config): string
    {
        return __('sms.drivers.whatsapp_cloud.label', ['number' => $config['phoneNumberId'], 'template' => $config['codeTemplate']]);
    }

    public function channel(): string
    {
        return 'whatsapp';
    }

    public function send(array $config, SmsMessage $message): void
    {
        $c = config('kit.sms.whatsapp_cloud');
        try {
            $response = Http::timeout((int) $c['timeout_seconds'])->withToken((string) $config['accessToken'])->acceptJson()->asJson()->post(
                rtrim((string) $c['graph_url'], '/')."/{$c['graph_version']}/{$config['phoneNumberId']}/messages",
                ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => ltrim($message->to, '+'), 'type' => 'template',
                    'template' => $this->template($config, $message)],
            );
        } catch (ConnectionException $e) {
            throw new SmsFailed($e->getMessage(), 0, $e);
        }
        $body = Str::limit(trim((string) $response->body()), config('kit.sms.http.answer_max_length'));
        if (! $response->successful()) {
            throw new SmsFailed(__('sms.drivers.whatsapp_cloud.refused', ['status' => $response->status(), 'body' => $body]));
        }
        if (! is_string($response->json('messages.0.id'))) {
            throw new SmsFailed(__('sms.drivers.whatsapp_cloud.not_success', ['body' => $body]));
        }
    }

    private function template(array $config, SmsMessage $message): array
    {
        $text = fn (string $value) => [['type' => 'text', 'text' => $value]];
        $language = ['code' => $config['language']];
        if ($message->code !== null) {
            return ['name' => $config['codeTemplate'], 'language' => $language, 'components' => [
                ['type' => 'body', 'parameters' => $text($message->code)],
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => $text($message->code)],
            ]];
        }
        if (($config['noticeTemplate'] ?? '') === '') {
            throw new SmsFailed(__('sms.drivers.whatsapp_cloud.no_notice_template'));
        }

        return ['name' => $config['noticeTemplate'], 'language' => $language, 'components' => [
            // A template's variable may hold no line breaks or runs of spaces.
            ['type' => 'body', 'parameters' => $text((string) preg_replace('/\s+/u', ' ', $message->text))],
        ]];
    }
}
