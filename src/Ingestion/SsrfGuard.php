<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Ingestion;

use Sellinnate\RagEngine\Exceptions\RagException;

/**
 * SSRF protection for URL ingestion (FR-IN-03, NFR-SE). Rejects non-http(s)
 * schemes and any host that resolves (A or AAAA) to a private, loopback,
 * link-local or reserved address — blocking access to cloud metadata
 * (169.254.169.254), localhost and internal services.
 *
 * {@see assertSafe()} returns the validated IPs so the caller can PIN the
 * connection to a checked address, defeating DNS-rebinding (TOCTOU between this
 * check and the HTTP client's own resolution).
 */
final class SsrfGuard
{
    /**
     * @return list<string> The validated IP addresses for the host.
     */
    public function assertSafe(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false) {
            throw new RagException('URL is malformed.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RagException("URL scheme [{$scheme}] is not allowed; only http/https.");
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            throw new RagException('URL has no host.');
        }

        $host = trim($host, '[]'); // unwrap IPv6 literals

        $ips = $this->resolve($host);

        foreach ($ips as $ip) {
            if (! $this->isPublic($ip)) {
                throw new RagException("URL host resolves to a non-public address [{$ip}] (SSRF blocked).");
            }
        }

        return $ips;
    }

    /**
     * Resolve a host to ALL its A and AAAA addresses (a dual-stack host with a
     * private AAAA must not slip through an IPv4-only check).
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $v4 = @gethostbynamel($host);
        if ($v4 !== false) {
            $ips = array_merge($ips, $v4);
        }

        $v6 = @dns_get_record($host, DNS_AAAA);
        if ($v6 !== false) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        $ips = array_values(array_unique($ips));

        if ($ips === []) {
            throw new RagException("Could not resolve host [{$host}] for SSRF validation.");
        }

        return $ips;
    }

    private function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
