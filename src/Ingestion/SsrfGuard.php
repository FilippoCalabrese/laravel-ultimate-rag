<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Ingestion;

use Sellinnate\RagEngine\Exceptions\RagException;

/**
 * SSRF protection for URL ingestion (FR-IN-03, NFR-SE). Rejects non-http(s)
 * schemes and any host that resolves to a private, loopback, link-local or
 * reserved address — blocking access to cloud metadata (169.254.169.254),
 * localhost and internal services.
 */
final class SsrfGuard
{
    public function assertSafe(string $url): void
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

        foreach ($this->resolve($host) as $ip) {
            if (! $this->isPublic($ip)) {
                throw new RagException("URL host resolves to a non-public address [{$ip}] (SSRF blocked).");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = gethostbynamel($host);

        if ($ips === false || $ips === []) {
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
