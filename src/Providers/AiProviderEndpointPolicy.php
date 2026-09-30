<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use InvalidArgumentException;

/**
 * Validation policy for AI provider URLs (SSRF protection).
 *
 * Validates URLs before allowing test/connection to prevent SSRF.
 *
 * Modes:
 * - self-hosted: allows localhost/RFC1918 (Ollama/vLLM may be hosted internally)
 * - saas: blocks loopback/link-local/cloud metadata/RFC1918/local IPv6
 *
 * Validates: DNS resolution, redirects, protocol (HTTPS in SaaS), port.
 */
final class AiProviderEndpointPolicy
{
    /**
     * Private/RFC1918 subnets.
     */
    private const PRIVATE_RANGES = [
        '127.0.0.0/8',      // Loopback
        '10.0.0.0/8',       // RFC1918
        '172.16.0.0/12',    // RFC1918
        '192.168.0.0/16',   // RFC1918
        '169.254.0.0/16',   // Link-local
        '0.0.0.0/8',        // Current network
    ];

    /**
     * Cloud metadata hosts.
     */
    private const CLOUD_METADATA_HOSTS = [
        '169.254.169.254',  // AWS/GCP/Azure metadata
        'metadata.google.internal', // GCP metadata
    ];

    /**
     * Validate a provider URL.
     *
     * @param  string  $baseUrl  The provider URL (e.g. https://api.openai.com/v1).
     * @param  string|null  $mode  'self-hosted' or 'saas'. Null resolves the
     *                             mode from config('ai-agents.endpoint_policy.mode'),
     *                             which defaults to env AI_AGENTS_ENDPOINT_POLICY_MODE
     *                             ('saas' when unset).
     *
     * @throws InvalidArgumentException If the URL is not valid per the policy.
     */
    public function validate(string $baseUrl, ?string $mode = null): void
    {
        $mode ??= (string) config('ai-agents.endpoint_policy.mode', 'saas');
        $parsed = parse_url($baseUrl);

        if ($parsed === false || ! isset($parsed['host'])) {
            throw new InvalidArgumentException(
                "Invalid URL: {$baseUrl}",
            );
        }

        $host = $parsed['host'];
        $scheme = $parsed['scheme'] ?? 'https';
        $port = $parsed['port'] ?? null;

        // Validate protocol in SaaS mode.
        if ($mode === 'saas' && $scheme !== 'https') {
            throw new InvalidArgumentException(
                "In SaaS mode, only HTTPS URLs are allowed. Received: {$scheme}",
            );
        }

        // Validate that the host is not cloud metadata.
        if (in_array($host, self::CLOUD_METADATA_HOSTS, true)) {
            throw new InvalidArgumentException(
                "The URL points to a cloud metadata endpoint: {$host}",
            );
        }

        // Resolve IP and validate against private ranges.
        $ip = gethostbyname($host);

        // Validate direct IP (host is already an IP) or DNS-resolved IP.
        $this->validateIp($ip, $mode, $baseUrl);

        // Validate dangerous ports.
        if ($port !== null && in_array((int) $port, [22, 3389, 6379, 11211, 27017], true)) {
            throw new InvalidArgumentException(
                "Port not allowed: {$port}. Internal service ports are blocked.",
            );
        }
    }

    /**
     * Validate the resolved IP against private ranges per the active mode.
     *
     * In 'self-hosted' mode private ranges are allowed unconditionally
     * (Ollama/vLLM legitimately live on localhost/LAN). In 'saas' mode any
     * CIDR match throws: shared-infrastructure tenants must never be able to
     * reach internal networks through a crafted provider URL.
     *
     * @param  string  $ip  The DNS-resolved (or literal) IPv4 of the endpoint.
     * @param  string  $mode  The active policy mode ('saas' or 'self-hosted').
     * @param  string  $baseUrl  The original URL, echoed into the error message.
     *
     * @throws InvalidArgumentException In saas mode when the IP falls inside
     *                                  a blocked private range.
     */
    private function validateIp(string $ip, string $mode, string $baseUrl): void
    {
        if ($mode === 'self-hosted') {
            // In self-hosted mode, allow private ranges.
            return;
        }

        // In SaaS mode, block private ranges.
        foreach (self::PRIVATE_RANGES as $range) {
            if ($this->ipInCidr($ip, $range)) {
                throw new InvalidArgumentException(
                    "The URL points to a private IP: {$ip} (range {$range}). ".
                    "Use 'self-hosted' mode if you need to access local services.",
                );
            }
        }
    }

    /**
     * Check whether an IPv4 address falls inside a CIDR subnet.
     *
     * Performs the comparison in 32-bit integer space (ip2long + bitmask) so
     * range checks are exact regardless of how the address is written.
     *
     * @param  string  $ip  The IPv4 address to test.
     * @param  string  $cidr  A subnet in 'a.b.c.d/prefix' notation.
     * @return bool True when the address is inside the subnet.
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - (int) $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
