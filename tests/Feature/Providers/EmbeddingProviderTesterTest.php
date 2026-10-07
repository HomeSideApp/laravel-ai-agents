<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Enums\EmbeddingDimensionsSource;
use HomeSide\AiAgents\Enums\EmbeddingProviderTestError;
use HomeSide\AiAgents\Exceptions\NoProviderModelException;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Providers\EmbeddingProviderTestData;
use HomeSide\AiAgents\Providers\EmbeddingProviderTester;
use HomeSide\AiAgents\Providers\ProviderModelDefaults;
use HomeSide\AiAgents\Providers\ProviderModelProbeResultApplier;
use HomeSide\AiAgents\Tests\TestCase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;

/**
 * The embeddings tester: verify declared dimensions, discover them on
 * drivers that support it, and fail before any call otherwise.
 */
final class EmbeddingProviderTesterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The openai-compatible test gateway uses a private host; allow it.
        config(['ai-agents.endpoint_policy.mode' => 'self-hosted']);
    }

    private function makeProvider(array $overrides = []): AiProvider
    {
        return AiProvider::createValidated(array_merge([
            'name' => 'P'.uniqid(),
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'qwen3',
            'api_key' => 'sk-test-1234',
            'module' => 'assistant',
            'privacy_level' => 'cloud',
            'fallback_policy' => 'allow_cloud',
            'scope' => 'global',
        ], $overrides));
    }

    private function makeModel(AiProvider $provider, string $model, ?int $dimensions, array $overrides = []): AiProviderModel
    {
        return AiProviderModel::create(array_merge([
            'ai_provider_id' => $provider->id,
            'model' => $model,
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => $dimensions,
        ], $overrides));
    }

    private function tester(): EmbeddingProviderTester
    {
        return $this->app->make(EmbeddingProviderTester::class);
    }

    /**
     * Fake the SDK with a fixed vector length regardless of the request.
     */
    private function fakeVector(int $dimensions): void
    {
        Embeddings::fake(static fn (): EmbeddingsResponse => new EmbeddingsResponse(
            [array_fill(0, $dimensions, 0.1)],
            new Usage,
            new Meta('fake', 'fake-model'),
        ));
    }

    // ----- Known dimensions ------------------------------------------------

    public function test_known_dimensions_are_verified_and_reported_as_configured(): void
    {
        $this->fakeVector(4);

        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'embed', 4);

        $result = $this->tester()->testModel($provider, $model);

        $this->assertTrue($result->successful());
        $this->assertSame(4, $result->dimensions);
        $this->assertSame(EmbeddingDimensionsSource::Configured, $result->dimensionsSource);
    }

    public function test_known_dimensions_mismatch_is_an_error(): void
    {
        $this->fakeVector(2);

        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'embed', 4);

        $result = $this->tester()->testModel($provider, $model);

        $this->assertFalse($result->successful());
        $this->assertSame(EmbeddingProviderTestError::DimensionMismatch, $result->error);
    }

    // ----- Unknown dimensions ----------------------------------------------

    public function test_unknown_dimensions_are_discovered_on_a_native_dimension_driver(): void
    {
        $this->fakeVector(768);

        $provider = $this->makeProvider(['type' => 'openai-compatible', 'base_url' => 'https://gateway.example.com/v1']);
        $model = $this->makeModel($provider, 'community-embed', null, ['capabilities_override' => null]);

        $result = $this->tester()->testModel($provider, $model);

        $this->assertTrue($result->successful());
        $this->assertSame(768, $result->dimensions);
        $this->assertSame(EmbeddingDimensionsSource::Discovered, $result->dimensionsSource);
    }

    public function test_unknown_dimensions_on_a_non_native_driver_fail_before_calling_the_provider(): void
    {
        Embeddings::fake();

        $provider = $this->makeProvider(['type' => 'openai']);
        $model = $this->makeModel($provider, 'embed', null, ['capabilities_override' => null]);

        $result = $this->tester()->testModel($provider, $model);

        $this->assertFalse($result->successful());
        $this->assertSame(EmbeddingProviderTestError::DimensionsRequired, $result->error);
        Embeddings::assertNothingGenerated();
    }

    // ----- Exploratory probing --------------------------------------------

    public function test_a_model_without_detected_capability_can_still_be_probed(): void
    {
        $this->fakeVector(4);

        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'undetected', 4, ['capabilities_override' => null, 'enabled' => false]);

        $result = $this->tester()->testModel($provider, $model);

        $this->assertTrue($result->successful());
    }

    public function test_unknown_dimensions_without_capability_are_discovered_on_a_native_driver(): void
    {
        $this->fakeVector(768);

        $provider = $this->makeProvider(['type' => 'openai-compatible', 'base_url' => 'https://gateway.example.com/v1']);
        $model = $this->makeModel($provider, 'undetected', null, ['capabilities_override' => null, 'enabled' => false]);

        $result = $this->tester()->testModel($provider, $model);

        $this->assertTrue($result->successful());
        $this->assertSame(EmbeddingDimensionsSource::Discovered, $result->dimensionsSource);
    }

    public function test_a_model_from_another_provider_is_rejected(): void
    {
        Embeddings::fake();

        $providerA = $this->makeProvider();
        $providerB = $this->makeProvider();
        $modelB = $this->makeModel($providerB, 'foreign-embed', 4);

        $this->expectException(NoProviderModelException::class);

        $this->tester()->testModel($providerA, $modelB);
    }

    // ----- testProvider resolves the default -------------------------------

    public function test_test_provider_probes_the_embeddings_default(): void
    {
        $this->fakeVector(4);

        $provider = $this->makeProvider(['model' => 'qwen3']);
        $embedding = $this->makeModel($provider, 'nomic-embed-text', 4);
        $this->app->make(ProviderModelDefaults::class)->set($provider, Capability::Embeddings, $embedding);

        $result = $this->tester()->testProvider($provider);

        $this->assertTrue($result->successful());
        $this->assertSame(4, $result->dimensions);
    }

    // ----- Applier ---------------------------------------------------------

    public function test_applier_records_discovered_dimensions_and_capability(): void
    {
        $provider = $this->makeProvider(['type' => 'openai-compatible', 'base_url' => 'https://gateway.example.com/v1']);
        $model = $this->makeModel($provider, 'embed', null, ['capabilities_override' => null]);

        $result = EmbeddingProviderTestData::ok(
            10,
            768,
            EmbeddingDimensionsSource::Discovered,
        );

        $this->app->make(ProviderModelProbeResultApplier::class)->apply($model, $result);

        $fresh = $model->fresh();
        $this->assertSame(768, $fresh->embedding_dimensions);
        $this->assertContains('embeddings', $fresh->capabilities_detected ?? []);
        $this->assertSame('ok', $fresh->last_probe_status);
    }

    public function test_applier_does_not_overwrite_dimensions_on_a_mismatch(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'embed', 768);

        $result = EmbeddingProviderTestData::error(
            EmbeddingProviderTestError::DimensionMismatch,
            'mismatch',
        );

        $this->app->make(ProviderModelProbeResultApplier::class)->apply($model, $result);

        $fresh = $model->fresh();
        $this->assertSame(768, $fresh->embedding_dimensions);
        $this->assertSame('error', $fresh->last_probe_status);
    }
}
