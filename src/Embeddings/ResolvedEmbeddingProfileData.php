<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Embeddings;

use HomeSide\AiAgents\Enums\EmbeddingPurpose;
use HomeSide\AiAgents\Enums\PrivacyLevel;
use Illuminate\Contracts\Support\Arrayable;

/**
 * A resolved embedding profile: the identity of the vector space an
 * embedding belongs to.
 *
 * Pure data — no Eloquent models, no secrets (API keys, headers and
 * credentials are deliberately excluded), so it is safe to serialize and
 * persist alongside a vector. Resolving it never calls the provider.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ResolvedEmbeddingProfileData implements Arrayable
{
    /**
     * @param  array<string, mixed>  $providerOptions  The effective options for
     *                                                 the requested purpose.
     * @param  string  $fingerprint  The stable identity of the WHOLE profile
     *                               (document + query + generic).
     */
    public function __construct(
        public string $providerId,
        public string $providerName,
        public string $providerModelId,
        public string $driver,
        public string $model,
        public int $dimensions,
        public int $profileVersion,
        public EmbeddingPurpose $purpose,
        public array $providerOptions,
        public string $fingerprint,
        public ?PrivacyLevel $privacyLevel = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'provider_name' => $this->providerName,
            'provider_model_id' => $this->providerModelId,
            'driver' => $this->driver,
            'model' => $this->model,
            'dimensions' => $this->dimensions,
            'profile_version' => $this->profileVersion,
            'purpose' => $this->purpose->value,
            'provider_options' => $this->providerOptions,
            'fingerprint' => $this->fingerprint,
            'privacy_level' => $this->privacyLevel?->value,
        ];
    }
}
