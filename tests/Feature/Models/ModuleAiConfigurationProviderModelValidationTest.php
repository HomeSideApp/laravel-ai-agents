<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Models;

use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Models\ModuleAiConfiguration;
use HomeSide\AiAgents\Tests\TestCase;
use Illuminate\Validation\ValidationException;

/**
 * ModuleAiConfiguration must reject incoherent provider/model pairs at write
 * time instead of failing later at runtime.
 */
final class ModuleAiConfigurationProviderModelValidationTest extends TestCase
{
    private function makeProvider(): AiProvider
    {
        return AiProvider::createValidated([
            'name' => 'P'.uniqid(),
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-test-1234',
            'module' => 'assistant',
            'privacy_level' => 'cloud',
            'fallback_policy' => 'allow_cloud',
            'scope' => 'global',
        ]);
    }

    private function baseAttributes(array $overrides = []): array
    {
        return array_merge([
            'module' => 'assistant',
            'agent_name' => 'chat',
            'label' => 'Chat',
            'system_prompt' => 'You chat.',
        ], $overrides);
    }

    public function test_provider_model_of_another_provider_is_rejected(): void
    {
        $providerA = $this->makeProvider();
        $providerB = $this->makeProvider();

        $modelB = AiProviderModel::create([
            'ai_provider_id' => $providerB->id,
            'model' => 'gpt-4o',
            'enabled' => true,
            'capabilities_override' => ['text'],
        ]);

        $this->expectException(ValidationException::class);

        ModuleAiConfiguration::createValidated($this->baseAttributes([
            'ai_provider_id' => $providerA->id,
            'provider_model_id' => $modelB->id,
        ]));
    }

    public function test_provider_model_without_provider_is_rejected(): void
    {
        $provider = $this->makeProvider();

        $model = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'gpt-4o',
            'enabled' => true,
            'capabilities_override' => ['text'],
        ]);

        $this->expectException(ValidationException::class);

        ModuleAiConfiguration::createValidated($this->baseAttributes([
            'provider_model_id' => $model->id,
        ]));
    }

    public function test_coherent_provider_and_model_is_accepted(): void
    {
        $provider = $this->makeProvider();

        $model = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'gpt-4o',
            'enabled' => true,
            'capabilities_override' => ['text'],
        ]);

        $config = ModuleAiConfiguration::createValidated($this->baseAttributes([
            'ai_provider_id' => $provider->id,
            'provider_model_id' => $model->id,
        ]));

        $this->assertSame($model->id, $config->provider_model_id);
    }
}
