<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Enums\AiDriver;
use HomeSide\AiAgents\Enums\EmbeddingProviderTestError;
use HomeSide\AiAgents\Exceptions\NoProviderModelException;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\EmbeddingsResponse;

/**
 * Probes an embeddings model.
 *
 * The text tester (AiProviderTester) cannot validate an embedding model:
 * this one embeds a fixed probe string and inspects the response.
 *
 * Two paths, chosen by whether the model already declares its dimensions:
 *
 * - known dimensions   → probe WITH ->dimensions() and VERIFY the returned
 *   vector length matches; a mismatch is an error (never auto-corrected, as
 *   it may signal an unexpected model change).
 * - unknown dimensions → only on drivers whose SDK provider supports native
 *   embedding dimensions (can probe without knowing N): DISCOVER the length
 *   from the response. On any other driver the probe fails BEFORE calling
 *   the provider, so a known SDK restriction is not reported as a generic
 *   provider error.
 *
 * Ownership is validated so the probe can never mix a provider's endpoint
 * with another provider's model name. Capability::Embeddings and `enabled`
 * are deliberately NOT required: detecting the capability is the purpose of
 * the probe, so an exploratory (not-yet-classified, disabled) model is
 * allowed.
 *
 * The tester never writes to the database: it observes. Persisting the
 * result is the job of {@see ProviderModelProbeResultApplier}.
 */
final class EmbeddingProviderTester
{
    private const PROBE_INPUT = 'HomeSide embedding capability probe';

    public function __construct(
        private readonly DynamicProviderRegistrar $registrar,
        private readonly AiProviderEndpointPolicy $endpointPolicy,
        private readonly ProviderModelResolver $modelResolver,
    ) {}

    /**
     * Probe a provider's DEFAULT embeddings model.
     *
     * The default is fully configured by definition (the resolver requires
     * the Embeddings capability and positive dimensions), so this verifies
     * the declared dimensions. For an exploratory probe of an arbitrary
     * (possibly unconfigured) model — including capability discovery on a
     * driver that supports native embedding dimensions — use testModel().
     *
     * @throws \InvalidArgumentException When the endpoint violates the SSRF policy.
     * @throws NoProviderModelException When the provider has no usable embeddings model.
     */
    public function testProvider(AiProvider $provider): EmbeddingProviderTestData
    {
        $this->endpointPolicy->validate($provider->base_url);

        $model = $this->modelResolver->resolveDefault($provider, Capability::Embeddings);

        return $this->testModel($provider, $model);
    }

    /**
     * Probe a specific provider model (resolved and validated).
     *
     * @deprecated Pass an AiProviderModel to testModel() for an explicit
     *             model; this variant requires a fully configured model and
     *             cannot discover dimensions.
     *
     * @throws \InvalidArgumentException When the endpoint violates the SSRF policy.
     * @throws NoProviderModelException When the provider has no usable embeddings model.
     */
    public function testProviderModel(AiProvider $provider, string $modelId): EmbeddingProviderTestData
    {
        $this->endpointPolicy->validate($provider->base_url);

        $model = $this->modelResolver->resolveExplicit($provider, $modelId, Capability::Embeddings);

        return $this->testModel($provider, $model);
    }

    /**
     * Probe one concrete model, exploratory or configured.
     *
     * @throws \InvalidArgumentException When the endpoint violates the SSRF policy.
     * @throws NoProviderModelException When the model belongs to another provider.
     */
    public function testModel(AiProvider $provider, AiProviderModel $model): EmbeddingProviderTestData
    {
        if ((string) $model->ai_provider_id !== (string) $provider->id) {
            throw NoProviderModelException::notOwned($model->id, $provider->name);
        }

        $this->endpointPolicy->validate($provider->base_url);

        $dynamicName = $this->registrar->register($provider);

        if (($model->embedding_dimensions ?? 0) > 0) {
            return $this->probeKnownDimensions($dynamicName, $model);
        }

        return $this->probeUnknownDimensions($provider, $dynamicName, $model);
    }

    /**
     * Probe a model whose dimensions are already declared: verify them.
     */
    private function probeKnownDimensions(string $dynamicName, AiProviderModel $model): EmbeddingProviderTestData
    {
        $expected = (int) $model->embedding_dimensions;
        $start = microtime(true);

        try {
            $response = $this->generate($dynamicName, $model, dimensions: $expected);
        } catch (\Exception $e) {
            return EmbeddingProviderTestData::error(
                EmbeddingProviderTestError::ProviderError,
                "Embedding probe failed: {$e->getMessage()}",
            );
        }

        $latency = (int) round((microtime(true) - $start) * 1000);
        $invalid = $this->validateShape($response);

        if ($invalid !== null) {
            return $invalid;
        }

        /** @var list<float|int> $vector */
        $vector = $response->embeddings[0];

        if (count($vector) !== $expected) {
            return EmbeddingProviderTestData::error(
                EmbeddingProviderTestError::DimensionMismatch,
                'The embeddings probe returned '.count($vector)." dimensions; the model declares {$expected}.",
            );
        }

        return EmbeddingProviderTestData::configured($latency, $expected);
    }

    /**
     * Probe a model whose dimensions are unknown: discover them, but only on
     * drivers whose SDK provider allows running without dimensions.
     */
    private function probeUnknownDimensions(
        AiProvider $provider,
        string $dynamicName,
        AiProviderModel $model,
    ): EmbeddingProviderTestData {
        $driver = AiDriver::fromColumn($provider->type);

        if (! $driver->supportsNativeEmbeddingDimensions()) {
            return EmbeddingProviderTestData::error(
                EmbeddingProviderTestError::DimensionsRequired,
                'Embedding dimensions must be configured before this model can be probed with '
                ."the [{$driver->value}] driver.",
            );
        }

        $start = microtime(true);

        try {
            $response = $this->generate($dynamicName, $model, dimensions: null);
        } catch (\Exception $e) {
            return EmbeddingProviderTestData::error(
                EmbeddingProviderTestError::ProviderError,
                "Embedding probe failed: {$e->getMessage()}",
            );
        }

        $latency = (int) round((microtime(true) - $start) * 1000);
        $invalid = $this->validateShape($response);

        if ($invalid !== null) {
            return $invalid;
        }

        /** @var list<float|int> $vector */
        $vector = $response->embeddings[0];

        return EmbeddingProviderTestData::discovered($latency, count($vector));
    }

    /**
     * Run the provider call, passing dimensions only when known.
     */
    private function generate(string $dynamicName, AiProviderModel $model, ?int $dimensions): EmbeddingsResponse
    {
        $pending = Embeddings::for([self::PROBE_INPUT]);

        if ($dimensions !== null) {
            $pending = $pending->dimensions($dimensions);
        }

        return $pending
            ->timeout((int) config('ai-agents.embeddings.timeout', 30))
            ->generate(provider: $dynamicName, model: $model->model);
    }

    /**
     * Validate the shared response shape (exactly one vector, non-empty,
     * numeric). Returns an error DTO when invalid, or null when valid.
     */
    private function validateShape(EmbeddingsResponse $response): ?EmbeddingProviderTestData
    {
        if (count($response->embeddings) !== 1) {
            return EmbeddingProviderTestData::error(
                EmbeddingProviderTestError::VectorCountMismatch,
                'The embeddings probe did not return exactly one vector.',
            );
        }

        $vector = $response->embeddings[0];

        if ($vector === []) {
            return EmbeddingProviderTestData::error(
                EmbeddingProviderTestError::EmptyVector,
                'The embeddings probe returned an empty vector.',
            );
        }

        foreach ($vector as $value) {
            if (! is_int($value) && ! is_float($value)) {
                return EmbeddingProviderTestData::error(
                    EmbeddingProviderTestError::InvalidVector,
                    'The embeddings probe returned non-numeric vector values.',
                );
            }
        }

        return null;
    }
}
