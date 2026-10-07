<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Enums\EmbeddingDimensionsSource;
use HomeSide\AiAgents\Enums\EmbeddingProviderTestError;
use HomeSide\AiAgents\Exceptions\NoProviderModelException;
use HomeSide\AiAgents\Exceptions\StaleProviderModelProbeResultException;
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
 * drivers that support it, and fail before any call otherwise. The applier
 * re-validates the snapshot against the locked row before persisting.
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

    private function applier(): ProviderModelProbeResultApplier
    {
        return $this->app->make(ProviderModelProbeResultApplier::class);
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
        $this->assertSame(EmbeddingDimensionsSource::Configured, $result->dimensionsSource);
    }

    // ----- Applier ---------------------------------------------------------

    public function test_applier_persists_discovered_dimensions_into_an_empty_row(): void
    {
        $provider = $this->makeProvider(['type' => 'openai-compatible', 'base_url' => 'https://gateway.example.com/v1']);
        $model = $this->makeModel($provider, 'embed', null, ['capabilities_override' => null, 'capabilities_detected' => null]);

        $this->applier()->apply($model, EmbeddingProviderTestData::discovered(10, 768));

        $fresh = $model->fresh();
        $this->assertSame(768, $fresh->embedding_dimensions);
        $this->assertContains('embeddings', $fresh->capabilities_detected ?? []);
        $this->assertSame('ok', $fresh->last_probe_status);
    }

    public function test_applier_is_idempotent_for_an_equal_discovered_value(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'embed', 768);

        $this->applier()->apply($model, EmbeddingProviderTestData::discovered(10, 768));

        $this->assertSame(768, $model->fresh()->embedding_dimensions);
    }

    public function test_applier_rejects_a_discovered_conflict_without_touching_state(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'embed', 1024, ['capabilities_detected' => ['text']]);

        try {
            $this->applier()->apply($model, EmbeddingProviderTestData::discovered(10, 768));
            $this->fail('A discovered conflict should have been rejected.');
        } catch (StaleProviderModelProbeResultException) {
            // expected
        }

        $fresh = $model->fresh();
        $this->assertSame(1024, $fresh->embedding_dimensions);
        $this->assertSame(['text'], $fresh->capabilities_detected);
        $this->assertNotSame('ok', $fresh->last_probe_status);
    }

    public function test_applier_accepts_a_matching_configured_result(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'embed', 768, ['capabilities_detected' => null]);

        $this->applier()->apply($model, EmbeddingProviderTestData::configured(10, 768));

        $fresh = $model->fresh();
        $this->assertSame(768, $fresh->embedding_dimensions);
        $this->assertContains('embeddings', $fresh->capabilities_detected ?? []);
        $this->assertSame('ok', $fresh->last_probe_status);
    }

    public function test_applier_rejects_a_configured_conflict(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'embed', 1024);

        $this->expectException(StaleProviderModelProbeResultException::class);

        $this->applier()->apply($model, EmbeddingProviderTestData::configured(10, 768));
    }

    public function test_applier_rejects_a_configured_result_when_dimensions_were_removed(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'embed', null);

        $this->expectException(StaleProviderModelProbeResultException::class);

        $this->applier()->apply($model, EmbeddingProviderTestData::configured(10, 768));
    }

    public function test_applier_does_not_overwrite_dimensions_on_a_failed_probe(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'embed', 768, ['capabilities_detected' => ['text']]);

        $this->applier()->apply($model, EmbeddingProviderTestData::error(
            EmbeddingProviderTestError::DimensionMismatch,
            'mismatch',
        ));

        $fresh = $model->fresh();
        $this->assertSame(768, $fresh->embedding_dimensions);
        $this->assertSame(['text'], $fresh->capabilities_detected);
        $this->assertSame('error', $fresh->last_probe_status);
    }

    public function test_applier_rejects_a_stale_instance_after_the_row_changed(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'embed', null);

        // T1: the probe observed an empty row.
        $result = EmbeddingProviderTestData::discovered(10, 768);

        // T2: someone else configured dimensions before apply().
        $model->fresh()->forceFill(['embedding_dimensions' => 1024])->save();

        // T3: the optimistic snapshot must be rejected because the applier
        // re-reads the row instead of trusting the stale instance.
        try {
            $this->applier()->apply($model, $result);
            $this->fail('A stale snapshot should have been rejected.');
        } catch (StaleProviderModelProbeResultException) {
            // expected
        }

        $this->assertSame(1024, $model->fresh()->embedding_dimensions);
    }

    public function test_applier_never_modifies_capabilities_override(): void
    {
        $provider = $this->makeProvider(['type' => 'openai-compatible', 'base_url' => 'https://gateway.example.com/v1']);
        $model = $this->makeModel($provider, 'embed', null, [
            'capabilities_override' => ['text'],
            'capabilities_detected' => null,
        ]);

        $this->applier()->apply($model, EmbeddingProviderTestData::discovered(10, 768));

        $fresh = $model->fresh();
        $this->assertSame(['text'], $fresh->capabilities_override);
        $this->assertContains('embeddings', $fresh->capabilities_detected ?? []);
        // The admin override still wins over the detected capability.
        $this->assertFalse($fresh->supportsCapability(Capability::Embeddings, 'openai-compatible'));
    }
}
