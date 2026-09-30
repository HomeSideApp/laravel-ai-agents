<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Configuration;

use HomeSide\AiAgents\AgentRegistry;
use HomeSide\AiAgents\Configuration\AgentConfigurationResolver;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Models\AiAgent;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\Unit\Fixtures\DummyAgent;
use InvalidArgumentException;

/**
 * Self-healing resolution: a missing ai_agents row is created on demand
 * instead of failing the run, keeping the registry→database sync
 * transparent for hosts and users.
 */
final class AgentConfigurationResolverSelfHealTest extends TestCase
{
    public function test_resolve_creates_missing_row_on_demand(): void
    {
        // Register the class but do NOT run the synchroniser: the row is
        // missing on purpose.
        $registry = $this->app->make(AgentRegistry::class);
        $registry->register('recipes.generator', DummyAgent::class);
        $this->assertNull(AiAgent::findByKey('recipes.generator'));

        $resolved = $this->app->make(AgentConfigurationResolver::class)
            ->resolve('recipes.generator', new AiExecutionContextData(userId: 1));

        $this->assertTrue($resolved->enabled);
        $this->assertSame('recipes.generator', $resolved->agent);
        $this->assertNotNull(AiAgent::findByKey('recipes.generator'));
    }

    public function test_get_platform_prompt_creates_missing_row_on_demand(): void
    {
        $registry = $this->app->make(AgentRegistry::class);
        $registry->register('recipes.generator', DummyAgent::class);

        $prompt = $this->app->make(AgentConfigurationResolver::class)
            ->getPlatformPrompt('recipes.generator');

        $this->assertNotNull($prompt);
        $this->assertNotNull(AiAgent::findByKey('recipes.generator'));
    }

    public function test_unregistered_agent_still_fails_as_configuration_error(): void
    {
        // Never registered: not a sync gap, a host configuration error.
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(AgentConfigurationResolver::class)
            ->resolve('unknown.agent', new AiExecutionContextData(userId: 1));
    }
}
