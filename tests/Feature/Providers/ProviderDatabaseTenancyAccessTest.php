<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Providers\ProviderModelDefaults;
use HomeSide\AiAgents\Providers\ProviderResolver;
use HomeSide\AiAgents\Tests\TestCase;

/**
 * In database isolation the tenant connection already isolates tenants, but
 * several personal providers coexist inside one tenant database: a foreign
 * user's provider must still be rejected. Knowing a UUID never grants access.
 */
final class ProviderDatabaseTenancyAccessTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ai-agents.tenant.isolation', 'database');
        $app['config']->set('ai-agents.tenant.enabled', true);
    }

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

    private function addEmbeddingDefault(AiProvider $provider, string $model = 'embed'): AiProviderModel
    {
        $row = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => $model,
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => 1536,
        ]);

        $this->app->make(ProviderModelDefaults::class)->set($provider, Capability::Embeddings, $row);

        return $row;
    }

    private function resolver(): ProviderResolver
    {
        return $this->app->make(ProviderResolver::class);
    }

    public function test_own_personal_provider_is_accessible(): void
    {
        $a = $this->makeProvider(['scope' => ['user' => 1]]);
        $this->addEmbeddingDefault($a);

        $this->assertNotNull(
            $this->resolver()->resolveExplicitForCapability($a->id, 'assistant', Capability::Embeddings, userId: 1),
        );
    }

    public function test_shared_provider_is_accessible(): void
    {
        $shared = $this->makeProvider(['scope' => 'global']);
        $this->addEmbeddingDefault($shared);

        $this->assertNotNull(
            $this->resolver()->resolveExplicitForCapability($shared->id, 'assistant', Capability::Embeddings, userId: 1),
        );
    }

    public function test_another_users_personal_provider_is_rejected(): void
    {
        $b = $this->makeProvider(['scope' => ['user' => 2]]);
        $this->addEmbeddingDefault($b);

        // Neither user 1 nor user 3 may reach user 2's provider.
        $this->assertNull(
            $this->resolver()->resolveExplicitForCapability($b->id, 'assistant', Capability::Embeddings, userId: 1),
        );
        $this->assertNull(
            $this->resolver()->resolveExplicitForCapability($b->id, 'assistant', Capability::Embeddings, userId: 3),
        );
    }

    public function test_pinning_another_users_provider_and_model_is_rejected(): void
    {
        $b = $this->makeProvider(['scope' => ['user' => 2]]);
        $model = $this->addEmbeddingDefault($b);

        // Pinning BOTH the foreign provider and its model must not bypass the
        // provider access check.
        $this->assertNull(
            $this->resolver()->resolveExplicitForCapability(
                providerId: $b->id,
                module: 'assistant',
                capability: Capability::Embeddings,
                userId: 1,
                pinnedModelId: $model->id,
            ),
        );
    }
}
