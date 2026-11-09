<?php

namespace App\Kit\Net;

use App\Kit\Agents\AgentRequest;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Reading a customer's link from this server (contacts from a link: App\Kit\Campaigns\CampaignSources). This server can reach what customers must not —
 * its own network, the cloud's metadata address — so a link is read only when every step of it is public:
 *
 * - https only, no login in it, on an allowed port (config('kit.links.ports'));
 * - its host resolved here (HostResolver), every address it stands for public — not private, loopback, link-local, shared, reserved or documentation
 *   space, and an IPv4 address carried inside IPv6 judged as IPv4 — and the connection pinned to the address that was checked, so the name cannot
 *   point somewhere else between the check and the connection; when a checked address cannot be reached the next one is tried, pinned the same
 *   way, at most config('kit.links.addresses_tried') of them (as any client falls back between a name's addresses: found on the real network);
 * - redirects followed by hand, each a new link checked the same way, at most config('kit.links.max_redirects');
 * - at most the bytes the caller allows, the transfer stopped as soon as it passes them (curl's own progress check: Guzzle's stream handler, which
 *   reads a body piece by piece, cannot pin the address, so it is never used), within config('kit.links.timeout_seconds'); a web page (HTML) is
 *   not a file.
 *
 * Not here: what a file says (the caller parses it).
 */
final class PublicLinks
{
    /** Address ranges that are not on the public internet though PHP's private/reserved flags let them through (CIDR). */
    private const NOT_PUBLIC = ['100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '2001:db8::/32'];

    public function __construct(private readonly HostResolver $resolver, private readonly AgentRequest $requests) {}

    /** @return string the file at the link, at most the bytes allowed */
    public function read(string $url, int $maxBytes): string
    {
        $c = config('kit.links');
        for ($hop = 0; ; $hop++) {
            if ($hop > $c['max_redirects']) {
                throw new LinkRefused('too_many_redirects');
            }
            [$host, $port, $ips] = $this->checked($url);
            $response = null;
            foreach (array_slice($ips, 0, $c['addresses_tried']) as $ip) {
                $tooBig = false;
                try {
                    $response = Http::withOptions([
                        'allow_redirects' => false,
                        'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".(str_contains($ip, ':') ? "[{$ip}]" : $ip)]],
                        'progress' => function (int $total, int $done) use (&$tooBig, $maxBytes): bool {
                            return $tooBig = $total > $maxBytes || $done > $maxBytes;
                        },
                    ])->connectTimeout($c['connect_seconds'])->timeout($c['timeout_seconds'])
                        ->withHeaders(['Accept' => 'text/csv, text/plain;q=0.9, */*;q=0.1', 'User-Agent' => 'voice-kit/'.config('kit.version')])
                        ->get($url);
                    break;
                } catch (ConnectionException|RequestException|TransferException) {
                    if ($tooBig) {
                        throw new LinkRefused('too_big');
                    }
                }
            }
            if ($response === null) {
                throw new LinkRefused('unreachable');
            }
            if ($response->redirect()) {
                $next = $response->header('Location');
                if ($next === '') {
                    throw new LinkRefused('unreachable');
                }
                $url = self::absolute($url, $next);

                continue;
            }
            if (! $response->successful()) {
                throw new LinkRefused('status', ['status' => $response->status()]);
            }
            if (str_contains(strtolower($response->header('Content-Type')), 'html')) {
                throw new LinkRefused('web_page');
            }

            $body = $response->body();
            if (strlen($body) > $maxBytes) {
                throw new LinkRefused('too_big');
            }

            return $body;
        }
    }

    /** @return array{0: string, 1: int, 2: list<string>} the host, port and the checked addresses to connect to, in the order resolved */
    private function checked(string $url): array
    {
        $problem = $this->requests->addressProblem($url);
        if ($problem !== null) {
            throw new LinkRefused($problem === 'address_https' ? 'https' : ($problem === 'address_private' ? 'private' : 'shape'));
        }
        $parts = parse_url($url);
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? 443);
        if (! in_array($port, config('kit.links.ports'), true)) {
            throw new LinkRefused('port');
        }
        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            throw new LinkRefused('unresolved');
        }
        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw new LinkRefused('private');
            }
        }

        return [$host, $port, array_values($ips)];
    }

    public static function isPublic(string $ip): bool
    {
        $inner = self::embeddedIpv4($ip);
        if ($inner !== null) {
            return self::isPublic($inner);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        foreach (self::NOT_PUBLIC as $cidr) {
            if (self::within($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /** An IPv4 address carried in IPv6 (mapped ::ffff:a.b.c.d, NAT64 64:ff9b::/96), else null. */
    private static function embeddedIpv4(string $ip): ?string
    {
        if (! str_contains($ip, ':') || ($bin = @inet_pton($ip)) === false || strlen($bin) !== 16) {
            return null;
        }
        $mapped = str_repeat("\0", 10)."\xff\xff";
        $nat64 = "\x00\x64\xff\x9b".str_repeat("\0", 8);
        foreach ([$mapped, $nat64] as $prefix) {
            if (str_starts_with($bin, $prefix)) {
                return inet_ntop(substr($bin, 12));
            }
        }

        return null;
    }

    private static function within(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bytes = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }

        return $rest === 0 || ((ord($a[$bytes]) ^ ord($b[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0;
    }

    private static function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }
        $p = parse_url($base);
        $origin = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        if (str_starts_with($location, '//')) {
            return $p['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');

        return $origin.$dir.$location;
    }
}
