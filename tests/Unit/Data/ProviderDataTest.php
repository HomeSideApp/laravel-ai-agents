<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Data;

use HomeSide\AiAgents\Data\CreateAiProviderData;
use HomeSide\AiAgents\Data\UpdateAiProviderData;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Providers\ProviderTestData;
use HomeSide\AiAgents\Providers\ResolvedProviderData;
use HomeSide\AiAgents\Synchronizer\DiagnoseResultData;
use HomeSide\AiAgents\Synchronizer\SyncResultData;
use HomeSide\AiAgents\Tests\TestCase;

/**
 * Unit tests for the standardised DTOs.
 */
final class ProviderDataTest extends TestCase
{
    public function test_create_ai_provider_data_from_array_parses_all_fields(): void
    {
        $dto = CreateAiProviderData::fromArray([
            'name' => 'OpenAI Prod',
            'type' => 'openai',
            'driver' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-test-123',
            'module' => 'assistant',
            'enabled' => true,
            'configuration' => ['timeout' => 30],
            'privacy_level' => 'cloud',
            'fallback_policy' => 'same_privacy_level',
            'family' => 'gpt-4o',
            'description' => 'GPT-4o model',
            'attachment' => true,
            'reasoning' => false,
            'tool_call' => true,
            'structured_output' => true,
            'temperature' => true,
            'open_weights' => false,
            'modalities_input' => ['text', 'image'],
            'modalities_output' => ['text'],
            'context_window' => 128000,
            'max_input_tokens' => 100000,
            'max_output_tokens' => 16384,
            'cost_input' => '2.500000',
            'cost_output' => '10.000000',
            'cost_cache_read' => '1.250000',
            'cost_cache_write' => '0.000000',
        ]);

        $this->assertSame('OpenAI Prod', $dto->name);
        $this->assertSame('openai', $dto->type);
        $this->assertSame('openai', $dto->driver);
        $this->assertSame('https://api.openai.com/v1', $dto->base_url);
        $this->assertSame('gpt-4o', $dto->model);
        $this->assertSame('sk-test-123', $dto->api_key);
        $this->assertSame('assistant', $dto->module);
        $this->assertTrue($dto->enabled);
        $this->assertSame('cloud', $dto->privacy_level);
        $this->assertSame('gpt-4o', $dto->family);
        $this->assertTrue($dto->attachment);
        $this->assertFalse($dto->reasoning);
        $this->assertTrue($dto->tool_call);
        $this->assertTrue($dto->structured_output);
        $this->assertTrue($dto->temperature);
        $this->assertFalse($dto->open_weights);
        $this->assertSame(['text', 'image'], $dto->modalities_input);
        $this->assertSame(['text'], $dto->modalities_output);
        $this->assertSame(128000, $dto->context_window);
        $this->assertSame(100000, $dto->max_input_tokens);
        $this->assertSame(16384, $dto->max_output_tokens);
        $this->assertSame('2.500000', $dto->cost_input);
        $this->assertSame('10.000000', $dto->cost_output);
    }

    public function test_create_ai_provider_data_from_array_defaults_catalog_fields_to_false(): void
    {
        $dto = CreateAiProviderData::fromArray([
            'name' => 'Test',
            'base_url' => 'https://example.com',
            'model' => 'test',
            'api_key' => 'sk-test',
        ]);

        $this->assertSame('openai-compatible', $dto->type);
        $this->assertSame('general', $dto->module);
        $this->assertTrue($dto->enabled);
        $this->assertFalse($dto->attachment);
        $this->assertFalse($dto->reasoning);
        $this->assertFalse($dto->tool_call);
        $this->assertFalse($dto->structured_output);
        $this->assertFalse($dto->temperature);
        $this->assertFalse($dto->open_weights);
        $this->assertNull($dto->family);
        $this->assertNull($dto->context_window);
        $this->assertNull($dto->cost_input);
    }

    public function test_create_ai_provider_data_to_array_excludes_nulls(): void
    {
        $dto = CreateAiProviderData::fromArray([
            'name' => 'Test',
            'base_url' => 'https://example.com',
            'model' => 'test',
            'api_key' => 'sk-test',
        ]);

        $array = $dto->toArray();

        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('enabled', $array);
        $this->assertArrayNotHasKey('family', $array);
        $this->assertArrayNotHasKey('description', $array);
        $this->assertArrayNotHasKey('context_window', $array);
    }

    public function test_provider_data_emits_multiple_modules_without_the_legacy_field(): void
    {
        $dto = CreateAiProviderData::fromArray([
            'name' => 'Shared',
            'base_url' => 'https://example.com',
            'model' => 'test',
            'api_key' => 'sk-test',
            'modules' => ['recipes', 'economy'],
        ]);

        $this->assertSame('recipes', $dto->module);
        $this->assertSame(['recipes', 'economy'], $dto->toArray()['modules']);
        $this->assertArrayNotHasKey('module', $dto->toArray());
    }

    public function test_update_ai_provider_data_api_key_is_optional(): void
    {
        $dto = UpdateAiProviderData::fromArray([
            'name' => 'Test',
            'base_url' => 'https://example.com',
            'model' => 'test',
        ]);

        $this->assertNull($dto->api_key);
    }

    public function test_update_ai_provider_data_to_array_excludes_api_key(): void
    {
        $dto = new UpdateAiProviderData(
            name: 'Test',
            type: 'openai',
            driver: null,
            base_url: 'https://example.com',
            model: 'test',
            api_key: null,
        );

        $array = $dto->toArray();

        $this->assertArrayNotHasKey('api_key', $array);
    }

    public function test_provider_test_data_ok_factory(): void
    {
        $result = ProviderTestData::ok(150, 'Hello');

        $this->assertSame('ok', $result->status);
        $this->assertSame(150, $result->latencyMs);
        $this->assertSame('Hello', $result->reply);
        $this->assertNull($result->message);
    }

    public function test_provider_test_data_error_factory(): void
    {
        $result = ProviderTestData::error('Connection refused');

        $this->assertSame('error', $result->status);
        $this->assertSame(0, $result->latencyMs);
        $this->assertNull($result->reply);
        $this->assertSame('Connection refused', $result->message);
    }

    public function test_provider_test_data_to_array_includes_only_non_nullable_fields(): void
    {
        $ok = ProviderTestData::ok(100, 'OK');
        $array = $ok->toArray();

        $this->assertArrayHasKey('reply', $array);
        $this->assertArrayNotHasKey('message', $array);

        $error = ProviderTestData::error('fail');
        $array = $error->toArray();

        $this->assertArrayNotHasKey('reply', $array);
        $this->assertArrayHasKey('message', $array);
    }

    public function test_provider_test_data_to_json_returns_json_string(): void
    {
        $result = ProviderTestData::ok(200, 'OK');
        $json = $result->toJson();

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame(200, $decoded['latency_ms']);
        $this->assertSame('OK', $decoded['reply']);
    }

    public function test_sync_result_data_array_access(): void
    {
        $result = new SyncResultData(
            created: 3,
            unchanged: 1,
            orphanedKeys: ['old.agent'],
            missingRows: ['new.agent'],
        );

        $this->assertSame(3, $result['created']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame(['old.agent'], $result['orphaned_keys']);
        $this->assertSame(['new.agent'], $result['missing_rows']);
    }

    public function test_sync_result_data_to_array(): void
    {
        $result = new SyncResultData(2, 0, [], ['a', 'b']);

        $array = $result->toArray();

        $this->assertSame([
            'created' => 2,
            'unchanged' => 0,
            'orphaned_keys' => [],
            'missing_rows' => ['a', 'b'],
        ], $array);
    }

    public function test_diagnose_result_data_array_access(): void
    {
        $result = new DiagnoseResultData(
            classesWithoutRows: ['agent.key' => 'App\\Agent'],
            rowsWithoutClasses: ['old.key' => 'Old Label'],
        );

        $this->assertSame(['agent.key' => 'App\\Agent'], $result['classes_without_rows']);
        $this->assertSame(['old.key' => 'Old Label'], $result['rows_without_classes']);
    }

    public function test_diagnose_result_data_to_array(): void
    {
        $result = new DiagnoseResultData([], []);

        $array = $result->toArray();

        $this->assertSame([
            'classes_without_rows' => [],
            'rows_without_classes' => [],
        ], $array);
    }

    public function test_resolved_provider_data_holds_provider_and_dynamic_name(): void
    {
        $provider = new AiProvider([
            'name' => 'Test',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'module' => 'general',
        ]);

        $resolved = new ResolvedProviderData(
            provider: $provider,
            dynamicName: 'dynamic-ai-test-123',
        );

        $this->assertSame($provider, $resolved->provider);
        $this->assertSame('dynamic-ai-test-123', $resolved->dynamicName);
    }
}
