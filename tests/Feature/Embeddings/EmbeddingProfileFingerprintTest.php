<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Embeddings;

use HomeSide\AiAgents\Embeddings\EmbeddingProfileFingerprint;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Tests\TestCase;

/**
 * EmbeddingProfileFingerprint: deterministic, key-order insensitive, and
 * sensitive only to what defines the embedding space.
 */
final class EmbeddingProfileFingerprintTest extends TestCase
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

    private function makeModel(AiProvider $provider, array $overrides = []): AiProviderModel
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
     * @param  array<string, mixed>  $generic
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $query
     */
    private function buckets(array $generic = [], array $document = [], array $query = []): array
    {
        return ['generic' => $generic, 'document' => $document, 'query' => $query];
    }

    private function fingerprint(): EmbeddingProfileFingerprint
    {
        return $this->app->make(EmbeddingProfileFingerprint::class);
    }

    public function test_same_configuration_yields_the_same_fingerprint(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider);

        $a = $this->fingerprint()->compute($provider, $model, $this->buckets(generic: ['truncate' => true]));
        $b = $this->fingerprint()->compute($provider, $model, $this->buckets(generic: ['truncate' => true]));

        $this->assertSame($a, $b);
    }

    public function test_key_order_does_not_change_the_fingerprint(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider);

        $a = $this->fingerprint()->compute($provider, $model, $this->buckets(generic: ['a' => 1, 'b' => 2, 'c' => ['x' => 1, 'y' => 2]]));
        $b = $this->fingerprint()->compute($provider, $model, $this->buckets(generic: ['c' => ['y' => 2, 'x' => 1], 'b' => 2, 'a' => 1]));

        $this->assertSame($a, $b);
    }

    public function test_list_order_is_preserved(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider);

        $a = $this->fingerprint()->compute($provider, $model, $this->buckets(generic: ['tags' => ['a', 'b']]));
        $b = $this->fingerprint()->compute($provider, $model, $this->buckets(generic: ['tags' => ['b', 'a']]));

        $this->assertNotSame($a, $b);
    }

    public function test_different_provider_id_changes_the_fingerprint(): void
    {
        $providerA = $this->makeProvider();
        $providerB = $this->makeProvider();
        $model = $this->makeModel($providerA);

        $a = $this->fingerprint()->compute($providerA, $model, $this->buckets());
        $b = $this->fingerprint()->compute($providerB, $model, $this->buckets());

        $this->assertNotSame($a, $b);
    }

    public function test_different_endpoint_changes_the_fingerprint(): void
    {
        $providerA = $this->makeProvider(['base_url' => 'https://a.example.com/v1']);
        $providerB = $this->makeProvider(['base_url' => 'https://b.example.com/v1']);
        $model = $this->makeModel($providerA);

        $a = $this->fingerprint()->compute($providerA, $model, $this->buckets());
        $b = $this->fingerprint()->compute($providerB, $model, $this->buckets());

        $this->assertNotSame($a, $b);
    }

    public function test_endpoint_trailing_slash_is_normalised(): void
    {
        $providerA = $this->makeProvider(['base_url' => 'https://api.openai.com/v1']);
        $providerB = $this->makeProvider(['base_url' => 'https://api.openai.com/v1/']);
        // Force the same provider id so only the endpoint differs.
        $providerB->forceFill(['id' => $providerA->id]);

        $model = $this->makeModel($providerA);

        $a = $this->fingerprint()->compute($providerA, $model, $this->buckets());
        $b = $this->fingerprint()->compute($providerB, $model, $this->buckets());

        $this->assertSame($a, $b);
    }

    public function test_different_model_id_or_name_changes_the_fingerprint(): void
    {
        $provider = $this->makeProvider();
        $modelA = $this->makeModel($provider, ['model' => 'embed-a']);
        $modelB = $this->makeModel($provider, ['model' => 'embed-b']);

        $a = $this->fingerprint()->compute($provider, $modelA, $this->buckets());
        $b = $this->fingerprint()->compute($provider, $modelB, $this->buckets());

        $this->assertNotSame($a, $b);
    }

    public function test_different_dimensions_change_the_fingerprint(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider);

        $a = $this->fingerprint()->compute($provider, $model, $this->buckets());
        $model->forceFill(['embedding_dimensions' => 1024])->save();
        $b = $this->fingerprint()->compute($provider, $model->fresh(), $this->buckets());

        $this->assertNotSame($a, $b);
    }

    public function test_different_profile_version_changes_the_fingerprint(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider);

        $a = $this->fingerprint()->compute($provider, $model, $this->buckets());
        $model->forceFill(['embedding_profile_version' => 2])->save();
        $b = $this->fingerprint()->compute($provider, $model->fresh(), $this->buckets());

        $this->assertNotSame($a, $b);
    }

    public function test_any_option_bucket_change_alters_the_fingerprint(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider);

        $base = $this->fingerprint()->compute($provider, $model, $this->buckets());
        $generic = $this->fingerprint()->compute($provider, $model, $this->buckets(generic: ['truncate' => true]));
        $document = $this->fingerprint()->compute($provider, $model, $this->buckets(document: ['input_type' => 'search_document']));
        $query = $this->fingerprint()->compute($provider, $model, $this->buckets(query: ['input_type' => 'search_query']));

        $this->assertNotSame($base, $generic);
        $this->assertNotSame($base, $document);
        $this->assertNotSame($base, $query);
        $this->assertNotSame($document, $query);
    }

    public function test_api_key_display_name_and_probe_metadata_do_not_change_the_fingerprint(): void
    {
        $provider = $this->makeProvider();
        $model = $this->makeModel($provider);

        $a = $this->fingerprint()->compute($provider, $model, $this->buckets());

        $provider->forceFill(['api_key' => 'sk-rotated-different-key', 'name' => 'Renamed'])->save();
        $model->forceFill([
            'display_name' => 'Pretty name',
            'last_probed_at' => now(),
            'last_probe_status' => 'ok',
        ])->save();

        $b = $this->fingerprint()->compute($provider->fresh(), $model->fresh(), $this->buckets());

        $this->assertSame($a, $b);
    }
}
