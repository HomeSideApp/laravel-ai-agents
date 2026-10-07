<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Exceptions\NoProviderModelException;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Providers\ProviderModelResolver;
use HomeSide\AiAgents\Providers\ProviderResolver;
use HomeSide\AiAgents\Tests\TestCase;

/**
 * A default that became stale (model disabled, missing dimensions, missing
 * capability) must not keep a provider in the routing chain when a valid
 * fallback exists — but a pinned stale model must still error.
 */
final class ProviderStaleDefaultFallbackTest extends TestCase
{
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
            'fallback_policy' => 'allow_cloud',
        ], $overrides));
    }

    /**
     * Create a default row directly, bypassing ProviderModelDefaults so an
     * invalid/stale state can be simulated (as a later model edit would).
     */
    private function forceDefault(AiProvider $provider, AiProviderModel $model): void
    {
        $provider->modelDefaults()->create([
            'ai_provider_model_id' => $model->id,
            'capability' => Capability::Embeddings->value,
        ]);
    }

    public function test_disabled_default_is_skipped_in_favour_of_a_valid_provider(): void
    {
        $stale = $this->makeProvider(['scope' => ['user' => 1]]);
        $staleModel = AiProviderModel::create([
            'ai_provider_id' => $stale->id,
            'model' => 'stale-embed',
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => 1536,
        ]);
        $this->forceDefault($stale, $staleModel);
        $staleModel->update(['enabled' => false]);

        $valid = $this->makeProvider(['scope' => 'global']);
        $validModel = AiProviderModel::create([
            'ai_provider_id' => $valid->id,
            'model' => 'valid-embed',
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => 1536,
        ]);
        $this->forceDefault($valid, $validModel);

        $resolved = $this->app->make(ProviderResolver::class)->resolveForCapability(
            'assistant',
            Capability::Embeddings,
            userId: 1,
        );

        $this->assertNotNull($resolved);
        $this->assertSame($valid->id, $resolved->id);
    }

    public function test_default_without_dimensions_is_skipped(): void
    {
        $stale = $this->makeProvider(['scope' => ['user' => 1]]);
        $staleModel = AiProviderModel::create([
            'ai_provider_id' => $stale->id,
            'model' => 'no-dims',
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => null,
        ]);
        $this->forceDefault($stale, $staleModel);

        $valid = $this->makeProvider(['scope' => 'global']);
        $validModel = AiProviderModel::create([
            'ai_provider_id' => $valid->id,
            'model' => 'valid-embed',
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => 1536,
        ]);
        $this->forceDefault($valid, $validModel);

        $resolved = $this->app->make(ProviderResolver::class)->resolveForCapability(
            'assistant',
            Capability::Embeddings,
            userId: 1,
        );

        $this->assertNotNull($resolved);
        $this->assertSame($valid->id, $resolved->id);
    }

    public function test_default_without_capability_is_skipped(): void
    {
        $stale = $this->makeProvider(['scope' => ['user' => 1]]);
        $staleModel = AiProviderModel::create([
            'ai_provider_id' => $stale->id,
            'model' => 'text-only',
            'enabled' => true,
            'capabilities_override' => ['text'],
        ]);
        $this->forceDefault($stale, $staleModel);

        $valid = $this->makeProvider(['scope' => 'global']);
        $validModel = AiProviderModel::create([
            'ai_provider_id' => $valid->id,
            'model' => 'valid-embed',
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => 1536,
        ]);
        $this->forceDefault($valid, $validModel);

        $resolved = $this->app->make(ProviderResolver::class)->resolveForCapability(
            'assistant',
            Capability::Embeddings,
            userId: 1,
        );

        $this->assertNotNull($resolved);
        $this->assertSame($valid->id, $resolved->id);
    }

    public function test_can_resolve_default_reports_false_for_stale(): void
    {
        $stale = $this->makeProvider();
        $model = AiProviderModel::create([
            'ai_provider_id' => $stale->id,
            'model' => 'stale',
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => 1536,
        ]);
        $this->forceDefault($stale, $model);
        $model->update(['enabled' => false]);

        $this->assertFalse(
            $this->app->make(ProviderModelResolver::class)->canResolveDefault($stale, Capability::Embeddings),
        );
    }

    public function test_pinned_stale_model_still_errors_instead_of_falling_back(): void
    {
        $provider = $this->makeProvider();
        $model = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'stale',
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => 1536,
        ]);
        $model->update(['enabled' => false]);

        $this->expectException(NoProviderModelException::class);

        $this->app->make(ProviderModelResolver::class)->resolveExplicit($provider, $model->id, Capability::Embeddings);
    }
}
