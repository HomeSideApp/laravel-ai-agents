<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Configuration;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Configuration\CapabilityResolver;
use HomeSide\AiAgents\Tests\TestCase;
use RuntimeException;

final class CapabilityResolverTest extends TestCase
{
    /**
     * With no detection or override, the driver baseline is the answer.
     */
    public function test_resolve_falls_back_to_driver_baseline(): void
    {
        $capabilities = (new CapabilityResolver)->resolve('openai');

        $this->assertContains(Capability::Text, $capabilities);
        $this->assertContains(Capability::StructuredOutput, $capabilities);
        $this->assertContains(Capability::Tools, $capabilities);
        $this->assertContains(Capability::Streaming, $capabilities);
    }

    /**
     * A probe result beats the baseline: openai-compatible endpoints
     * advertise what they actually support once probed.
     */
    public function test_detected_capabilities_override_baseline(): void
    {
        $capabilities = (new CapabilityResolver)->resolve('openai-compatible', detected: ['text', 'structured_output']);

        $this->assertContains(Capability::StructuredOutput, $capabilities);
        $this->assertNotContains(Capability::Tools, $capabilities);
    }

    /**
     * The admin override wins over everything — it exists to correct wrong
     * detections.
     */
    public function test_override_wins_over_detected_and_baseline(): void
    {
        $capabilities = (new CapabilityResolver)->resolve(
            'openai',
            detected: ['text'],
            override: ['text', 'vision'],
        );

        $this->assertSame([Capability::Text, Capability::Vision], $capabilities);
    }

    /**
     * missing() reports exactly the required capabilities the model lacks.
     */
    public function test_missing_reports_uncovered_requirements(): void
    {
        $resolver = new CapabilityResolver;
        $effective = [Capability::Text];
        $required = [Capability::Text, Capability::Vision, Capability::Tools];

        $this->assertSame([Capability::Vision, Capability::Tools], $resolver->missing($effective, $required));
    }

    /**
     * isCompatible() is the boolean form of missing() being empty.
     */
    public function test_is_compatible_when_requirements_covered(): void
    {
        $resolver = new CapabilityResolver;

        $this->assertTrue($resolver->isCompatible([Capability::Text, Capability::Tools], [Capability::Text]));
        $this->assertFalse($resolver->isCompatible([Capability::Text], [Capability::Tools]));
    }

    /**
     * ensureCompatible() throws listing every missing capability, naming the
     * agent and model, so the admin sees exactly what is wrong.
     */
    public function test_ensure_compatible_throws_listing_missing_capabilities(): void
    {
        $resolver = new CapabilityResolver;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('vision, tools');

        $resolver->ensureCompatible(
            [Capability::Text],
            [Capability::Vision, Capability::Tools],
            agentKey: 'economy.ticket_analyzer',
            model: 'gpt-3.5-turbo',
        );
    }

    /**
     * No exception when the model covers the requirements.
     */
    public function test_ensure_compatible_passes_for_compatible_model(): void
    {
        (new CapabilityResolver)->ensureCompatible(
            [Capability::Text, Capability::Tools, Capability::Vision],
            [Capability::Tools],
            agentKey: 'economy.ticket_analyzer',
            model: 'gpt-4o',
        );

        $this->addToAssertionCount(1); // no exception thrown
    }
}
