<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Embeddings;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Embeddings\EmbeddingManager;
use HomeSide\AiAgents\Embeddings\EmbeddingProfileResolver;
use HomeSide\AiAgents\Embeddings\EmbeddingProfileVersioner;
use HomeSide\AiAgents\Embeddings\EmbeddingRequestData;
use HomeSide\AiAgents\Enums\EmbeddingPurpose;
use HomeSide\AiAgents\Enums\PrivacyLevel;
use HomeSide\AiAgents\Exceptions\NoEmbeddingProviderException;
use HomeSide\AiAgents\Exceptions\NoProviderModelException;
use HomeSide\AiAgents\Exceptions\PrivacyViolationException;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Providers\ProviderModelDefaults;
use HomeSide\AiAgents\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

/**
 * EmbeddingProfileResolver: resolves the vector-space profile without any
 * provider call, reusing the existing provider/model/privacy invariants, and
 * the purpose-specific options + fingerprint semantics.
 */
final class EmbeddingProfileResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai-agents.endpoint_policy.mode' => 'self-hosted']);
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
            'scope' => 'global',
        ], $overrides));
    }

    private function makeEmbeddingModel(AiProvider $provider, array $overrides = []): AiProviderModel
    {
        return AiProviderModel::create(array_merge([
            'ai_provider_id' => $provider->id,
            'model' => 'embed',
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => 768,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $query
     */
    private function providerWithOptions(array $generic = [], array $document = [], array $query = []): array
    {
        $provider = $this->makeProvider();
        $model = $this->makeEmbeddingModel($provider, [
            'embedding_options' => ['generic' => $generic, 'document' => $document, 'query' => $query],
        ]);
        $this->app->make(ProviderModelDefaults::class)->set($provider, Capability::Embeddings, $model);

        return [$provider, $model];
    }

    private function resolver(): EmbeddingProfileResolver
    {
        return $this->app->make(EmbeddingProfileResolver::class);
    }

    // ----- Resolution basics ----------------------------------------------

    public function test_resolves_the_default_embeddings_model_without_calling_the_provider(): void
    {
        Embeddings::fake();

        [$provider, $model] = $this->providerWithOptions();

        $profile = $this->resolver()->resolve(1, null, 'assistant');

        $this->assertSame($provider->id, $profile->providerId);
        $this->assertSame($model->id, $profile->providerModelId);
        $this->assertSame('embed', $profile->model);
        $this->assertSame(768, $profile->dimensions);
        $this->assertSame(1, $profile->profileVersion);
        $this->assertNotEmpty($profile->fingerprint);

        Embeddings::assertNothingGenerated();
    }

    public function test_resolves_a_pinned_provider_and_model(): void
    {
        Embeddings::fake();

        [$provider, $model] = $this->providerWithOptions();

        $profile = $this->resolver()->resolve(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            providerId: $provider->id,
            providerModelId: $model->id,
        );

        $this->assertSame($model->id, $profile->providerModelId);
        Embeddings::assertNothingGenerated();
    }

    public function test_rejects_a_foreign_provider_model(): void
    {
        Embeddings::fake();

        $providerA = $this->makeProvider();
        $providerB = $this->makeProvider();
        $modelA = $this->makeEmbeddingModel($providerA);
        $modelB = $this->makeEmbeddingModel($providerB);
        // providerA has its own default; the caller pins providerB's model.
        $this->app->make(ProviderModelDefaults::class)->set($providerA, Capability::Embeddings, $modelA);

        $this->expectException(NoProviderModelException::class);

        $this->resolver()->resolve(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            providerId: $providerA->id,
            providerModelId: $modelB->id,
        );
    }

    public function test_a_dimensionless_default_is_not_usable(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeEmbeddingModel($provider, ['embedding_dimensions' => null]);
        // Bypass the writer guard to simulate a legacy/corrupt default row.
        $provider->modelDefaults()->create([
            'ai_provider_model_id' => $model->id,
            'capability' => Capability::Embeddings->value,
        ]);

        // The provider is skipped (no usable default), so no provider remains.
        $this->expectException(NoEmbeddingProviderException::class);

        $this->resolver()->resolve(1, null, 'assistant');
    }

    public function test_pinning_a_dimensionless_model_is_rejected(): void
    {
        [$provider, $model] = $this->providerWithOptions();
        $model->forceFill(['embedding_dimensions' => null])->save();

        $this->expectException(NoProviderModelException::class);

        $this->resolver()->resolve(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            providerId: $provider->id,
            providerModelId: $model->id,
        );
    }

    public function test_a_disabled_default_is_not_usable(): void
    {
        [$provider, $model] = $this->providerWithOptions();
        $model->update(['enabled' => false]);

        $this->expectException(NoEmbeddingProviderException::class);

        $this->resolver()->resolve(1, null, 'assistant');
    }

    public function test_pinning_a_disabled_model_is_rejected(): void
    {
        [$provider, $model] = $this->providerWithOptions();
        $model->update(['enabled' => false]);

        $this->expectException(NoProviderModelException::class);

        $this->resolver()->resolve(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            providerId: $provider->id,
            providerModelId: $model->id,
        );
    }

    public function test_throws_when_no_provider_serves_embeddings(): void
    {
        $this->makeProvider(); // has no embeddings default

        $this->expectException(NoEmbeddingProviderException::class);

        $this->resolver()->resolve(1, null, 'assistant');
    }

    public function test_required_privacy_level_is_enforced(): void
    {
        $this->providerWithOptions(); // cloud provider

        $this->expectException(PrivacyViolationException::class);

        $this->resolver()->resolve(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            requiredPrivacyLevel: PrivacyLevel::Local,
        );
    }

    public function test_privacy_level_is_exposed_for_diagnosis(): void
    {
        [$provider] = $this->providerWithOptions();

        $profile = $this->resolver()->resolve(1, null, 'assistant');

        $this->assertSame(PrivacyLevel::Cloud, $profile->privacyLevel);
        $this->assertNotNull($provider->id);
    }

    // ----- Purpose / options ----------------------------------------------

    public function test_generic_purpose_uses_only_generic_options(): void
    {
        [$provider, $model] = $this->providerWithOptions(
            generic: ['truncate' => true],
            document: ['input_type' => 'search_document'],
            query: ['input_type' => 'search_query'],
        );

        $profile = $this->resolver()->resolve(1, null, 'assistant', EmbeddingPurpose::Generic);

        $this->assertSame(['truncate' => true], $profile->providerOptions);
        $this->assertNotNull($model->id);
        $this->assertNotNull($provider->id);
    }

    public function test_document_purpose_merges_generic_then_document(): void
    {
        [$provider] = $this->providerWithOptions(
            generic: ['truncate' => true],
            document: ['input_type' => 'search_document'],
        );

        $profile = $this->resolver()->resolve(1, null, 'assistant', EmbeddingPurpose::Document);

        $this->assertSame(['truncate' => true, 'input_type' => 'search_document'], $profile->providerOptions);
        $this->assertNotNull($provider->id);
    }

    public function test_nested_merge_is_deterministic(): void
    {
        [$provider] = $this->providerWithOptions(
            generic: ['truncate' => true, 'foo' => ['a' => 1, 'b' => 2]],
            query: ['input_type' => 'search_query', 'foo' => ['b' => 3]],
        );

        $profile = $this->resolver()->resolve(1, null, 'assistant', EmbeddingPurpose::Query);

        // Key order follows the merge (generic keys first, then new ones).
        $this->assertSame(
            ['truncate' => true, 'foo' => ['a' => 1, 'b' => 3], 'input_type' => 'search_query'],
            $profile->providerOptions,
        );
    }

    // ----- Fingerprint semantics ------------------------------------------

    public function test_document_and_query_share_the_same_fingerprint(): void
    {
        [$provider] = $this->providerWithOptions(
            document: ['input_type' => 'search_document'],
            query: ['input_type' => 'search_query'],
        );

        $document = $this->resolver()->resolve(1, null, 'assistant', EmbeddingPurpose::Document);
        $query = $this->resolver()->resolve(1, null, 'assistant', EmbeddingPurpose::Query);

        // Different effective options, same space identity.
        $this->assertNotSame($document->providerOptions, $query->providerOptions);
        $this->assertSame($document->fingerprint, $query->fingerprint);
        $this->assertNotNull($provider->id);
    }

    public function test_changing_query_options_changes_the_fingerprint_for_documents_too(): void
    {
        [$providerA] = $this->providerWithOptions(query: ['input_type' => 'v1']);
        $before = $this->resolver()->resolve(1, null, 'assistant', EmbeddingPurpose::Document)->fingerprint;

        $providerA->modelDefaults()->first()->model()->first()
            ->forceFill(['embedding_options' => ['query' => ['input_type' => 'v2']]])->save();

        $after = $this->resolver()->resolve(1, null, 'assistant', EmbeddingPurpose::Document)->fingerprint;

        $this->assertNotSame($before, $after);
    }

    public function test_a_zero_profile_version_is_rejected(): void
    {
        [$provider, $model] = $this->providerWithOptions();
        $model->fresh()->forceFill(['embedding_profile_version' => 0])->save();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be >= 1');

        $this->resolver()->resolve(1, null, 'assistant');
    }

    public function test_invalid_stored_options_fail_closed_on_resolution(): void
    {
        [$provider, $model] = $this->providerWithOptions();
        // Simulate legacy/corrupt data written outside the model guard (raw
        // SQL/import), which the saving hook cannot intercept.
        DB::table('ai_provider_models')
            ->where('id', $model->id)
            ->update(['embedding_options' => json_encode(['documment' => ['x' => 1]])]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('documment');

        $this->resolver()->resolve(1, null, 'assistant');
    }

    public function test_stored_options_with_secrets_fail_closed_on_resolution(): void
    {
        [$provider, $model] = $this->providerWithOptions();
        DB::table('ai_provider_models')
            ->where('id', $model->id)
            ->update(['embedding_options' => json_encode(['query' => ['api_key' => 'secret']])]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not allowed');

        $this->resolver()->resolve(1, null, 'assistant');
    }

    public function test_saving_options_with_a_secret_is_rejected(): void
    {
        $provider = $this->makeProvider();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not allowed');

        $this->makeEmbeddingModel($provider, [
            'embedding_options' => ['query' => ['authorization' => 'Bearer secret']],
        ]);
    }

    public function test_saving_options_with_an_unknown_purpose_is_rejected(): void
    {
        $provider = $this->makeProvider();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown embedding purpose');

        $this->makeEmbeddingModel($provider, [
            'embedding_options' => ['documment' => ['x' => 1]],
        ]);
    }

    public function test_bumping_the_profile_version_changes_the_fingerprint(): void
    {
        [$provider, $model] = $this->providerWithOptions();

        $before = $this->resolver()->resolve(1, null, 'assistant')->fingerprint;
        $version = $this->app->make(EmbeddingProfileVersioner::class)->bump($model);
        $after = $this->resolver()->resolve(1, null, 'assistant')->fingerprint;

        $this->assertSame(2, $version);
        $this->assertNotSame($before, $after);
        $this->assertNotNull($provider->id);
    }

    // ----- Manager integration --------------------------------------------

    public function test_manager_applies_purpose_options_and_returns_the_profile(): void
    {
        [$provider] = $this->providerWithOptions(
            generic: ['truncate' => true],
            query: ['input_type' => 'search_query'],
        );

        /** @var list<EmbeddingsPrompt> $prompts */
        $prompts = [];

        Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$prompts) {
            $prompts[] = $prompt;

            return array_map(fn (): array => array_fill(0, 768, 0.01), $prompt->inputs);
        });

        $result = $this->app->make(EmbeddingManager::class)->embed(new EmbeddingRequestData(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            inputs: ['buscar'],
            purpose: EmbeddingPurpose::Query,
        ));

        $this->assertSame(['truncate' => true, 'input_type' => 'search_query'], $prompts[0]->providerOptions);

        // Profile present and mirrored in the legacy fields.
        $this->assertSame($provider->id, $result->profile->providerId);
        $this->assertSame(768, $result->profile->dimensions);
        $this->assertSame(EmbeddingPurpose::Query, $result->profile->purpose);
        $this->assertSame($result->providerId, $result->profile->providerId);
        $this->assertSame($result->providerModelId, $result->profile->providerModelId);
        $this->assertSame($result->model, $result->profile->model);
        $this->assertSame($result->dimensions, $result->profile->dimensions);
        $this->assertNotEmpty($result->profile->fingerprint);
    }

    public function test_backwards_compatible_generic_request_sends_no_options_when_none_configured(): void
    {
        $this->providerWithOptions();

        /** @var list<EmbeddingsPrompt> $prompts */
        $prompts = [];

        Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$prompts) {
            $prompts[] = $prompt;

            return array_map(fn (): array => array_fill(0, 768, 0.01), $prompt->inputs);
        });

        $this->app->make(EmbeddingManager::class)->embed(new EmbeddingRequestData(
            userId: 1,
            tenantId: null,
            module: 'assistant',
            inputs: ['hola'],
        ));

        $this->assertSame([], $prompts[0]->providerOptions);
    }
}
