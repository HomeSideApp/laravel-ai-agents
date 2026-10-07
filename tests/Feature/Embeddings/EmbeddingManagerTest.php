<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Embeddings;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Embeddings\EmbeddingManager;
use HomeSide\AiAgents\Embeddings\EmbeddingRequestData;
use HomeSide\AiAgents\Enums\PrivacyLevel;
use HomeSide\AiAgents\Exceptions\EmbeddingDimensionMismatchException;
use HomeSide\AiAgents\Exceptions\NoEmbeddingProviderException;
use HomeSide\AiAgents\Exceptions\NoProviderModelException;
use HomeSide\AiAgents\Exceptions\PrivacyViolationException;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Providers\DynamicProviderRegistrar;
use HomeSide\AiAgents\Providers\ProviderModelDefaults;
use HomeSide\AiAgents\Tests\TestCase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;

/**
 * EmbeddingManager: capability-aware provider+model resolution, result DTO,
 * privacy preservation and dimension validation.
 */
final class EmbeddingManagerTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeProvider(array $overrides = []): AiProvider
    {
        return AiProvider::createValidated(array_merge([
            'name' => 'P'.uniqid(),
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-test-1234',
            'module' => 'assistant',
            'privacy_level' => 'cloud',
            'fallback_policy' => 'same_privacy_level',
            'scope' => 'global',
        ], $overrides));
    }

    private function makeEmbeddingModel(AiProvider $provider, string $model, ?int $dimensions = 1536): AiProviderModel
    {
        return AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => $model,
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => $dimensions,
        ]);
    }

    private function manager(): EmbeddingManager
    {
        return $this->app->make(EmbeddingManager::class);
    }

    /**
     * Register a dynamic provider so the SDK can resolve it during the fake.
     */
    private function registerDynamic(AiProvider $provider): string
    {
        return $this->app->make(DynamicProviderRegistrar::class)->register($provider);
    }

    public function test_embeddings_require_explicit_model_support_in_capability_baseline(): void
    {
        // Cohere's driver baseline advertises embeddings, but the per-model
        // effective capabilities must NOT inherit it.
        $provider = $this->makeProvider(['type' => 'cohere']);
        $model = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'command-r',
            'enabled' => true,
        ]);

        $this->assertFalse($model->supportsCapability(Capability::Embeddings, 'cohere'));
        $this->assertTrue($model->supportsCapability(Capability::Text, 'cohere'));
    }

    public function test_embed_returns_a_result_dto_using_the_default_model(): void
    {
        Embeddings::fake();

        $provider = $this->makeProvider();
        $model = $this->makeEmbeddingModel($provider, 'text-embedding-3-small', 4);
        $this->app->make(ProviderModelDefaults::class)->set($provider, Capability::Embeddings, $model);
        $this->registerDynamic($provider);

        $result = $this->manager()->embed(new EmbeddingRequestData(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            inputs: ['Ana prefiere leche sin lactosa.'],
        ));

        $this->assertCount(1, $result->embeddings);
        $this->assertCount(4, $result->embeddings[0]);
        $this->assertSame('text-embedding-3-small', $result->model);
        $this->assertSame($provider->id, $result->providerId);
        $this->assertSame(4, $result->dimensions);
    }

    public function test_pinned_model_is_used_but_still_validated(): void
    {
        Embeddings::fake();

        $provider = $this->makeProvider();
        $model = $this->makeEmbeddingModel($provider, 'pinned-embed', 3);
        $this->registerDynamic($provider);

        $result = $this->manager()->embed(new EmbeddingRequestData(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            inputs: ['x'],
            providerId: $provider->id,
            providerModelId: $model->id,
        ));

        $this->assertSame('pinned-embed', $result->model);
        $this->assertSame($model->id, $result->providerModelId);
    }

    public function test_pinned_model_from_another_provider_is_rejected(): void
    {
        Embeddings::fake();

        $providerA = $this->makeProvider();
        $providerB = $this->makeProvider();
        $model = $this->makeEmbeddingModel($providerB, 'other-embed');

        $this->expectException(NoProviderModelException::class);

        $this->manager()->embed(new EmbeddingRequestData(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            inputs: ['x'],
            providerId: $providerA->id,
            providerModelId: $model->id,
        ));
    }

    public function test_no_embedding_capable_provider_throws(): void
    {
        Embeddings::fake();

        // Provider exists but has no embeddings default.
        $this->makeProvider();

        $this->expectException(NoEmbeddingProviderException::class);

        $this->manager()->embed(new EmbeddingRequestData(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            inputs: ['x'],
        ));
    }

    public function test_dimension_mismatch_is_rejected(): void
    {
        Embeddings::fake(static fn () => new EmbeddingsResponse(
            [[0.1, 0.2]], // 2 dimensions
            new Usage,
            new Meta('fake', 'declared-4'),
        ));

        $provider = $this->makeProvider();
        $model = $this->makeEmbeddingModel($provider, 'declared-4', 4);
        $this->app->make(ProviderModelDefaults::class)->set($provider, Capability::Embeddings, $model);
        $this->registerDynamic($provider);

        $this->expectException(EmbeddingDimensionMismatchException::class);

        $this->manager()->embed(new EmbeddingRequestData(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            inputs: ['x'],
        ));
    }

    public function test_multiple_inputs_return_the_same_number_of_vectors(): void
    {
        Embeddings::fake();

        $provider = $this->makeProvider();
        $model = $this->makeEmbeddingModel($provider, 'embed', 5);
        $this->app->make(ProviderModelDefaults::class)->set($provider, Capability::Embeddings, $model);
        $this->registerDynamic($provider);

        $result = $this->manager()->embed(new EmbeddingRequestData(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            inputs: ['a', 'b', 'c'],
        ));

        $this->assertCount(3, $result->embeddings);
    }

    public function test_local_only_provider_without_embedding_does_not_degrade_to_cloud(): void
    {
        Embeddings::fake();

        config(['ai-agents.endpoint_policy.mode' => 'self-hosted']);

        // Local user provider, local_only: no embedding model.
        AiProvider::createValidated([
            'name' => 'Local',
            'type' => 'ollama',
            'base_url' => 'http://localhost:11434',
            'model' => 'qwen3',
            'api_key' => 'local-key',
            'module' => 'assistant',
            'privacy_level' => 'local',
            'fallback_policy' => 'local_only',
            'scope' => ['user' => 1],
        ]);

        // Cloud system provider DOES have an embedding model.
        $this->makeCloudEmbeddingProvider();

        $this->expectException(NoEmbeddingProviderException::class);

        $this->manager()->embed(new EmbeddingRequestData(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            inputs: ['x'],
        ));
    }

    public function test_pinned_provider_of_another_user_is_rejected(): void
    {
        Embeddings::fake();

        // Owner 1 provider with embeddings; caller is user 2.
        $owner = $this->makeProvider(['scope' => ['user' => 1]]);
        $model = $this->makeEmbeddingModel($owner, 'owner-embed', 3);
        $this->app->make(ProviderModelDefaults::class)->set($owner, Capability::Embeddings, $model);

        $this->expectException(NoEmbeddingProviderException::class);

        $this->manager()->embed(new EmbeddingRequestData(
            userId: 2,
            tenantId: null,
            module: 'assistant',
            inputs: ['x'],
            providerId: $owner->id,
            providerModelId: $model->id,
        ));
    }

    public function test_pinned_disabled_provider_is_rejected(): void
    {
        Embeddings::fake();

        $provider = $this->makeProvider();
        $model = $this->makeEmbeddingModel($provider, 'embed', 3);
        $this->app->make(ProviderModelDefaults::class)->set($provider, Capability::Embeddings, $model);
        $provider->update(['enabled' => false]);

        $this->expectException(NoEmbeddingProviderException::class);

        $this->manager()->embed(new EmbeddingRequestData(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            inputs: ['x'],
            providerId: $provider->id,
            providerModelId: $model->id,
        ));
    }

    public function test_required_privacy_level_blocks_a_cloud_provider(): void
    {
        Embeddings::fake();

        $provider = $this->makeProvider(['privacy_level' => 'cloud']);
        $model = $this->makeEmbeddingModel($provider, 'embed', 3);
        $this->app->make(ProviderModelDefaults::class)->set($provider, Capability::Embeddings, $model);
        $this->registerDynamic($provider);

        $this->expectException(PrivacyViolationException::class);

        $this->manager()->embed(new EmbeddingRequestData(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            inputs: ['x'],
            requiredPrivacyLevel: PrivacyLevel::Local,
        ));
    }

    public function test_batch_size_splits_inputs_and_preserves_order(): void
    {
        Embeddings::fake();

        $provider = $this->makeProvider();
        $model = $this->makeEmbeddingModel($provider, 'embed', 3);
        $this->app->make(ProviderModelDefaults::class)->set($provider, Capability::Embeddings, $model);
        $this->registerDynamic($provider);

        $result = $this->manager()->embed(new EmbeddingRequestData(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            inputs: ['a', 'b', 'c', 'd', 'e'],
            batchSize: 2,
        ));

        $this->assertCount(5, $result->embeddings);
        $this->assertSame(3, $result->dimensions);
        // Three batches ran (2 + 2 + 1): the usage is aggregated.
        $this->assertArrayHasKey('totalTokens', $result->usage);
    }

    /**
     * A cloud system provider WITH an embedding model, used to prove that a
     * local_only anchor never degrades to it.
     */
    private function makeCloudEmbeddingProvider(): AiProvider
    {
        /** @var ProviderModelDefaults $defaults */
        $defaults = app(ProviderModelDefaults::class);

        $cloud = AiProvider::createValidated([
            'name' => 'Cloud',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-test-1234',
            'module' => 'assistant',
            'privacy_level' => 'cloud',
            'fallback_policy' => 'same_privacy_level',
            'scope' => 'global',
        ]);

        $model = AiProviderModel::create([
            'ai_provider_id' => $cloud->id,
            'model' => 'text-embedding-3-small',
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => 1536,
        ]);

        $defaults->set($cloud, Capability::Embeddings, $model);

        return $cloud;
    }
}
