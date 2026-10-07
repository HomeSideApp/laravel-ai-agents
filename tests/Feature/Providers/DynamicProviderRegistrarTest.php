<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Providers\DynamicProviderRegistrar;
use HomeSide\AiAgents\Providers\ProviderModelDefaults;
use HomeSide\AiAgents\Tests\TestCase;
use Illuminate\Support\Facades\Config;

/**
 * DynamicProviderRegistrar builds the SDK `models` block from stored data.
 */
final class DynamicProviderRegistrarTest extends TestCase
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
            'fallback_policy' => 'same_privacy_level',
            'scope' => 'global',
        ], $overrides));
    }

    public function test_registrar_includes_text_and_embeddings_defaults(): void
    {
        $provider = $this->makeProvider();

        $text = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'gpt-4o',
            'enabled' => true,
            'capabilities_override' => ['text'],
        ]);

        $embeddings = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'text-embedding-3-small',
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => 1536,
        ]);

        $defaults = $this->app->make(ProviderModelDefaults::class);
        $defaults->set($provider, Capability::Text, $text);
        $defaults->set($provider, Capability::Embeddings, $embeddings);

        $name = $this->app->make(DynamicProviderRegistrar::class)->register($provider);

        $models = Config::get("ai.providers.{$name}.models");

        $this->assertSame('gpt-4o', $models['text']['default']);
        $this->assertSame('text-embedding-3-small', $models['embeddings']['default']);
        $this->assertSame(1536, $models['embeddings']['dimensions']);
        $this->assertArrayNotHasKey('reranking', $models);
    }

    public function test_registrar_falls_back_to_legacy_provider_model_for_text(): void
    {
        $provider = $this->makeProvider(['model' => 'legacy-model']);

        $name = $this->app->make(DynamicProviderRegistrar::class)->register($provider);

        $this->assertSame('legacy-model', Config::get("ai.providers.{$name}.models.text.default"));
    }

    public function test_registrar_omits_embeddings_when_no_default_exists(): void
    {
        $provider = $this->makeProvider();

        $name = $this->app->make(DynamicProviderRegistrar::class)->register($provider);

        $models = Config::get("ai.providers.{$name}.models");

        $this->assertArrayNotHasKey('embeddings', $models);
    }
}
