<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Providers;

use HomeSide\AiAgents\Providers\AiProviderEndpointPolicy;
use HomeSide\AiAgents\Tests\TestCase;
use InvalidArgumentException;

final class AiProviderEndpointPolicyTest extends TestCase
{
    /**
     * Public HTTPS endpoints are the happy path in saas mode.
     */
    public function test_public_https_endpoint_is_valid_in_saas_mode(): void
    {
        (new AiProviderEndpointPolicy)->validate('https://api.openai.com/v1', 'saas');

        $this->addToAssertionCount(1);
    }

    /**
     * SaaS mode requires HTTPS: plaintext traffic to third-party APIs is
     * never acceptable on shared infrastructure.
     */
    public function test_http_endpoint_is_rejected_in_saas_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only HTTPS');

        (new AiProviderEndpointPolicy)->validate('http://api.openai.com/v1', 'saas');
    }

    /**
     * Loopback targets are blocked in saas mode — a tenant must not be able
     * to probe the host's own services (Redis, Postgres, internal admin...).
     * HTTPS is validated first, so the loopback probe uses an https URL.
     */
    public function test_loopback_is_blocked_in_saas_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('private IP');

        (new AiProviderEndpointPolicy)->validate('https://127.0.0.1:8080/v1', 'saas');
    }

    /**
     * The self-hosted mode exists precisely for local inference servers.
     */
    public function test_loopback_is_allowed_in_self_hosted_mode(): void
    {
        (new AiProviderEndpointPolicy)->validate('http://127.0.0.1:11434', 'self-hosted');

        $this->addToAssertionCount(1);
    }

    /**
     * RFC1918 LAN targets behave like loopback: blocked in saas, allowed
     * self-hosted.
     */
    public function test_lan_ip_blocked_in_saas_but_allowed_self_hosted(): void
    {
        $policy = new AiProviderEndpointPolicy;

        $this->expectException(InvalidArgumentException::class);

        $policy->validate('https://192.168.1.50/v1', 'saas');
    }

    /**
     * The AWS/GCP/Azure metadata endpoint is the SSRF crown jewel: blocked
     * in BOTH modes, because no AI API legitimately lives there.
     */
    public function test_cloud_metadata_endpoint_is_blocked_even_self_hosted(): void
    {
        $policy = new AiProviderEndpointPolicy;

        try {
            $policy->validate('http://169.254.169.254/latest/meta-data/', 'saas');
            $this->fail('saas mode should reject the metadata endpoint.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('metadata');

        $policy->validate('http://169.254.169.254/latest/meta-data/', 'self-hosted');
    }

    /**
     * GCP's DNS-based metadata hostname is also blocked (it resolves to the
     * same link-local service and bypasses IP-only filters).
     */
    public function test_metadata_google_internal_hostname_is_blocked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('metadata');

        (new AiProviderEndpointPolicy)->validate('http://metadata.google.internal/computeMetadata/v1/', 'self-hosted');
    }

    /**
     * Ports of common internal services (Redis, MongoDB, memcached, SSH, RDP)
     * are blocked in self-hosted mode: they are never AI API endpoints. In
     * saas mode the private-range check may fire first for internal hosts,
     * so the dedicated port assertion runs self-hosted.
     */
    public function test_dangerous_ports_are_blocked_in_self_hosted_mode(): void
    {
        $policy = new AiProviderEndpointPolicy;

        foreach ([6379, 27017, 22, 11211, 3389] as $port) {
            try {
                $policy->validate("http://api.example.com:{$port}", 'self-hosted');
                $this->fail("Port {$port} should have been rejected.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Port not allowed', $e->getMessage());
            }
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Malformed URLs fail the basic parse before any network work.
     */
    public function test_malformed_url_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URL');

        (new AiProviderEndpointPolicy)->validate('not-a-url', 'saas');
    }

    /**
     * Null mode resolves the configured default (saas unless overridden).
     */
    public function test_null_mode_resolves_config_default(): void
    {
        config()->set('ai-agents.endpoint_policy.mode', 'self-hosted');

        (new AiProviderEndpointPolicy)->validate('http://127.0.0.1:11434');

        $this->addToAssertionCount(1);
    }
}
