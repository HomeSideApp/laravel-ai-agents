<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Models;

use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AiProviderValidatedWriteTest extends TestCase
{
    /**
     * A valid payload creates the row, encrypts the API key and keeps the
     * plaintext out of the database.
     */
    public function test_valid_payload_creates_provider_with_encrypted_key(): void
    {
        $provider = AiProvider::createValidated([
            'name' => 'OpenAI production',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-secret-value',
            'module' => 'assistant',
        ]);

        $this->assertDatabaseHas('ai_providers', ['id' => $provider->id, 'name' => 'OpenAI production']);

        // Model read: transparently decrypted by the encrypted cast.
        $this->assertSame('sk-secret-value', $provider->api_key);

        // Raw column read bypasses Eloquent casts: ciphertext, never plaintext.
        $stored = (string) DB::table('ai_providers')
            ->where('id', $provider->getKey())
            ->value('api_key');

        $this->assertNotSame('sk-secret-value', $stored);
        $this->assertStringNotContainsString('sk-secret-value', $stored);
        $this->assertDatabaseMissing('ai_providers', ['api_key' => 'sk-secret-value']);
    }

    /**
     * A URL pointing at a cloud metadata endpoint must be rejected before
     * any database write — this is the SSRF crown jewel.
     */
    public function test_metadata_endpoint_url_is_rejected_without_creating_row(): void
    {
        try {
            AiProvider::createValidated([
                'name' => 'Evil',
                'type' => 'openai',
                'base_url' => 'http://169.254.169.254/latest/meta-data/',
                'model' => 'gpt-4o',
                'api_key' => 'sk-x-1234',
                'module' => 'assistant',
            ]);
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('base_url', $e->errors());
        }

        $this->assertDatabaseCount('ai_providers', 0);
    }

    /**
     * A private-range URL is rejected in the default saas policy mode.
     */
    public function test_private_range_url_is_rejected_in_saas_mode(): void
    {
        config()->set('ai-agents.endpoint_policy.mode', 'saas');

        try {
            AiProvider::createValidated([
                'name' => 'Local',
                'type' => 'openai',
                'base_url' => 'http://192.168.1.50:8080/v1',
                'model' => 'llama3',
                'api_key' => 'sk-x-1234',
                'module' => 'assistant',
            ]);
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('base_url', $e->errors());
        }
    }

    /**
     * The same private URL is accepted in self-hosted mode: the policy is
     * config-driven, not hardcoded.
     */
    public function test_private_range_url_is_accepted_in_self_hosted_mode(): void
    {
        config()->set('ai-agents.endpoint_policy.mode', 'self-hosted');

        $provider = AiProvider::createValidated([
            'name' => 'Local Ollama',
            'type' => 'ollama',
            'base_url' => 'http://127.0.0.1:11434',
            'model' => 'llama3',
            'api_key' => 'sk-x-1234',
            'module' => 'assistant',
        ]);

        $this->assertDatabaseHas('ai_providers', ['id' => $provider->id]);
    }

    /**
     * An unsupported provider type is rejected by the driver map.
     */
    public function test_unsupported_provider_type_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not supported');

        AiProvider::createValidated([
            'name' => 'Mystery',
            'type' => 'carrier-pigeon',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-x-1234',
            'module' => 'assistant',
        ]);
    }

    /**
     * A module identifier with characters outside the slug alphabet is
     * rejected: the module column is matched by string everywhere.
     */
    public function test_module_with_invalid_characters_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        AiProvider::createValidated([
            'name' => 'Bad module',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-x-1234',
            'module' => 'Bad Module!',
        ]);
    }

    /**
     * The virtual 'scope' attribute routes ownership to user_id.
     */
    public function test_user_scope_resolves_to_user_id_column(): void
    {
        $provider = AiProvider::createValidated([
            'name' => 'Personal',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-x-1234',
            'module' => 'assistant',
            'scope' => ['user' => 1],
        ]);

        $this->assertSame(1, $provider->user_id);
        $this->assertTrue($provider->isUser());
    }

    /**
     * 'global' scope clears both owner columns: system-wide provider.
     */
    public function test_global_scope_clears_owner_columns(): void
    {
        $provider = AiProvider::createValidated([
            'name' => 'System',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-x-1234',
            'module' => 'assistant',
            'scope' => 'global',
        ]);

        $this->assertNull($provider->user_id);
        $this->assertTrue($provider->isGlobal());
    }

    /**
     * A malformed 'scope' shape fails validation with a clear message.
     */
    public function test_malformed_scope_shape_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid scope');

        AiProvider::createValidated([
            'name' => 'X',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-x-1234',
            'module' => 'assistant',
            'scope' => ['organisation' => 5],
        ]);
    }

    /**
     * Requesting a tenant scope while tenancy is disabled is a config error
     * surfaced as validation, not a SQL error about a missing column.
     */
    public function test_tenant_scope_without_tenancy_support_is_rejected_clearly(): void
    {
        config()->set('ai-agents.tenant.enabled', false);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Tenant support is disabled');

        AiProvider::createValidated([
            'name' => 'X',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-x-1234',
            'module' => 'assistant',
            'scope' => ['tenant' => 'h-1'],
        ]);
    }

    /**
     * updateValidated applies the same guards to updates, and lets the
     * is_default promotion flow through.
     */
    public function test_update_validated_updates_fields(): void
    {
        $provider = AiProvider::createValidated([
            'name' => 'Before',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-x-1234',
            'module' => 'assistant',
        ]);

        $provider->updateValidated(['name' => 'After', 'is_default' => true]);

        $this->assertDatabaseHas('ai_providers', ['id' => $provider->id, 'name' => 'After', 'is_default' => true]);
    }

    /**
     * markAsDefault() leaves exactly one default per user+module scope.
     */
    public function test_mark_as_default_unmarks_siblings_of_same_scope(): void
    {
        $first = AiProvider::createValidated([
            'name' => 'First',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-key-1',
            'module' => 'assistant',
            'scope' => ['user' => 1],
        ]);
        $first->update(['is_default' => true]);

        $second = AiProvider::createValidated([
            'name' => 'Second',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-key-2',
            'module' => 'assistant',
            'scope' => ['user' => 1],
        ]);

        $second->markAsDefault();

        $this->assertTrue($second->fresh()->is_default);
        $this->assertFalse($first->fresh()->is_default);
    }

    /**
     * The encrypted cast is the single source of encryption: even a raw
     * create()/save() bypassing the validated helpers cannot persist
     * plaintext.
     */
    public function test_raw_create_still_encrypts_the_api_key(): void
    {
        $provider = AiProvider::create([
            'name' => 'Raw',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'module' => 'assistant',
            'api_key' => 'sk-raw-path',
        ]);

        $stored = (string) DB::table('ai_providers')
            ->where('id', $provider->getKey())
            ->value('api_key');

        $this->assertNotSame('sk-raw-path', $stored);
        $this->assertStringNotContainsString('sk-raw-path', $stored);
        $this->assertSame('sk-raw-path', $provider->fresh()->api_key);
    }

    /**
     * Mass assignment of the api_key is allowed but cannot store plaintext:
     * the encrypted cast encrypts whatever enters the column before
     * persistence.
     */
    public function test_mass_assignment_of_api_key_is_still_encrypted(): void
    {
        $provider = new AiProvider([
            'name' => 'Mass',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'module' => 'assistant',
            'api_key' => 'sk-mass-assignment',
        ]);

        $provider->save();

        $stored = (string) DB::table('ai_providers')
            ->where('id', $provider->getKey())
            ->value('api_key');

        $this->assertStringNotContainsString('sk-mass-assignment', $stored);
        $this->assertSame('sk-mass-assignment', $provider->api_key);
    }

    /**
     * Serialisation never exposes the key: toArray/toJson skip hidden
     * attributes, closing exfiltration through API resources, logs or dumps.
     */
    public function test_serialisation_hides_the_encrypted_key(): void
    {
        $provider = AiProvider::createValidated([
            'name' => 'Secret',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-visible-check',
            'module' => 'assistant',
        ]);

        $array = $provider->toArray();
        $json = $provider->toJson();

        $this->assertArrayNotHasKey('api_key', $array);
        $this->assertStringNotContainsString('sk-visible-check', $json);
    }

    /**
     * Catalog-aligned spec columns (family, capabilities, modalities,
     * limits, costs) persist through validated writes with their casts.
     */
    public function test_valid_payload_persists_catalog_aligned_specs(): void
    {
        $provider = AiProvider::createValidated([
            'name' => 'OpenAI production',
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-spec-fields',
            'module' => 'assistant',
            'family' => 'gpt-4o',
            'description' => 'Multimodal flagship model',
            'attachment' => true,
            'reasoning' => false,
            'tool_call' => true,
            'structured_output' => true,
            'temperature' => true,
            'open_weights' => false,
            'modalities_input' => ['text', 'image'],
            'modalities_output' => ['text'],
            'context_window' => 128000,
            'max_output_tokens' => 16384,
            'cost_input' => '2.5',
            'cost_output' => '10',
        ]);

        $this->assertTrue($provider->attachment);
        $this->assertTrue($provider->tool_call);
        $this->assertFalse($provider->open_weights);
        $this->assertSame(['text', 'image'], $provider->modalities_input);
        $this->assertSame(128000, $provider->context_window);
        $this->assertSame('2.500000', $provider->cost_input);
        $this->assertNull($provider->cost_cache_write);

        $fresh = AiProvider::query()->findOrFail($provider->id);
        $this->assertSame('gpt-4o', $fresh->family);
        $this->assertSame(16384, $fresh->max_output_tokens);
        $this->assertSame('10.000000', $fresh->cost_output);
    }

    /**
     * Invalid catalog-aligned input is rejected: unknown modalities,
     * negative costs, non-numeric costs and non-positive token limits.
     */
    public function test_invalid_catalog_specs_are_rejected(): void
    {
        try {
            AiProvider::createValidated([
                'name' => 'Bad',
                'type' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4o',
                'api_key' => 'sk-bad-specs',
                'module' => 'assistant',
                'modalities_input' => ['text', 'hologram'],
            ]);
            $this->fail('ValidationException was not thrown for unknown modality.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('modalities_input.1', $e->errors());
        }

        try {
            AiProvider::createValidated([
                'name' => 'Bad',
                'type' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4o',
                'api_key' => 'sk-bad-specs',
                'module' => 'assistant',
                'cost_input' => -1,
            ]);
            $this->fail('ValidationException was not thrown for negative cost.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cost_input', $e->errors());
        }

        try {
            AiProvider::createValidated([
                'name' => 'Bad',
                'type' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4o',
                'api_key' => 'sk-bad-specs',
                'module' => 'assistant',
                'context_window' => 0,
            ]);
            $this->fail('ValidationException was not thrown for zero context window.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('context_window', $e->errors());
        }
    }
}
