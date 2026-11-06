<?php

namespace App\Kit\Sms\Drivers;

use App\Kit\Setup\Field;
use App\Kit\Sms\SmsDriver;
use App\Kit\Sms\SmsFailed;
use App\Kit\Sms\SmsMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The built-in SMS driver: any provider that sends a text when asked over HTTP, described by the operator rather than
 * coded per vendor — the address, GET (parameters in the query) or POST (JSON or a form), the names of its number and text parameters, the number
 * with or without its `+`, an optional sender id, and the API key as a parameter and/or a header (both secrets). A
 * text counts as sent on a 2xx answer, and, when the operator gives `successText`, only if the answer contains it
 * (providers that answer 200 with an error inside). The choices come from config('kit.sms.http').
 *
 * Not here: which sender goes first (SmsSenders), the words (App\Kit\Sms\Sms).
 */
final class HttpDriver implements SmsDriver
{
    private const PARAM = '/^[A-Za-z0-9_.\[\]-]{1,60}$/';

    private const HEADER = '/^[A-Za-z0-9-]{1,60}$/';

    public function fields(array $config): array
    {
        $c = config('kit.sms.http');
        $text = fn (string $key) => __("sms.drivers.http.{$key}");
        $choices = fn (string $group, array $values) => array_map(fn (string $v) => ['value' => $v, 'label' => $text("{$group}.{$v}")], $values);
        $posting = ['field' => 'method', 'values' => array_values(array_diff($c['methods'], ['GET']))];

        return [
            new Field('url', $text('fields.url'), 'url', true, $config['url'] ?? null, $text('help.url')),
            new Field('method', $text('fields.method'), 'select', true, $config['method'] ?? $c['methods'][0], null, $choices('methods', $c['methods'])),
            new Field('format', $text('fields.format'), 'select', true, $config['format'] ?? $c['formats'][0], null, $choices('formats', $c['formats']), $posting),
            new Field('toParam', $text('fields.toParam'), 'text', true, $config['toParam'] ?? $c['default_to_param']),
            new Field('numberFormat', $text('fields.numberFormat'), 'select', true, $config['numberFormat'] ?? $c['number_formats'][0], null,
                $choices('numberFormats', $c['number_formats'])),
            new Field('textParam', $text('fields.textParam'), 'text', true, $config['textParam'] ?? $c['default_text_param']),
            new Field('fromParam', $text('fields.fromParam'), 'text', false, $config['fromParam'] ?? null, $text('help.fromParam')),
            new Field('from', $text('fields.from'), 'text', false, $config['from'] ?? null),
            new Field('keyParam', $text('fields.keyParam'), 'text', false, $config['keyParam'] ?? null, $text('help.keyParam')),
            new Field('keyValue', $text('fields.keyValue'), 'secret', false, null, null, [], null, ($config['keyValue'] ?? '') !== ''),
            new Field('headerName', $text('fields.headerName'), 'text', false, $config['headerName'] ?? null, $text('help.headerName')),
            new Field('headerValue', $text('fields.headerValue'), 'secret', false, null, null, [], null, ($config['headerValue'] ?? '') !== ''),
            new Field('successText', $text('fields.successText'), 'text', false, $config['successText'] ?? null, $text('help.successText')),
        ];
    }

    public function rules(): array
    {
        $c = config('kit.sms.http');

        return [
            'url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'method' => ['required', 'string', Rule::in($c['methods'])],
            'format' => ['required_unless:method,GET', 'nullable', 'string', Rule::in($c['formats'])],
            'toParam' => ['required', 'string', 'regex:'.self::PARAM],
            'numberFormat' => ['required', 'string', Rule::in($c['number_formats'])],
            'textParam' => ['required', 'string', 'regex:'.self::PARAM, 'different:toParam'],
            'fromParam' => ['nullable', 'required_with:from', 'string', 'regex:'.self::PARAM],
            'from' => ['nullable', 'string', 'max:40'],
            'keyParam' => ['nullable', 'required_with:keyValue', 'string', 'regex:'.self::PARAM],
            'keyValue' => ['nullable', 'required_with:keyParam', 'string', 'max:1000'],
            'headerName' => ['nullable', 'required_with:headerValue', 'string', 'regex:'.self::HEADER],
            'headerValue' => ['nullable', 'required_with:headerName', 'string', 'max:2000'],
            'successText' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function secrets(): array
    {
        return ['keyValue', 'headerValue'];
    }

    public function label(array $config): string
    {
        return __('sms.drivers.http.label', ['method' => $config['method'], 'host' => (string) parse_url((string) $config['url'], PHP_URL_HOST)]);
    }

    public function channel(): string
    {
        return 'sms';
    }

    public function send(array $config, SmsMessage $message): void
    {
        $params = [
            $config['toParam'] => $config['numberFormat'] === 'digits' ? ltrim($message->to, '+') : $message->to,
            $config['textParam'] => $message->text,
        ];
        if (($config['fromParam'] ?? '') !== '' && ($config['from'] ?? '') !== '') {
            $params[$config['fromParam']] = $config['from'];
        }
        if (($config['keyParam'] ?? '') !== '') {
            $params[$config['keyParam']] = (string) $config['keyValue'];
        }
        $http = Http::timeout((int) config('kit.sms.http.timeout_seconds'));
        if (($config['headerName'] ?? '') !== '') {
            $http = $http->withHeaders([$config['headerName'] => (string) $config['headerValue']]);
        }
        try {
            $response = match (true) {
                $config['method'] === 'GET' => $http->get($config['url'], $params),
                $config['format'] === 'form' => $http->asForm()->post($config['url'], $params),
                default => $http->asJson()->post($config['url'], $params),
            };
        } catch (ConnectionException $e) {
            throw new SmsFailed($e->getMessage(), 0, $e);
        }
        $body = Str::limit(trim((string) $response->body()), config('kit.sms.http.answer_max_length'));
        if (! $response->successful()) {
            throw new SmsFailed(__('sms.drivers.http.refused', ['status' => $response->status(), 'body' => $body]));
        }
        if (($config['successText'] ?? '') !== '' && ! str_contains((string) $response->body(), $config['successText'])) {
            throw new SmsFailed(__('sms.drivers.http.not_success', ['body' => $body]));
        }
    }
}
