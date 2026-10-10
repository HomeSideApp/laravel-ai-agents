<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Embeddings;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Enums\AiDriver;
use HomeSide\AiAgents\Enums\EmbeddingPurpose;
use HomeSide\AiAgents\Enums\PrivacyLevel;
use HomeSide\AiAgents\Exceptions\NoEmbeddingProviderException;
use HomeSide\AiAgents\Exceptions\PrivacyViolationException;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Providers\ProviderModelResolver;
use HomeSide\AiAgents\Providers\ProviderResolver;
use InvalidArgumentException;

/**
 * Resolves the embedding profile — the identity of the vector space — for a
 * given context and purpose, WITHOUT generating any embedding.
 *
 * Reuses ProviderResolver and ProviderModelResolver so every existing
 * invariant holds: user → tenant → system scope, module fallback, privacy
 * fallback policy, requiredPrivacyLevel, provider/model pinning, database
 * tenancy, cross-user IDOR, enabled model, Capability::Embeddings and
 * dimensions > 0. It never calls AiProvider::find()/AiProviderModel::find()
 * as a substitute for those services.
 */
final class EmbeddingProfileResolver
{
    public function __construct(
        private readonly ProviderResolver $providerResolver,
        private readonly ProviderModelResolver $modelResolver,
        private readonly EmbeddingProfileFingerprint $fingerprint,
        private readonly EmbeddingOptionsValidator $optionsValidator,
    ) {}

    /**
     * Resolve the public profile DTO (no Eloquent, no provider call).
     *
     * @throws NoEmbeddingProviderException When no authorised provider declares
     *                                      an embeddings model.
     * @throws PrivacyViolationException When requiredPrivacyLevel is not met.
     */
    public function resolve(
        int|string $userId,
        int|string|null $tenantId,
        string $module,
        EmbeddingPurpose $purpose = EmbeddingPurpose::Generic,
        ?string $providerId = null,
        ?string $providerModelId = null,
        ?PrivacyLevel $requiredPrivacyLevel = null,
    ): ResolvedEmbeddingProfileData {
        return $this->resolveContext(
            $userId,
            $tenantId,
            $module,
            $purpose,
            $providerId,
            $providerModelId,
            $requiredPrivacyLevel,
        )->profile;
    }

    /**
     * Resolve the internal context (Eloquent rows + public profile).
     *
     * @throws NoEmbeddingProviderException
     * @throws PrivacyViolationException
     */
    public function resolveContext(
        int|string $userId,
        int|string|null $tenantId,
        string $module,
        EmbeddingPurpose $purpose = EmbeddingPurpose::Generic,
        ?string $providerId = null,
        ?string $providerModelId = null,
        ?PrivacyLevel $requiredPrivacyLevel = null,
    ): ResolvedEmbeddingProfileContext {
        $provider = $this->resolveProvider(
            $userId,
            $tenantId,
            $module,
            $providerId,
            $providerModelId,
            $requiredPrivacyLevel,
        );

        // resolveProvider already enforced the privacy requirement for a
        // provider; re-assert here as defence in depth.
        if ($requiredPrivacyLevel !== null
            && ! PrivacyLevel::fromColumn($provider->privacy_level)->isAtLeast($requiredPrivacyLevel)) {
            throw new PrivacyViolationException(
                "The embeddings provider [{$provider->name}] does not meet the required privacy level "
                ."[{$requiredPrivacyLevel->value}].",
            );
        }

        $model = $providerModelId !== null && $providerModelId !== ''
            ? $this->modelResolver->resolveExplicit($provider, $providerModelId, Capability::Embeddings)
            : $this->modelResolver->resolveDefault($provider, Capability::Embeddings);

        // Fail closed on legacy/corrupt data: the fingerprint must represent
        // the stored options, never a sanitised interpretation of them.
        $options = $this->optionsValidator->validate($model->embedding_options);

        $profileVersion = $model->embedding_profile_version ?? 1;

        if ($profileVersion < 1) {
            throw new InvalidArgumentException(
                "The embedding profile version of model [{$model->model}] must be >= 1, got {$profileVersion}.",
            );
        }

        $profile = new ResolvedEmbeddingProfileData(
            providerId: $provider->id,
            providerName: $provider->name,
            providerModelId: $model->id,
            driver: AiDriver::fromColumn($provider->type)->value,
            model: $model->model,
            dimensions: (int) $model->embedding_dimensions,
            profileVersion: $profileVersion,
            purpose: $purpose,
            providerOptions: $model->embeddingOptionsFor($purpose),
            fingerprint: $this->fingerprint->compute($provider, $model, $options),
            privacyLevel: PrivacyLevel::fromColumn($provider->privacy_level),
        );

        return new ResolvedEmbeddingProfileContext($provider, $model, $profile);
    }

    /**
     * Resolve the provider, preserving privacy policy, pinning and IDOR
     * protections for embeddings.
     */
    private function resolveProvider(
        int|string $userId,
        int|string|null $tenantId,
        string $module,
        ?string $providerId,
        ?string $pinnedModelId,
        ?PrivacyLevel $requiredPrivacyLevel,
    ): AiProvider {
        if ($providerId !== null && $providerId !== '') {
            $provider = $this->providerResolver->resolveExplicitForCapability(
                providerId: $providerId,
                module: $module,
                capability: Capability::Embeddings,
                userId: $userId,
                tenantId: $tenantId,
                pinnedModelId: $pinnedModelId,
                requiredPrivacyLevel: $requiredPrivacyLevel,
            );
        } else {
            $provider = $this->providerResolver->resolveForCapability(
                $module,
                Capability::Embeddings,
                $userId,
                $tenantId,
                $requiredPrivacyLevel,
            );
        }

        if ($provider === null) {
            if ($requiredPrivacyLevel !== null
                && $this->providerResolver->hasCandidateRejectedOnlyByRequiredPrivacy(
                    $module,
                    Capability::Embeddings,
                    $userId,
                    $tenantId,
                )) {
                throw new PrivacyViolationException(
                    "No embeddings provider for module [{$module}] meets the required "
                    ."privacy level [{$requiredPrivacyLevel->value}].",
                );
            }

            throw NoEmbeddingProviderException::forModule($module);
        }

        return $provider;
    }
}
