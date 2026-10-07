<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use HomeSide\AiAgents\Models\AiProvider;
use Laravel\Ai\Embeddings;

/**
 * Probes an embeddings model.
 *
 * The text tester (AiProviderTester) cannot validate an embedding model:
 * this one embeds a fixed probe string and verifies the shape of the
 * result (one vector, non-empty, numeric, positive dimension), so a
 * successful probe can seed capabilities_detected += embeddings and
 * embedding_dimensions. Embeddings are never inferred from a model name.
 */
final class EmbeddingProviderTester
{
    public function __construct(
        private readonly DynamicProviderRegistrar $registrar,
        private readonly AiProviderEndpointPolicy $endpointPolicy,
    ) {}

    /**
     * Probe a stored provider's default embeddings model.
     *
     * @throws \InvalidArgumentException When the endpoint violates the SSRF policy.
     */
    public function testProvider(AiProvider $provider, ?string $model = null, ?int $dimensions = null): ProviderTestData
    {
        $this->endpointPolicy->validate($provider->base_url);

        $dynamicName = $this->registrar->register($provider);

        return $this->probe($dynamicName, $model ?? $provider->model, $dimensions);
    }

    /**
     * Probe an unsaved provider configuration.
     *
     * @param  array{type: string, base_url: string, api_key: string, model: string}  $config
     */
    public function testConfig(array $config, ?int $dimensions = null): ProviderTestData
    {
        $this->endpointPolicy->validate($config['base_url']);

        $dynamicName = $this->registrar->registerFromData($config);

        return $this->probe($dynamicName, $config['model'], $dimensions);
    }

    /**
     * Run the probe and validate the resulting vector shape.
     */
    private function probe(string $dynamicName, string $model, ?int $dimensions): ProviderTestData
    {
        $start = microtime(true);

        try {
            $pending = Embeddings::for(['HomeSide embedding capability probe']);

            if ($dimensions !== null && $dimensions > 0) {
                $pending = $pending->dimensions($dimensions);
            }

            $response = $pending->timeout((int) config('ai-agents.embeddings.timeout', 30))->generate(
                provider: $dynamicName,
                model: $model,
            );

            $latency = (int) round((microtime(true) - $start) * 1000);

            if (count($response->embeddings) !== 1) {
                return ProviderTestData::error('The embeddings probe did not return exactly one vector.');
            }

            $vector = $response->embeddings[0];

            if ($vector === []) {
                return ProviderTestData::error('The embeddings probe returned an empty vector.');
            }

            foreach ($vector as $value) {
                if (! is_int($value) && ! is_float($value)) {
                    return ProviderTestData::error('The embeddings probe returned non-numeric vector values.');
                }
            }

            return ProviderTestData::ok($latency, (string) count($vector));
        } catch (\Exception $e) {
            return ProviderTestData::error("Embedding probe failed: {$e->getMessage()}");
        }
    }
}
