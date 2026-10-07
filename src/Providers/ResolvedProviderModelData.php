<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;

/**
 * A resolved provider + model pair for one capability.
 *
 * Shared result shape for every capability-driven operation (text agents,
 * embeddings, future reranking), so callers never carry SDK internals.
 */
final readonly class ResolvedProviderModelData
{
    public function __construct(
        public AiProvider $provider,
        public AiProviderModel $model,
        public Capability $capability,
        public string $dynamicName,
    ) {}
}
