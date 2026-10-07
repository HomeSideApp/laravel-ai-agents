<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Configuration;

use HomeSide\AiAgents\AgentRegistry;
use HomeSide\AiAgents\AiAgentManager;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Models\AiAgent;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Models\ModuleAiConfiguration;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use HomeSide\AiAgents\Tests\Unit\Fixtures\DummyAgent;
use HomeSide\AiAgents\Tests\Unit\Fixtures\SdkBoundaryAgent;

/**
 * Model precedence in AiAgentManager: an explicit provider_model_id on the
 * user's ModuleAiConfiguration wins over the stored default and the legacy
 * model string.
 */
final class ConfiguredProviderModelPrecedenceTest extends TestCase
{
    private TestUser $user;

    private DummyAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = TestUser::create(['name' => 'Prec', 'email' => 'prec@example.com']);
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

    public function test_configured_provider_model_id_wins_over_default(): void
    {
        $provider = AiProvider::createValidated([
            'name' => 'P',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-test-1234',
            'module' => $this->agent->module(),
            'privacy_level' => 'local',
            'fallback_policy' => 'allow_cloud',
            'scope' => ['user' => $this->user->id],
        ]);

        $pinned = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'gpt-pinned',
            'enabled' => true,
            'capabilities_override' => ['text'],
        ]);

        AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'gpt-default',
            'enabled' => true,
            'capabilities_override' => ['text'],
        ]);

        $provider->modelDefaults()->create([
            'ai_provider_model_id' => AiProviderModel::query()->where('model', 'gpt-default')->value('id'),
            'capability' => 'text',
        ]);

        ModuleAiConfiguration::create([
            'user_id' => $this->user->id,
            'module' => $this->agent->module(),
            'agent_name' => 'generator',
            'label' => 'Personal',
            'system_prompt' => 'Personal prompt.',
            'ai_provider_id' => $provider->id,
            'provider_model_id' => $pinned->id,
        ]);

        $sdkAgent = new SdkBoundaryAgent;
        $sdkAgent->reply = 'ok';
        $this->app->instance(SdkBoundaryAgent::class, $sdkAgent);
        $this->app->make(AgentRegistry::class)->register($this->agent->key(), SdkBoundaryAgent::class);

        $result = $this->app->make(AiAgentManager::class)->run(
            $this->agent->key(),
            new AiExecutionContextData(userId: $this->user->id),
            'Hola',
        );

        $this->assertSame('gpt-pinned', $result->model);
    }
}
