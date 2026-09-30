<?php

declare(strict_types=1);

namespace HomeSide\AiAgents;

use HomeSide\AiAgents\Contracts\DomainAgent;
use HomeSide\AiAgents\Contracts\ModuleAiProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Registry of agents and modules.
 *
 * Stores class-string<DomainAgent> entries and resolves instances through
 * the Laravel container.
 */
final class AgentRegistry
{
    /** @var array<string, class-string<DomainAgent>> */
    private array $agents = [];

    /** @var array<string, ModuleAiProvider> */
    private array $modules = [];

    /**
     * Bind the container used to resolve agent instances.
     *
     * Constructor resolution via the container lets agent classes declare
     * their own dependencies (services, request data) and get them injected
     * per resolution.
     *
     * @param  Container  $container  The Laravel service container.
     */
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Register an agent class under its canonical key.
     *
     * Keys are module-namespaced ('<module>.<agent_name>') — AgentSynchronizer
     * and AiAgentManager both key off this registry, so re-registering the
     * same key replaces the previous class (last registration wins).
     *
     * @param  string  $key  The canonical agent key.
     * @param  class-string<DomainAgent>  $class  The agent class to register;
     *                                            instantiated lazily via get().
     */
    public function register(string $key, string $class): void
    {
        $this->agents[$key] = $class;
    }

    /**
     * Resolve a fresh agent instance for a registered key.
     *
     * Every call constructs a new instance through the container, so agents
     * are per-request/per-execution by design — never shared state between
     * concurrent runs.
     *
     * @param  string  $key  The canonical agent key.
     * @return DomainAgent A container-resolved instance of the registered class.
     *
     * @throws InvalidArgumentException When the key was never registered.
     */
    public function get(string $key): DomainAgent
    {
        if (! isset($this->agents[$key])) {
            throw new InvalidArgumentException(
                "Agent not registered: {$key}",
            );
        }

        return $this->container->make($this->agents[$key]);
    }

    /**
     * Determine whether an agent key is registered.
     *
     * Used e.g. by AiConversation validation before opening a conversation
     * for an agent.
     *
     * @param  string  $key  The canonical agent key to check.
     * @return bool True when the key has a registered class.
     */
    public function has(string $key): bool
    {
        return isset($this->agents[$key]);
    }

    /**
     * List every registered agent key.
     *
     * Drives AgentSynchronizer's discovery pass (create/verify ai_agents rows).
     *
     * @return string[] All canonical agent keys, in registration order.
     */
    public function all(): array
    {
        return array_keys($this->agents);
    }

    /**
     * List the agent keys that belong to one module.
     *
     * Keys are module-namespaced, so membership is a prefix check.
     *
     * @param  string  $module  The module identifier (e.g. 'recipes').
     * @return string[] Keys starting with '<module>.', re-indexed.
     */
    public function forModule(string $module): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (string $key) => str_starts_with($key, $module.'.'),
        ));
    }

    /**
     * Get the registered class for a key without resolving an instance.
     *
     * Used by the synchroniser to record the implementing class and by tests
     * to assert registrations.
     *
     * @param  string  $key  The canonical agent key.
     * @return class-string<DomainAgent>|null The registered class, or null when
     *                                        the key is unknown.
     */
    public function getClass(string $key): ?string
    {
        return $this->agents[$key] ?? null;
    }

    /**
     * Register an AI module under its module identifier.
     *
     * Modules declare agent defaults (label, system prompt, parameters) that
     * the synchroniser seeds into module_ai_configurations; re-registering
     * an identifier replaces the previous module.
     *
     * @param  ModuleAiProvider  $module  The module implementation to register.
     */
    public function registerModule(ModuleAiProvider $module): void
    {
        $this->modules[$module->module()] = $module;
    }

    /**
     * Get every registered module, keyed by module identifier.
     *
     * @return array<string, ModuleAiProvider> All modules in registration order.
     */
    public function getModules(): array
    {
        return $this->modules;
    }

    /**
     * Look up one module by its identifier.
     *
     * @param  string  $name  The module identifier (ModuleAiProvider::module()).
     * @return ModuleAiProvider|null The registered module, or null when unknown.
     */
    public function getModule(string $name): ?ModuleAiProvider
    {
        return $this->modules[$name] ?? null;
    }
}
