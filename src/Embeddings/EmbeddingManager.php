<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Embeddings;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Exceptions\EmbeddingDimensionMismatchException;
use HomeSide\AiAgents\Exceptions\NoEmbeddingProviderException;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Providers\DynamicProviderRegistrar;
use HomeSide\AiAgents\Providers\ProviderModelResolver;
use HomeSide\AiAgents\Providers\ProviderResolver;
use InvalidArgumentException;
use Laravel\Ai\Embeddings;

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
     * @throws NoEmbeddingProviderException When no provider honours the
     *                                      privacy policy for embeddings.
     * @throws EmbeddingDimensionMismatchException When the provider returns
     *                                             an inconsistent result.
     */
    public function embed(EmbeddingRequestData $request): EmbeddingResultData
    {
        if ($request->inputs === []) {
            throw new InvalidArgumentException('At least one input is required to generate embeddings.');
        }

        $provider = $this->resolveProvider($request);
        $model = $this->resolveModel($provider, $request);

        $dynamicName = $this->registrar->register($provider);

        $pending = Embeddings::for(array_values($request->inputs));

        if ($model->embedding_dimensions !== null && $model->embedding_dimensions > 0) {
            $pending = $pending->dimensions($model->embedding_dimensions);
        }

        $pending = $pending
            ->timeout($request->timeout ?? (int) config('ai-agents.embeddings.timeout', 30));

        $cache = $this->cacheConfiguration();
        if ($cache['enabled']) {
            $pending = $pending->cache($cache['seconds'], $cache['individually']);
        }

        $response = $pending->generate(provider: $dynamicName, model: $model->model);

        $embeddings = $response->embeddings;

        if (count($embeddings) !== count($request->inputs)) {
            throw EmbeddingDimensionMismatchException::countMismatch(count($request->inputs), count($embeddings));
        }

        $dimensions = $model->embedding_dimensions;

        foreach ($embeddings as $vector) {
            if ($dimensions !== null && count($vector) !== $dimensions) {
                throw EmbeddingDimensionMismatchException::vectorMismatch($dimensions, count($vector));
            }
        }

        /** @var list<list<float>> $vectors */
        $vectors = array_map(
            static fn (array $vector): array => array_map(static fn (int|float $v): float => (float) $v, $vector),
            $embeddings,
        );

        $usage = $response->usage->toArray();

        return new EmbeddingResultData(
            embeddings: $vectors,
            providerId: $provider->id,
            providerName: $provider->name,
            providerModelId: $model->id,
            model: $model->model,
            dimensions: $dimensions ?? (isset($vectors[0]) ? count($vectors[0]) : 0),
            usage: [
                'inputTokens' => (int) ($usage['input_tokens'] ?? 0),
                'totalTokens' => (int) ($usage['total_tokens'] ?? 0),
            ],
        );
    }

    /**
     * Resolve the provider serving embeddings, preserving privacy policy.
     */
    private function resolveProvider(EmbeddingRequestData $request): AiProvider
    {
        if ($request->providerId !== null && $request->providerId !== '') {
            $provider = AiProvider::query()->find($request->providerId);

            if ($provider === null || ! $provider->enabled) {
                throw NoEmbeddingProviderException::forModule($request->module);
            }

            return $provider;
        }

        $provider = $this->providerResolver->resolveForCapability(
            $request->module,
            Capability::Embeddings,
            $request->userId,
            $request->tenantId,
        );

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
