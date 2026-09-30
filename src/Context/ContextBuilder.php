<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Context;

use HomeSide\AiAgents\Contracts\DomainAgent;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use Illuminate\Support\Collection;

/**
 * Orchestrates the context providers required by an agent.
 *
 * Aggregates context without unnecessary personal identities.
 * Respects the agent's maxContextTokens (simplified: per-provider truncation).
 */
class ContextBuilder
{
    /**
     * @var Collection<string, ContextProvider>
     */
    private Collection $providers;

    /**
     * Seed the builder with an initial provider set.
     *
     * Providers are indexed by their own key() so agent-declared context
     * provider keys resolve in O(1); later registrations replace duplicates.
     *
     * @param  iterable<int, ContextProvider>  $providers  Initial providers,
     *                                                     typically resolved from
     *                                                     the host config.
     */
    public function __construct(iterable $providers = [])
    {
        $this->providers = collect($providers)->keyBy(fn (ContextProvider $p) => $p->key());
    }

    /**
     * Register (or replace) a context provider at runtime.
     *
     * Called by the service provider for each host-configured provider class;
     * replacement semantics allow hosts to override the bundled
     * UserContextProvider with their own implementation under the same key.
     *
     * @param  ContextProvider  $provider  The provider to register; its key()
     *                                     becomes the lookup key.
     */
    public function register(ContextProvider $provider): void
    {
        $this->providers->put($provider->key(), $provider);
    }

    /**
     * Build the combined domain context for one agent execution.
     *
     * Only invokes the providers the agent declares via contextProviders():
     * agents pull the context they need, and unregistered keys are skipped
     * silently so a missing optional provider never aborts a run. The
     * result feeds PromptCompositor's domain-context layer.
     *
     * @param  DomainAgent  $agent  The agent whose required keys drive the build.
     * @param  AiExecutionContextData  $context  The execution context passed
     *                                           through to each provider.
     * @return array<string, mixed> Map of provider key → provided data, in
     *                              the agent's declared order.
     */
    public function build(DomainAgent $agent, AiExecutionContextData $context): array
    {
        $requiredKeys = $agent->contextProviders();
        $result = [];

        foreach ($requiredKeys as $key) {
            $provider = $this->providers->get($key);

            if ($provider === null) {
                continue; // Provider not registered, skip silently
            }

            $provided = $provider->provide($context);
            $result[$provided['key']] = $provided['data'];
        }

        return $result;
    }

    /**
     * Return every registered provider, keyed by provider key.
     *
     * Exposed for diagnostics (which providers could serve an agent?) and
     * tests; execution paths only ever touch the subset an agent declares.
     *
     * @return Collection<string, ContextProvider> Key-indexed provider map.
     */
    public function all(): Collection
    {
        return $this->providers;
    }
}
