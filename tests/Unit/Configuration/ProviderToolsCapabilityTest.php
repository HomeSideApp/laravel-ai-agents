<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Configuration;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Configuration\ModelCapabilities;
use HomeSide\AiAgents\Enums\AiDriver;
use HomeSide\AiAgents\Providers\DynamicProviderRegistrar;
use HomeSide\AiAgents\Tests\TestCase;

/**
 * Provider-tool capabilities and the expanded driver set.
 */
final class ProviderToolsCapabilityTest extends TestCase
{
    /**
     * A driver baseline advertises the provider tools it supports and
     * nothing it does not.
     */
    public function test_driver_baseline_reflects_supported_provider_tools(): void
    {
        $anthropic = ModelCapabilities::fromProviderConfiguration(null, AiDriver::Anthropic);

        $this->assertTrue($anthropic->supportsProviderTool(Capability::WebSearch));
        $this->assertTrue($anthropic->supportsProviderTool(Capability::ToolSearch));
        $this->assertFalse($anthropic->supportsProviderTool(Capability::FileSearch));

        $groq = ModelCapabilities::fromProviderConfiguration(null, AiDriver::Groq);

        $this->assertTrue($groq->supportsProviderTool(Capability::WebSearch));
        $this->assertTrue($groq->supportsProviderTool(Capability::CodeExecution));
        $this->assertFalse($groq->supportsProviderTool(Capability::WebFetch));
    }

    /**
     * An explicit provider_tools list in the provider configuration wins
     * over the driver baseline.
     */
    public function test_declared_provider_tools_override_baseline(): void
    {
        $capabilities = ModelCapabilities::fromProviderConfiguration(
            ['model_capabilities' => ['provider_tools' => ['web_search']]],
            AiDriver::Anthropic,
        );

        $this->assertTrue($capabilities->supportsProviderTool(Capability::WebSearch));
        $this->assertFalse($capabilities->supportsProviderTool(Capability::ToolSearch));
    }

    /**
     * The provider-tool map resolves SDK provider-tool class basenames.
     */
    public function test_provider_tool_map_covers_sdk_tools(): void
    {
        $map = Capability::providerToolMap();

        $this->assertSame(Capability::WebSearch, $map['WebSearch']);
        $this->assertSame(Capability::CodeExecution, $map['CodeExecution']);
        $this->assertSame(Capability::ToolSearch, $map['ToolSearch']);
    }

    /**
     * Every SDK driver is a first-class AiDriver and registers dynamically.
     */
    public function test_new_drivers_register_dynamically(): void
    {
        $supported = DynamicProviderRegistrar::supportedDrivers();

        foreach (['cohere', 'typesafe', 'azure', 'bedrock', 'eleven', 'jina', 'voyageai'] as $driver) {
            $this->assertArrayHasKey($driver, $supported, "Driver [{$driver}] must be supported.");
            $this->assertSame($driver, AiDriver::fromColumn($driver)->toSdkDriver());
        }
    }

    /**
     * Cohere is cloud; Ollama stays local; unknown types degrade to
     * openai-compatible.
     */
    public function test_driver_privacy_defaults_and_fallback(): void
    {
        $this->assertSame('cloud', AiDriver::Cohere->defaultPrivacyLevel()->value);
        $this->assertSame('local', AiDriver::Ollama->defaultPrivacyLevel()->value);
        $this->assertSame(AiDriver::OpenAICompatible, AiDriver::fromColumn('totally-unknown'));
    }
}
