<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Telenow for the Laravel tests: the same recordings the contract's mock serves (contract/telenow-mock/cassettes,
 * each a real Telenow answer held to Telenow's released document), matched the same way — method, path and the
 * placeholder key the recording names, and its Idempotency-Key when it names one. Anything not recorded answers 404,
 * so a test never gets a made-up answer and never reaches a real Telenow. A recording that names `queryNames` answers
 * only a request carrying those query parameters (any values; the one naming most wins); a binary answer (a recording's
 * audio) is served from `bodyBase64`. A recording marked `serve: false` is never served (it answers a later state of the
 * same request); a test reads it with body(). Telenow's recorded events (contract/telenow-mock/events) are read with
 * event(), signed as Telenow signs them.
 */
final class RecordedTelenow
{
    /** @var list<array>|null read once: some are large (a voice provider's whole list), and they never change */
    private static ?array $cassettes = null;

    /** @return list<array> */
    public static function cassettes(): array
    {
        return self::$cassettes ??= array_map(
            fn (string $file) => json_decode((string) file_get_contents($file), true),
            glob(base_path('../contract/telenow-mock/cassettes/*.json')) ?: [],
        );
    }

    /** One recording's answer body, by its file name (without `.json`). */
    public static function body(string $name): mixed
    {
        return json_decode((string) file_get_contents(base_path("../contract/telenow-mock/cassettes/{$name}.json")), true)['response']['body'];
    }

    /** One recording's answer as a fake answers it (its status and body), by its file name (without `.json`). */
    public static function response(string $name): \GuzzleHttp\Promise\PromiseInterface
    {
        $response = json_decode((string) file_get_contents(base_path("../contract/telenow-mock/cassettes/{$name}.json")), true)['response'];

        return Http::response($response['body'], $response['status']);
    }

    /** The recording of `$operationId` made with `$apiKey`. */
    public static function answer(string $operationId, string $apiKey): array
    {
        foreach (self::cassettes() as $c) {
            if ($c['operationId'] === $operationId && $c['request']['apiKey'] === $apiKey) {
                return $c['response']['body'];
            }
        }
        throw new \LogicException("No recording of {$operationId} with {$apiKey}");
    }

    /**
     * A recorded event (contract/telenow-mock/events/<name>.json) as Telenow delivers it: the raw body and its headers,
     * signed with the recording's placeholder secret (or `$secret`).
     *
     * @return array{body: string, headers: array<string, string>, secret: string, payload: array}
     */
    public static function event(string $name, ?string $secret = null): array
    {
        $e = json_decode((string) file_get_contents(base_path("../contract/telenow-mock/events/{$name}.json")), true);
        $raw = json_encode($e['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return ['body' => $raw, 'secret' => $e['secret'], 'payload' => $e['body'], 'headers' => [
            'X-VoiceAI-Signature' => 'sha256='.hash_hmac('sha256', $raw, $secret ?? $e['secret']),
            'X-VoiceAI-Event' => $e['event'],
            'X-VoiceAI-Delivery' => $e['delivery'],
            'Content-Type' => 'application/json',
        ]];
    }

    public static function fake(): void
    {
        Http::preventStrayRequests();
        Http::fake(self::responder());
    }

    /** The recordings as a fake's answer function, for a test that answers some requests itself and passes the rest on. */
    public static function responder(): \Closure
    {
        $cassettes = self::cassettes();

        return function (Request $request) use ($cassettes) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $key = $request->header('X-API-Key')[0] ?? null;
            $idem = $request->header('Idempotency-Key')[0] ?? null;
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $mine = array_filter($cassettes, fn (array $c) => ($c['serve'] ?? true) !== false && $c['request']['method'] === $request->method()
                && $c['request']['path'] === $path && $c['request']['apiKey'] === $key
                && (! isset($c['request']['idempotencyKey']) || $c['request']['idempotencyKey'] === $idem)
                && array_diff($c['request']['queryNames'] ?? [], array_keys($query)) === []);
            // A recording that names this Idempotency-Key, or more of the query, is more particular.
            usort($mine, fn (array $a, array $b) => [isset($b['request']['idempotencyKey']), count($b['request']['queryNames'] ?? [])]
                <=> [isset($a['request']['idempotencyKey']), count($a['request']['queryNames'] ?? [])]);
            if ($mine) {
                $r = $mine[0]['response'];

                return isset($r['bodyBase64'])
                    ? Http::response(base64_decode($r['bodyBase64']), $r['status'], ['Content-Type' => $r['contentType']])
                    : Http::response($r['body'], $r['status']);
            }

            return Http::response(['success' => false, 'error' => "no recording for {$request->method()} {$path}"], 404);
        };
    }
}
