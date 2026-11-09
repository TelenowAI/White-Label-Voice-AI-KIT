<?php

namespace App\Kit\Routing;

use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\PhoneNumber;
use App\Models\RoutingEndpoint;
use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * An account's routing endpoint at Telenow: the address on this kit Telenow asks, on every call to one of the account's routed numbers, who takes
 * it (setInboundRouting, with the account's own key). The address carries the account's token in its path — Telenow signs the address without its
 * query — and the secret Telenow signs with is kept encrypted, as Telenow shows it only on the answer that made it.
 *
 * - ensure(): registered, and again when this kit's address changed or the secret is not held (a new one is asked for). Telenow refuses an address
 *   that is not public https (recorded), so a kit not reachable from the internet cannot route calls: said in the kit's words.
 * - verify(): a request is Telenow's for this account when its timed signature (`X-Telenow-Signature-V2: t=<unix>,v2=<hex>`, HMAC-SHA256 of
 *   "v2\n" + t + "\n" + the address + "\n" + the body, pool_routing.rs) holds under the secret, and t is within
 *   config('kit.routing.signature_tolerance_seconds') of this server's clock. The older untimed signature is not taken: a copy would answer for ever.
 * - status(): whether Telenow asks this kit, when it last had an answer, and its last problem — in the kit's words (problem()), never Telenow's text,
 *   which can hold a page another server answered with.
 * - check(): Telenow's own test, a pretend call to a routed number through this endpoint: whether it answered, how long it took, the action.
 *
 * Not here: which rule takes a call (RoutingMatch), the rules (RoutingRules), marking numbers routed (App\Kit\Numbers\Numbers::route).
 */
final class RoutingConnection
{
    private const PATH = '/api/kit/telenow/routing';

    public function __construct(private readonly TelenowClient $telenow) {}

    /** The address Telenow asks for this account, as Telenow writes it when it signs: scheme and host in lower case, no default port. */
    public function url(RoutingEndpoint $endpoint): string
    {
        $base = config('kit.routing.url') ?: rtrim((string) config('app.url'), '/').self::PATH;

        return self::signedUrl(rtrim((string) $base, '/').'/'.$endpoint->token);
    }

    public function ensure(Workspace $workspace): RoutingEndpoint
    {
        [$key, $org] = $this->ready($workspace);
        $endpoint = RoutingEndpoint::query()->find($workspace->id);
        if ($endpoint === null) {
            $endpoint = new RoutingEndpoint;
            $endpoint->forceFill(['workspace_id' => $workspace->id, 'token' => Str::lower(Str::random(40))])->save();
        }
        $url = $this->url($endpoint);
        if ($endpoint->secret !== null && $endpoint->url === $url && $endpoint->registered_at !== null) {
            return $endpoint;
        }
        try {
            $answer = $this->telenow->setInboundRouting($key, $org, $url, (int) config('kit.routing.timeout_ms'), $endpoint->secret === null);
        } catch (TelenowError $e) {
            throw new RoutingRefused($e->status === 400 ? 409 : 502, __($e->status === 400 ? 'routing.address_not_public' : 'routing.platform_unreachable'));
        }
        $secret = $answer['signingSecret'] ?? null;
        if ($endpoint->secret === null && (! is_string($secret) || $secret === '')) {
            throw new RoutingRefused(502, __('routing.platform_unreachable'));
        }
        $endpoint->forceFill(['secret' => is_string($secret) && $secret !== '' ? $secret : $endpoint->secret, 'url' => $url, 'registered_at' => now()])->save();

        return $endpoint;
    }

    /** The account a request from Telenow is for, when its signature holds; null otherwise. */
    public function verify(string $token, ?string $signature, string $body): ?RoutingEndpoint
    {
        if ($signature === null || preg_match('/^t=(\d{1,12}),v2=([0-9a-f]{64})$/', trim($signature), $m) !== 1) {
            return null;
        }
        if (abs(now()->getTimestamp() - (int) $m[1]) > (int) config('kit.routing.signature_tolerance_seconds')) {
            return null;
        }
        $endpoint = RoutingEndpoint::query()->where('token', $token)->first();
        if ($endpoint === null || $endpoint->secret === null || $endpoint->url === null) {
            return null;
        }
        $expected = hash_hmac('sha256', "v2\n{$m[1]}\n{$endpoint->url}\n".$body, $endpoint->secret);

        return hash_equals($expected, $m[2]) ? $endpoint : null;
    }

    /**
     * Whether Telenow asks this kit for the account: null when it was never set up; else `state` connected | off | elsewhere | not_connected |
     * unknown (Telenow not reached), when an answer was last taken and the last problem (problem()) with when.
     *
     * @return array{state: string, lastAnsweredAt: ?string, problem: ?string, problemAt: ?string}|null
     */
    public function status(Workspace $workspace): ?array
    {
        $endpoint = RoutingEndpoint::query()->find($workspace->id);
        if ($endpoint === null || $endpoint->registered_at === null) {
            return null;
        }
        $none = ['lastAnsweredAt' => null, 'problem' => null, 'problemAt' => null];
        try {
            [$key, $org] = $this->ready($workspace);
            $at = $this->telenow->inboundRouting($key, $org);
        } catch (TelenowError|RoutingRefused) {
            return ['state' => 'unknown'] + $none;
        }
        if ($at === null) {
            return ['state' => 'not_connected'] + $none;
        }
        $state = match (true) {
            ($at['url'] ?? null) !== $endpoint->url => 'elsewhere',
            ($at['enabled'] ?? false) !== true => 'off',
            default => 'connected',
        };

        return ['state' => $state, 'lastAnsweredAt' => $at['lastOkAt'] ?? null, 'problem' => self::problem($at['lastError'] ?? null), 'problemAt' => $at['lastErrorAt'] ?? null];
    }

    /**
     * Telenow's test of a routed number: `answered`, `elapsedMs`, `action` answer | refuse (null with no answer) and `problem` (problem()).
     *
     * @return array{answered: bool, elapsedMs: int, action: ?string, problem: ?string}
     */
    public function check(Workspace $workspace, PhoneNumber $number): array
    {
        [$key, $org] = $this->ready($workspace);
        try {
            $test = $this->telenow->testInboundRouting($key, $org, '+'.preg_replace('/\D/', '', (string) $number->e164));
        } catch (TelenowError $e) {
            throw new RoutingRefused($e->status === 400 ? 409 : 502, __($e->status === 400 ? 'routing.number_not_routed' : 'routing.platform_unreachable'));
        }
        $action = $test['decision']['action'] ?? null;

        return [
            'answered' => ($test['answered'] ?? false) === true,
            'elapsedMs' => (int) ($test['elapsedMs'] ?? 0),
            'action' => match ($action) { 'route' => 'answer', 'reject' => 'refuse', default => null },
            'problem' => self::problem($test['reason'] ?? null),
        ];
    }

    /**
     * Telenow's words for why there was no answer (pool_routing.rs and its test, recorded) as the kit's: off | not_connected | slow (past the time
     * allowed) | refused (this kit answered with an error) | unreachable | bad_answer (Telenow would not take it) | address | other.
     */
    public static function problem(?string $said): ?string
    {
        if ($said === null || trim($said) === '') {
            return null;
        }

        return match (true) {
            str_starts_with($said, 'inbound routing is disabled') => 'off',
            str_starts_with($said, 'no inbound routing endpoint') => 'not_connected',
            str_starts_with($said, 'timed out') => 'slow',
            preg_match('/^[1-5]\d\d\b/', $said) === 1 => 'refused',
            str_starts_with($said, 'request failed') => 'unreachable',
            str_starts_with($said, 'answer refused') => 'bad_answer',
            str_starts_with($said, 'url rejected') => 'address',
            default => 'other',
        };
    }

    /** @return array{0: string, 1: string} the account's key and its id at Telenow */
    private function ready(Workspace $workspace): array
    {
        if (! is_string($workspace->telenow_key) || $workspace->telenow_key === '' || $workspace->telenow_sub_account_id === null) {
            throw new RoutingRefused(409, __('routing.account_not_ready'));
        }

        return [$workspace->telenow_key, $workspace->telenow_sub_account_id];
    }

    /** An address as Telenow signs it (pool_routing.rs `signed_url`, its own test's cases): scheme and host lower case, no default port, no query. */
    public static function signedUrl(string $url): string
    {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? 'https');
        $port = isset($p['port']) && ! ($scheme === 'https' && $p['port'] === 443) && ! ($scheme === 'http' && $p['port'] === 80) ? ':'.$p['port'] : '';

        return $scheme.'://'.strtolower($p['host'] ?? '').$port.($p['path'] ?? '/');
    }
}
