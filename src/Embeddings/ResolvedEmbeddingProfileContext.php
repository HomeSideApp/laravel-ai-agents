<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Embeddings;

use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;

/**
 * Internal runtime context pairing the public profile DTO with the Eloquent
 * rows the execution layer needs (dynamic SDK registration).
 *
 * Kept separate from {@see ResolvedEmbeddingProfileData} so the public DTO
 * stays Eloquent-free and serializable.
 */
final readonly class ResolvedEmbeddingProfileContext
{
    public function __construct(
        public AiProvider $provider,
        public AiProviderModel $model,
        public ResolvedEmbeddingProfileData $profile,
    ) {}
}
