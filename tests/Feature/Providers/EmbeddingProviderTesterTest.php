<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Exceptions\NoProviderModelException;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Providers\EmbeddingProviderTester;
use HomeSide\AiAgents\Providers\ProviderModelDefaults;
use HomeSide\AiAgents\Tests\TestCase;
use Laravel\Ai\Embeddings;

/**
 * The embeddings tester probes a concrete model and reports dimensions.
 */
final class EmbeddingProviderTesterTest extends TestCase
{
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

    public function test_test_provider_probes_the_embeddings_default_not_the_text_model(): void
    {
        Embeddings::fake();

        $provider = $this->makeProvider(['model' => 'qwen3']);

        $embedding = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'nomic-embed-text',
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => 4,
        ]);

        $this->app->make(ProviderModelDefaults::class)->set($provider, Capability::Embeddings, $embedding);

        $result = $this->app->make(EmbeddingProviderTester::class)->testProvider($provider);

        $this->assertSame('ok', $result->status);
        $this->assertSame(4, $result->dimensions);
    }

    public function test_test_model_reports_dimensions(): void
    {
        Embeddings::fake();

        $provider = $this->makeProvider();
        $model = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'embed-8',
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => 8,
        ]);

        $result = $this->app->make(EmbeddingProviderTester::class)->testModel($provider, $model);

        $this->assertSame('ok', $result->status);
        $this->assertSame(8, $result->dimensions);
        $this->assertArrayHasKey('dimensions', $result->toArray());
    }

    public function test_test_model_rejects_a_model_from_another_provider(): void
    {
        Embeddings::fake();

        $providerA = $this->makeProvider();
        $providerB = $this->makeProvider();
        $modelB = AiProviderModel::create([
            'ai_provider_id' => $providerB->id,
            'model' => 'foreign-embed',
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => 4,
        ]);

        $this->expectException(NoProviderModelException::class);

        $this->app->make(EmbeddingProviderTester::class)->testModel($providerA, $modelB);
    }

    public function test_test_model_allows_a_model_without_detected_capability(): void
    {
        Embeddings::fake();

        $provider = $this->makeProvider();

        // No capabilities_detected/override yet: the probe is what discovers
        // the capability, so it must be allowed.
        $model = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'undetected-embed',
            'enabled' => false,
            'embedding_dimensions' => 4,
        ]);

        $result = $this->app->make(EmbeddingProviderTester::class)->testModel($provider, $model);

        $this->assertSame('ok', $result->status);
        $this->assertSame(4, $result->dimensions);
    }

    public function test_provider_without_embeddings_model_throws(): void
    {
        Embeddings::fake();

        $provider = $this->makeProvider();

        $this->expectException(NoProviderModelException::class);

        $this->app->make(EmbeddingProviderTester::class)->testProvider($provider);
    }
}
