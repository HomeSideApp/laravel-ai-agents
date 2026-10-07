<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Embeddings;

use HomeSide\AiAgents\Enums\PrivacyLevel;
use InvalidArgumentException;

/**
 * A request to embed one or more inputs.
 *
 * When providerModelId is set, that exact model is used (pinned), but it is
 * still re-validated through the resolver: ownership, tenant access,
 * enabled state, the Embeddings capability and privacy all apply. Knowing a
 * UUID never bypasses the resolver.
 */
final readonly class EmbeddingRequestData
{
    /**
     * @param  int|string  $userId  The owner user id.
     * @param  int|string|null  $tenantId  The tenant id, if any.
     * @param  string  $module  The module the embeddings are requested for.
     * @param  list<string>  $inputs  The strings to embed (non-empty).
     * @param  string|null  $providerModelId  A pinned AiProviderModel id, or null for the default.
     * @param  string|null  $providerId  A pinned AiProvider id, or null for scope resolution.
     * @param  int|null  $timeout  Per-request timeout in seconds, or null for config.
     * @param  PrivacyLevel|null  $requiredPrivacyLevel  Requirement of THIS
     *                                                   operation (applied even
     *                                                   to a pinned provider),
     *                                                   distinct from the
     *                                                   provider's fallback
     *                                                   policy.
     * @param  int|null  $batchSize  Max inputs per provider call, or null for config.
     */
    public function __construct(
        public int|string $userId,
        public int|string|null $tenantId,
        public string $module,
        public array $inputs,
        public ?string $providerModelId = null,
        public ?string $providerId = null,
        public ?int $timeout = null,
        public ?PrivacyLevel $requiredPrivacyLevel = null,
        public ?int $batchSize = null,
    ) {
        if ($batchSize !== null && $batchSize < 1) {
            throw new InvalidArgumentException('batchSize must be greater than zero.');
        }
    }
}
