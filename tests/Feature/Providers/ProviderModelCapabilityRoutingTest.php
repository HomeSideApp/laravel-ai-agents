<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Providers;

use HomeSide\AiAgents\AgentRegistry;
use HomeSide\AiAgents\AiAgentManager;
use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Embeddings\EmbeddingManager;
use HomeSide\AiAgents\Embeddings\EmbeddingRequestData;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Models\AiAgent;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Providers\ProviderModelDefaults;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use HomeSide\AiAgents\Tests\Unit\Fixtures\DummyAgent;
use HomeSide\AiAgents\Tests\Unit\Fixtures\SdkBoundaryAgent;
use Laravel\Ai\Embeddings;

/**
 * Acceptance: one provider, one endpoint, two capabilities routed to two
 * different models. A text agent uses qwen3; embeddings use
 * nomic-embed-text — same endpoint, credentials, privacy and scope.
 */
final class ProviderModelCapabilityRoutingTest extends TestCase
{
    private TestUser $user;

    private DummyAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // The local ollama provider uses a private host; allow it explicitly.
        config(['ai-agents.endpoint_policy.mode' => 'self-hosted']);

        $this->user = TestUser::create(['name' => 'Routing', 'email' => 'routing@example.com']);
        $this->agent = new DummyAgent;

        $this->app->instance(DummyAgent::class, $this->agent);
        $this->app->make(AgentRegistry::class)->register($this->agent->key(), $this->agent::class);

        AiAgent::createValidated([
            'key' => $this->agent->key(),
            'module' => $this->agent->module(),
            'label' => 'Generator',
            'platform_prompt' => 'You generate things.',
        ]);
    }

    public function test_text_and_embeddings_use_different_models_on_the_same_provider(): void
    {
        Embeddings::fake();

        // Single local provider: text + embeddings models.
        $provider = AiProvider::createValidated([
            'name' => 'Local',
            'type' => 'ollama',
            'base_url' => 'http://localhost:11434',
            'model' => 'qwen3',
            'api_key' => 'local-key',
            'module' => $this->agent->module(),
            'privacy_level' => 'local',
            'fallback_policy' => 'local_only',
            'scope' => 'global',
        ]);

        $textModel = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'qwen3',
            'enabled' => true,
            'capabilities_override' => ['text', 'tools', 'structured_output'],
        ]);

        $embeddingModel = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'nomic-embed-text',
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => 768,
        ]);

        $defaults = $this->app->make(ProviderModelDefaults::class);
        $defaults->set($provider, Capability::Text, $textModel);
        $defaults->set($provider, Capability::Embeddings, $embeddingModel);

        // 1. The agent manager uses the Text default (qwen3).
        $sdkAgent = new SdkBoundaryAgent;
        $sdkAgent->reply = 'routed';
        $this->app->instance(SdkBoundaryAgent::class, $sdkAgent);
        $this->app->make(AgentRegistry::class)->register($this->agent->key(), SdkBoundaryAgent::class);

        $textResult = $this->app->make(AiAgentManager::class)->run(
            $this->agent->key(),
            new AiExecutionContextData(userId: $this->user->id),
            'Hola',
        );

        $this->assertSame('qwen3', $textResult->model);

        // 2. The embedding manager uses the Embeddings default
        // (nomic-embed-text) on the same provider.
        $embedding = $this->app->make(EmbeddingManager::class)->embed(new EmbeddingRequestData(
            userId: $this->user->id,
            tenantId: null,
            module: $this->agent->module(),
            inputs: ['Ana prefiere leche sin lactosa.'],
        ));

        $this->assertSame('nomic-embed-text', $embedding->model);
        $this->assertSame($provider->id, $embedding->providerId);
        $this->assertCount(1, $embedding->embeddings);
    }
}
