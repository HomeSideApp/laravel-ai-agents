<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit;

use HomeSide\AiAgents\AgentRegistry;
use HomeSide\AiAgents\Contracts\ModuleAiProvider;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\Unit\Fixtures\DummyAgent;
use HomeSide\AiAgents\Tests\Unit\Fixtures\DummyModule;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;

final class AgentRegistryTest extends TestCase
{
    /**
     * A freshly registered key resolves to an instance of its class.
     */
    public function test_registered_agent_resolves_to_instance(): void
    {
        $registry = $this->app->make(AgentRegistry::class);
        $registry->register('recipes.generator', DummyAgent::class);

        $agent = $registry->get('recipes.generator');

        $this->assertInstanceOf(DummyAgent::class, $agent);
        $this->assertSame('recipes.generator', $agent->key());
    }

    /**
     * Every get() call must build a fresh instance so no execution state
     * leaks between concurrent runs sharing the same registry singleton.
     */
    public function test_get_returns_new_instance_per_call(): void
    {
        $registry = $this->app->make(AgentRegistry::class);
        $registry->register('recipes.generator', DummyAgent::class);

        $this->assertNotSame($registry->get('recipes.generator'), $registry->get('recipes.generator'));
    }

    /**
     * An unknown key is a programming error and must fail loudly.
     */
    public function test_unknown_key_throws_invalid_argument(): void
    {
        $registry = $this->app->make(AgentRegistry::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent not registered');

        $registry->get('missing.agent');
    }

    /**
     * has() is the safe membership probe used before opening conversations.
     */
    public function test_has_reflects_registration_state(): void
    {
        $registry = $this->app->make(AgentRegistry::class);

        $this->assertFalse($registry->has('recipes.generator'));

        $registry->register('recipes.generator', DummyAgent::class);

        $this->assertTrue($registry->has('recipes.generator'));
    }

    /**
     * Module listing is a prefix scan over module-namespaced keys.
     */
    public function test_for_module_filters_keys_by_module_prefix(): void
    {
        $registry = $this->app->make(AgentRegistry::class);
        $registry->register('recipes.generator', DummyAgent::class);
        $registry->register('recipes.suggester', DummyAgent::class);
        $registry->register('economy.analyzer', DummyAgent::class);

        $this->assertSame(
            ['recipes.generator', 'recipes.suggester'],
            $registry->forModule('recipes'),
        );
        $this->assertSame([], $registry->forModule('translations'));
    }

    /**
     * getClass() exposes the registration without resolving the container.
     */
    public function test_get_class_returns_registered_class_without_instantiating(): void
    {
        $registry = $this->app->make(AgentRegistry::class);
        $registry->register('recipes.generator', DummyAgent::class);

        $this->assertSame(DummyAgent::class, $registry->getClass('recipes.generator'));
        $this->assertNull($registry->getClass('unknown.key'));
    }

    /**
     * Re-registering the same key must replace the previous class (the
     * service provider relies on this to allow host overrides).
     */
    public function test_re_registering_same_key_replaces_previous_class(): void
    {
        $registry = $this->app->make(AgentRegistry::class);
        $registry->register('recipes.generator', DummyAgent::class);

        $replacement = new class extends DummyAgent {};

        $registry->register('recipes.generator', $replacement::class);

        $this->assertSame($replacement::class, $registry->getClass('recipes.generator'));
    }

    /**
     * A class-string that the container cannot construct surfaces the
     * container's own error instead of a silent failure.
     */
    public function test_get_with_unconstructable_class_surfaces_container_error(): void
    {
        $registry = $this->app->make(AgentRegistry::class);
        $registry->register('broken.agent', 'Not\\A\\Real\\Class');

        $this->expectException(BindingResolutionException::class);

        $registry->get('broken.agent');
    }

    /**
     * Modules are stored keyed by their declared module identifier.
     */
    public function test_register_module_stores_by_module_identifier(): void
    {
        $registry = $this->app->make(AgentRegistry::class);

        $registry->registerModule(new DummyModule);

        $this->assertSame(['recipes' => $registry->getModule('recipes')], $registry->getModules());
        $this->assertInstanceOf(ModuleAiProvider::class, $registry->getModule('recipes'));
        $this->assertNull($registry->getModule('assistant'));
    }
}
