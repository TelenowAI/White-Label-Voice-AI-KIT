<?php

namespace App\Kit\Net;

/**
 * The addresses a host name stands for right now (A and AAAA), so PublicLinks can check each before it connects and connect to the one it checked.
 * Its own class so tests can stand in for the network.
 */
class HostResolver
{
    /** @return list<string> every address the name resolves to; an address given as the host is its own */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = [];
        foreach ($records as $r) {
            $ip = $r['ip'] ?? $r['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}
