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
 * resolveForCapability() must honour the same user → tenant → system
 * precedence as resolve(), even when the anchor lacks the capability.
 */
final class ProviderCapabilityPrecedenceTest extends TestCase
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

    public function test_user_anchor_without_capability_falls_to_system_in_order(): void
    {
        // User provider wins the default resolution (the anchor) but has NO
        // embeddings model; the system provider does.
        $user = $this->makeProvider(['scope' => ['user' => 1]]);
        $system = $this->makeProvider(['scope' => 'global']);
        $this->addEmbeddingDefault($system, 'system-embed');

        $resolved = $this->app->make(ProviderResolver::class)
            ->resolveForCapability('assistant', Capability::Embeddings, userId: 1);

        $this->assertNotNull($resolved);
        $this->assertSame($system->id, $resolved->id);
    }

    public function test_anchor_with_capability_is_preferred_over_lower_scopes(): void
    {
        // Both have embeddings: the user anchor wins over the system one.
        $user = $this->makeProvider(['scope' => ['user' => 1]]);
        $system = $this->makeProvider(['scope' => 'global']);
        $this->addEmbeddingDefault($user, 'user-embed');
        $this->addEmbeddingDefault($system, 'system-embed');

        $resolved = $this->app->make(ProviderResolver::class)
            ->resolveForCapability('assistant', Capability::Embeddings, userId: 1);

        $this->assertNotNull($resolved);
        $this->assertSame($user->id, $resolved->id);
    }

    public function test_explicit_pinned_provider_requires_accessibility(): void
    {
        $global = $this->makeProvider(['scope' => 'global']);
        $this->addEmbeddingDefault($global);

        $resolver = $this->app->make(ProviderResolver::class);

        // Global pinned provider is accessible to anyone.
        $this->assertNotNull(
            $resolver->resolveExplicitForCapability($global->id, 'assistant', Capability::Embeddings, userId: 1),
        );

        // A personal provider of user 2 is not accessible to user 1.
        $personal = $this->makeProvider(['scope' => ['user' => 2]]);
        $this->addEmbeddingDefault($personal);

        $this->assertNull(
            $resolver->resolveExplicitForCapability($personal->id, 'assistant', Capability::Embeddings, userId: 1),
        );
        $this->assertNotNull(
            $resolver->resolveExplicitForCapability($personal->id, 'assistant', Capability::Embeddings, userId: 2),
        );
    }

    public function test_pinned_provider_without_capability_is_rejected(): void
    {
        $provider = $this->makeProvider(['scope' => 'global']);

        $this->assertNull(
            $this->app->make(ProviderResolver::class)
                ->resolveExplicitForCapability($provider->id, 'assistant', Capability::Embeddings, userId: 1),
        );
    }
}
