<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Exceptions\NoProviderModelException;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use Laravel\Ai\Embeddings;

/**
 * Probes an embeddings model.
 *
 * The text tester (AiProviderTester) cannot validate an embedding model:
 * this one embeds a fixed probe string and verifies the shape of the
 * result (one vector, non-empty, numeric, positive dimension). A successful
 * probe can seed capabilities_detected += embeddings and
 * embedding_dimensions. Embeddings are never inferred from a model name.
 *
 * Always probe an explicit AiProviderModel: using AiProvider.model would
 * probe the legacy TEXT model, not the embeddings one.
 */
final class EmbeddingProviderTester
{
    public function __construct(
        private readonly DynamicProviderRegistrar $registrar,
        private readonly AiProviderEndpointPolicy $endpointPolicy,
        private readonly ProviderModelResolver $modelResolver,
    ) {}

    /**
     * Probe a stored provider's default embeddings model.
     *
     * @throws \InvalidArgumentException When the endpoint violates the SSRF policy.
     * @throws NoProviderModelException When the
     *                                  provider has no usable embeddings model.
     */
    public function testProvider(AiProvider $provider, ?string $modelId = null): EmbeddingProviderTestData
    {
        $this->endpointPolicy->validate($provider->base_url);

        $model = $modelId !== null && $modelId !== ''
            ? $this->modelResolver->resolveExplicit($provider, $modelId, Capability::Embeddings)
            : $this->modelResolver->resolveDefault($provider, Capability::Embeddings);

        return $this->testModel($provider, $model);
    }

    /**
     * Probe one concrete embeddings model.
     *
     * @throws \InvalidArgumentException When the endpoint violates the SSRF policy.
     */
    public function testModel(AiProvider $provider, AiProviderModel $model): EmbeddingProviderTestData
    {
        $this->endpointPolicy->validate($provider->base_url);

        $dynamicName = $this->registrar->register($provider);

        return $this->probe($dynamicName, $model);
    }

    /**
     * Run the probe and validate the resulting vector shape.
     */
    private function probe(string $dynamicName, AiProviderModel $model): EmbeddingProviderTestData
    {
        $start = microtime(true);

        try {
            $pending = Embeddings::for(['HomeSide embedding capability probe']);

            if (($model->embedding_dimensions ?? 0) > 0) {
                $pending = $pending->dimensions($model->embedding_dimensions);
            }

            $response = $pending
                ->timeout((int) config('ai-agents.embeddings.timeout', 30))
                ->generate(provider: $dynamicName, model: $model->model);

            $latency = (int) round((microtime(true) - $start) * 1000);

            if (count($response->embeddings) !== 1) {
                return EmbeddingProviderTestData::error('The embeddings probe did not return exactly one vector.');
            }

            $vector = $response->embeddings[0];

            if ($vector === []) {
                return EmbeddingProviderTestData::error('The embeddings probe returned an empty vector.');
            }

            foreach ($vector as $value) {
                if (! is_int($value) && ! is_float($value)) {
                    return EmbeddingProviderTestData::error('The embeddings probe returned non-numeric vector values.');
                }
            }

            if (($model->embedding_dimensions ?? 0) > 0 && count($vector) !== $model->embedding_dimensions) {
                return EmbeddingProviderTestData::error(
                    'The embeddings probe returned '.count($vector).' dimensions; expected '.$model->embedding_dimensions.'.',
                );
            }

            return EmbeddingProviderTestData::ok($latency, count($vector));
        } catch (\Exception $e) {
            return EmbeddingProviderTestData::error("Embedding probe failed: {$e->getMessage()}");
        }
    }
}
