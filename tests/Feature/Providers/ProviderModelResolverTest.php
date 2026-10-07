<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Exceptions\NoProviderModelException;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Models\AiProviderModelDefault;
use HomeSide\AiAgents\Providers\ProviderModelDefaults;
use HomeSide\AiAgents\Providers\ProviderModelResolver;
use HomeSide\AiAgents\Tests\TestCase;
use Illuminate\Database\QueryException;

/**
 * ProviderModelResolver and the per-capability defaults source of truth.
 */
final class ProviderModelResolverTest extends TestCase
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

    /**
     * @param  list<string>  $capabilities
     */
    private function makeModel(AiProvider $provider, string $model, array $capabilities, array $overrides = []): AiProviderModel
    {
        return AiProviderModel::create(array_merge([
            'ai_provider_id' => $provider->id,
            'model' => $model,
            'enabled' => true,
            'capabilities_override' => $capabilities,
        ], $overrides));
    }

    private function resolver(): ProviderModelResolver
    {
        return $this->app->make(ProviderModelResolver::class);
    }

    public function test_resolve_default_returns_the_capability_default(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'gpt-4o', ['text', 'tools', 'structured_output']);

        $this->app->make(ProviderModelDefaults::class)->set($provider, Capability::Text, $model);

        $resolved = $this->resolver()->resolveDefault($provider, Capability::Text);

        $this->assertSame($model->id, $resolved->id);
    }

    public function test_unique_constraint_prevents_two_defaults_per_capability(): void
    {
        $provider = $this->makeProvider();
        $a = $this->makeModel($provider, 'gpt-4o', ['text']);
        $b = $this->makeModel($provider, 'gpt-4o-mini', ['text']);

        $defaults = $this->app->make(ProviderModelDefaults::class);
        $defaults->set($provider, Capability::Text, $a);

        // updateOrCreate means a second set replaces, never duplicates.
        $defaults->set($provider, Capability::Text, $b);

        $this->assertSame(1, AiProviderModelDefault::query()->where('capability', 'text')->count());
        $this->assertSame($b->id, AiProviderModelDefault::query()->where('capability', 'text')->value('ai_provider_model_id'));
    }

    public function test_database_rejects_a_duplicate_default_row(): void
    {
        $provider = $this->makeProvider();
        $a = $this->makeModel($provider, 'gpt-4o', ['text']);
        $b = $this->makeModel($provider, 'gpt-4o-mini', ['text']);

        AiProviderModelDefault::create([
            'ai_provider_id' => $provider->id,
            'ai_provider_model_id' => $a->id,
            'capability' => 'text',
        ]);

        $this->expectException(QueryException::class);

        AiProviderModelDefault::create([
            'ai_provider_id' => $provider->id,
            'ai_provider_model_id' => $b->id,
            'capability' => 'text',
        ]);
    }

    public function test_same_model_can_be_default_for_text_and_embeddings_when_it_supports_both(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'multi', ['text', 'embeddings']);

        $defaults = $this->app->make(ProviderModelDefaults::class);
        $defaults->set($provider, Capability::Text, $model);
        $defaults->set($provider, Capability::Embeddings, $model);

        $this->assertSame(2, AiProviderModelDefault::query()->where('ai_provider_id', $provider->id)->count());
    }

    public function test_setting_a_default_for_a_non_routable_capability_is_rejected(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'gpt-4o', ['text', 'tools']);

        $this->expectException(NoProviderModelException::class);

        $this->app->make(ProviderModelDefaults::class)->set($provider, Capability::Tools, $model);
    }

    public function test_setting_a_model_from_another_provider_is_rejected(): void
    {
        $providerA = $this->makeProvider();
        $providerB = $this->makeProvider();
        $model = $this->makeModel($providerB, 'gpt-4o', ['text']);

        $this->expectException(NoProviderModelException::class);

        $this->app->make(ProviderModelDefaults::class)->set($providerA, Capability::Text, $model);
    }

    public function test_disabled_model_is_rejected(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'gpt-4o', ['text'], ['enabled' => false]);

        $this->expectException(NoProviderModelException::class);

        $this->resolver()->resolveExplicit($provider, $model->id, Capability::Text);
    }

    public function test_model_without_the_capability_is_rejected(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'text-only', ['text']);

        $this->expectException(NoProviderModelException::class);

        $this->resolver()->resolveExplicit($provider, $model->id, Capability::Embeddings);
    }

    public function test_explicit_model_from_another_provider_is_rejected(): void
    {
        $providerA = $this->makeProvider();
        $providerB = $this->makeProvider();
        $model = $this->makeModel($providerB, 'gpt-4o', ['text']);

        $this->expectException(NoProviderModelException::class);

        $this->resolver()->resolveExplicit($providerA, $model->id, Capability::Text);
    }

    public function test_required_capabilities_are_enforced_on_top_of_primary(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider, 'text-only', ['text']);

        $this->expectException(NoProviderModelException::class);
        $this->expectExceptionMessage('structured_output');

        $this->resolver()->resolveExplicit($provider, $model->id, Capability::Text, [Capability::StructuredOutput]);
    }

    public function test_missing_default_throws(): void
    {
        $provider = $this->makeProvider();

        $this->expectException(NoProviderModelException::class);

        $this->resolver()->resolveDefault($provider, Capability::Embeddings);
    }

    public function test_resolve_for_agent_falls_back_to_legacy_provider_model(): void
    {
        $provider = $this->makeProvider(['model' => 'gpt-legacy']);

        $model = $this->resolver()->resolveForAgent($provider, null, null);

        $this->assertSame('gpt-legacy', $model->model);
    }

    public function test_resolve_for_agent_prefers_the_stored_text_default(): void
    {
        $provider = $this->makeProvider(['model' => 'gpt-legacy']);
        $model = $this->makeModel($provider, 'gpt-default', ['text']);
        $this->app->make(ProviderModelDefaults::class)->set($provider, Capability::Text, $model);

        $resolved = $this->resolver()->resolveForAgent($provider, null, null);

        $this->assertSame('gpt-default', $resolved->model);
    }

    public function test_resolve_for_agent_uses_explicit_provider_model_id_over_legacy(): void
    {
        $provider = $this->makeProvider(['model' => 'gpt-legacy']);
        $pinned = $this->makeModel($provider, 'gpt-pinned', ['text']);

        $resolved = $this->resolver()->resolveForAgent($provider, $pinned->id, 'gpt-legacy');

        $this->assertSame('gpt-pinned', $resolved->model);
    }
}
