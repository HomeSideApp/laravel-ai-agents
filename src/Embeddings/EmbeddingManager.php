<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Embeddings;

use HomeSide\AiAgents\Exceptions\EmbeddingDimensionMismatchException;
use HomeSide\AiAgents\Exceptions\NoEmbeddingProviderException;
use HomeSide\AiAgents\Exceptions\PrivacyViolationException;
use HomeSide\AiAgents\Providers\DynamicProviderRegistrar;
use InvalidArgumentException;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\EmbeddingsResponse;

/**
 * Generic embeddings infrastructure.
 *
 * Knows nothing about semantic memory: it resolves an embedding PROFILE
 * (provider + model + dimensions + options + fingerprint) through
 * {@see EmbeddingProfileResolver}, validates the result shape and returns a
 * plain DTO. Vector storage (MariaDB VECTOR, pgvector, Qdrant...) is the
 * host's concern, so the package never assumes a specific backend.
 *
 * The profile — never a re-implemented copy of provider/model/privacy
 * resolution — is what drives the call. Uses Laravel AI's own Embeddings API
 * and caching, and deliberately does NOT create AiRun rows: an embedding
 * call is not an agent execution.
 */
final class EmbeddingManager
{
    public function __construct(
        private readonly EmbeddingProfileResolver $profileResolver,
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

        $context = $this->profileResolver->resolveContext(
            userId: $request->userId,
            tenantId: $request->tenantId,
            module: $request->module,
            purpose: $request->purpose,
            providerId: $request->providerId,
            providerModelId: $request->providerModelId,
            requiredPrivacyLevel: $request->requiredPrivacyLevel,
        );

        $provider = $context->provider;
        $model = $context->model;
        $profile = $context->profile;

        $dimensions = $profile->dimensions;

        $dynamicName = $this->registrar->register($provider);

        /** @var list<string> $inputs */
        $inputs = array_values($request->inputs);

        // The manager is the last security boundary: a misconfigured (or
        // non-positive) batch size must fail loudly instead of silently
        // sending every input in a single, unbounded provider call.
        $batchSize = $request->batchSize ?? (int) config('ai-agents.embeddings.batch_size', 50);

        if ($batchSize < 1) {
            throw new InvalidArgumentException(
                'Configured embedding batch size must be greater than zero.',
            );
        }

        $batches = array_chunk($inputs, $batchSize);

        $vectors = [];
        $inputTokens = 0;
        $outputTokens = 0;

        foreach ($batches as $batch) {
            $response = $this->generateBatch($batch, $dynamicName, $model->model, $dimensions, $profile->providerOptions, $request);

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
            profile: $profile,
            providerId: $profile->providerId,
            providerName: $profile->providerName,
            providerModelId: $profile->providerModelId,
            model: $profile->model,
            dimensions: $profile->dimensions,
            usage: [
                'inputTokens' => $inputTokens,
                'outputTokens' => $outputTokens,
                'totalTokens' => $inputTokens + $outputTokens,
            ],
        );
    }

    /**
     * Generate one batch, mapping the profile options and cache config to
     * the SDK.
     *
     * @param  list<string>  $batch
     * @param  array<string, mixed>  $providerOptions
     */
    private function generateBatch(
        array $batch,
        string $dynamicName,
        string $model,
        int $dimensions,
        array $providerOptions,
        EmbeddingRequestData $request,
    ): EmbeddingsResponse {
        $pending = Embeddings::for($batch)
            ->dimensions($dimensions)
            ->timeout($request->timeout ?? (int) config('ai-agents.embeddings.timeout', 30));

        if ($providerOptions !== []) {
            $pending = $pending->withProviderOptions($providerOptions);
        }

        $cache = $this->cacheConfiguration();

        if ($cache['enabled']) {
            $pending = $pending->cache($cache['seconds'], $cache['individually']);
        }

        return $pending->generate(provider: $dynamicName, model: $model);
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
