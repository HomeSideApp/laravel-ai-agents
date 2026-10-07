<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Embeddings;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Enums\PrivacyLevel;
use HomeSide\AiAgents\Exceptions\EmbeddingDimensionMismatchException;
use HomeSide\AiAgents\Exceptions\NoEmbeddingProviderException;
use HomeSide\AiAgents\Exceptions\PrivacyViolationException;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Providers\DynamicProviderRegistrar;
use HomeSide\AiAgents\Providers\ProviderModelResolver;
use HomeSide\AiAgents\Providers\ProviderResolver;
use InvalidArgumentException;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\EmbeddingsResponse;

/**
 * Generic embeddings infrastructure.
 *
 * Knows nothing about semantic memory: it resolves a provider+model for the
 * Embeddings capability, validates the result shape and returns a plain
 * DTO. Vector storage (MariaDB VECTOR, pgvector, Qdrant...) is the host's
 * concern, so the package never assumes a specific backend.
 *
 * Uses Laravel AI's own Embeddings API and caching, and deliberately does
 * NOT create AiRun rows: an embedding call is not an agent execution.
 */
final class EmbeddingManager
{
    public function __construct(
        private readonly ProviderResolver $providerResolver,
        private readonly ProviderModelResolver $modelResolver,
        private readonly DynamicProviderRegistrar $registrar,
    ) {}

    /**
     * Generate embeddings for the given request.
     *
     * @throws NoEmbeddingProviderException When no authorised provider
     *                                      declares an embeddings model.
     * @throws PrivacyViolationException When requiredPrivacyLevel is not met.
     * @throws EmbeddingDimensionMismatchException When the provider returns
     *                                             an inconsistent result.
     */
    public function embed(EmbeddingRequestData $request): EmbeddingResultData
    {
        if ($request->inputs === []) {
            throw new InvalidArgumentException('At least one input is required to generate embeddings.');
        }

        $provider = $this->resolveProvider($request);

        // requiredPrivacyLevel is a requirement of THIS operation and is
        // enforced even when the provider was pinned explicitly.
        if ($request->requiredPrivacyLevel !== null
            && ! PrivacyLevel::fromColumn($provider->privacy_level)->isAtLeast($request->requiredPrivacyLevel)) {
            throw new PrivacyViolationException(
                "The embeddings provider [{$provider->name}] does not meet the required privacy level "
                ."[{$request->requiredPrivacyLevel->value}].",
            );
        }

        $model = $this->resolveModel($provider, $request);

        // ProviderModelResolver guarantees a positive value for embeddings.
        $dimensions = (int) $model->embedding_dimensions;

        $dynamicName = $this->registrar->register($provider);

        /** @var list<string> $inputs */
        $inputs = array_values($request->inputs);
        $batchSize = $request->batchSize ?? (int) config('ai-agents.embeddings.batch_size', 50);
        $batches = $batchSize > 0 ? array_chunk($inputs, $batchSize) : [$inputs];

        $vectors = [];
        $inputTokens = 0;
        $outputTokens = 0;

        foreach ($batches as $batch) {
            $response = $this->generateBatch($provider, $model, $batch, $dynamicName, $request);

            if (count($response->embeddings) !== count($batch)) {
                throw EmbeddingDimensionMismatchException::countMismatch(count($batch), count($response->embeddings));
            }

            foreach ($response->embeddings as $vector) {
                if (count($vector) !== $dimensions) {
                    throw EmbeddingDimensionMismatchException::vectorMismatch($dimensions, count($vector));
                }

                /** @var list<float> $cast */
                $cast = array_map(static fn (int|float $value): float => (float) $value, $vector);
                $vectors[] = $cast;
            }

            $inputTokens += $response->usage->inputTokens;
            $outputTokens += $response->usage->outputTokens;
        }

        return new EmbeddingResultData(
            embeddings: $vectors,
            providerId: $provider->id,
            providerName: $provider->name,
            providerModelId: $model->id,
            model: $model->model,
            dimensions: $dimensions,
            usage: [
                'inputTokens' => $inputTokens,
                'outputTokens' => $outputTokens,
                'totalTokens' => $inputTokens + $outputTokens,
            ],
        );
    }

    /**
     * Generate one batch, mapping the package cache config to the SDK.
     *
     * @param  list<string>  $batch
     */
    private function generateBatch(
        AiProvider $provider,
        AiProviderModel $model,
        array $batch,
        string $dynamicName,
        EmbeddingRequestData $request,
    ): EmbeddingsResponse {
        $pending = Embeddings::for($batch)
            ->dimensions((int) $model->embedding_dimensions)
            ->timeout($request->timeout ?? (int) config('ai-agents.embeddings.timeout', 30));

        $cache = $this->cacheConfiguration();

        if ($cache['enabled']) {
            $pending = $pending->cache($cache['seconds'], $cache['individually']);
        }

        return $pending->generate(provider: $dynamicName, model: $model->model);
    }

    /**
     * Resolve the provider serving embeddings, preserving privacy policy.
     *
     * A pinned provider id passes through resolveExplicitForCapability() —
     * never a raw find() — so authorisation, module and privacy anchor still
     * apply and a foreign UUID cannot become an IDOR.
     */
    private function resolveProvider(EmbeddingRequestData $request): AiProvider
    {
        if ($request->providerId !== null && $request->providerId !== '') {
            $provider = $this->providerResolver->resolveExplicitForCapability(
                providerId: $request->providerId,
                module: $request->module,
                capability: Capability::Embeddings,
                userId: $request->userId,
                tenantId: $request->tenantId,
                pinnedModelId: $request->providerModelId,
            );
        } else {
            $provider = $this->providerResolver->resolveForCapability(
                $request->module,
                Capability::Embeddings,
                $request->userId,
                $request->tenantId,
            );
        }

        if ($provider === null) {
            throw NoEmbeddingProviderException::forModule($request->module);
        }

        return $provider;
    }

    /**
     * Resolve the embeddings model, pinning it when an id was supplied.
     */
    private function resolveModel(AiProvider $provider, EmbeddingRequestData $request): AiProviderModel
    {
        if ($request->providerModelId !== null && $request->providerModelId !== '') {
            return $this->modelResolver->resolveExplicit(
                $provider,
                $request->providerModelId,
                Capability::Embeddings,
            );
        }

        return $this->modelResolver->resolveDefault($provider, Capability::Embeddings);
    }

    /**
     * Translate the package embeddings cache config into the SDK's own cache.
     *
     * @return array{enabled: bool, seconds: int|null, individually: bool}
     */
    private function cacheConfiguration(): array
    {
        $config = (array) config('ai-agents.embeddings.cache', []);

        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'seconds' => isset($config['seconds']) ? (int) $config['seconds'] : null,
            'individually' => (bool) ($config['individually'] ?? true),
        ];
    }
}
