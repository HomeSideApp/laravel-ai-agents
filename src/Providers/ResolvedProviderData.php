<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use HomeSide\AiAgents\Models\AiProvider;

/**
 * Resolved provider and its runtime SDK registration name.
 *
 * Returned by {@see ProviderResolver::resolveAndRegister()} after matching
 * a module to an enabled provider and registering it dynamically in the
 * Laravel AI SDK configuration.
 */
final readonly class ResolvedProviderData
{
    public function __construct(
        public AiProvider $provider,
        public string $dynamicName,
    ) {}
}
