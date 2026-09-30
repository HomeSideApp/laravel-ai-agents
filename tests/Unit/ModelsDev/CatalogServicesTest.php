<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\ModelsDev;

use HomeSide\AiAgents\Database\Factories\ModelsDevModelFactory;
use HomeSide\AiAgents\Database\Factories\ModelsDevProviderFactory;
use HomeSide\AiAgents\ModelsDev\CatalogPrefill;
use HomeSide\AiAgents\ModelsDev\CatalogQuery;
use HomeSide\AiAgents\Tests\TestCase;

/**
 * CatalogQuery (server-side filters + pagination) and CatalogPrefill
 * (form autofill suggestions) over the models.dev catalog tables.
 */
final class CatalogServicesTest extends TestCase
{
    private function seedCatalog(): void
    {
        $openai = ModelsDevProviderFactory::new()->create([
            'slug' => 'openai',
            'name' => 'OpenAI',
            'api_url' => 'https://api.openai.com/v1',
        ]);

        $ollama = ModelsDevProviderFactory::new()->create([
            'slug' => 'ollama',
            'name' => 'Ollama',
            'api_url' => 'http://localhost:11434/v1',
        ]);

        ModelsDevModelFactory::new()->create([
            'models_dev_provider_id' => $openai->id,
            'model_id' => 'gpt-4o',
            'name' => 'GPT-4o',
            'family' => 'gpt-4o',
            'description' => 'Multimodal flagship model',
            'attachment' => true,
            'tool_call' => true,
            'structured_output' => true,
            'reasoning' => false,
            'modalities_input' => ['text', 'image'],
            'cost_input' => '2.500000',
            'cost_output' => '10.000000',
            'release_date' => '2024-05-13',
        ]);

        ModelsDevModelFactory::new()->create([
            'models_dev_provider_id' => $openai->id,
            'model_id' => 'gpt-o3',
            'name' => 'GPT-o3',
            'family' => 'o3',
            'description' => 'Reasoning model',
            'tool_call' => true,
            'reasoning' => true,
            'modalities_input' => ['text'],
            'cost_input' => '10.000000',
            'cost_output' => '40.000000',
            'release_date' => '2025-01-01',
        ]);

        ModelsDevModelFactory::new()->create([
            'models_dev_provider_id' => $ollama->id,
            'model_id' => 'llama3:8b',
            'name' => 'Llama 3 8B',
            'family' => 'llama3',
            'open_weights' => true,
            'tool_call' => false,
            'modalities_input' => ['text'],
            'cost_input' => null,
            'cost_output' => null,
            'release_date' => '2024-04-18',
        ]);
    }

    // ----- CatalogQuery::providers() --------------------------------------

    public function test_providers_paginates_with_model_counts(): void
    {
        $this->seedCatalog();

        $page = app(CatalogQuery::class)->providers(perPage: 2);

        $this->assertSame(2, $page->total());
        $this->assertSame(1, $page->lastPage());

        $openai = collect($page->items())->firstWhere('slug', 'openai');
        $this->assertNotNull($openai);
        $this->assertSame(2, $openai->models_count);
    }

    public function test_providers_search_filters_by_name_and_slug(): void
    {
        $this->seedCatalog();

        $page = app(CatalogQuery::class)->providers(search: 'open');

        $this->assertSame(1, $page->total());

        $page = app(CatalogQuery::class)->providers(search: 'ollam');

        $this->assertSame(1, $page->total());
    }

    public function test_providers_escapes_like_wildcards(): void
    {
        ModelsDevProviderFactory::new()->create(['slug' => 'literal_star', 'name' => 'literal_star']);

        // "_" is a LIKE wildcard: matching "literal star" must not match.
        $page = app(CatalogQuery::class)->providers(search: 'literal star');

        $this->assertSame(0, $page->total());
    }

    // ----- CatalogQuery::models() -----------------------------------------

    public function test_models_filters_by_provider_slug(): void
    {
        $this->seedCatalog();

        $page = app(CatalogQuery::class)->models(providerSlug: 'openai');

        $this->assertSame(2, $page->total());

        $page = app(CatalogQuery::class)->models(providerSlug: 'ollama');

        $this->assertSame(1, $page->total());
    }

    public function test_models_search_spans_id_name_description_and_family(): void
    {
        $this->seedCatalog();

        $query = app(CatalogQuery::class);

        $this->assertSame(2, $query->models(search: 'gpt')->total());
        $this->assertSame(1, $query->models(search: 'flagship')->total());
        $this->assertSame(1, $query->models(search: 'llama')->total());
        $this->assertSame(1, $query->models(search: 'O3')->total());
    }

    public function test_models_capability_filters(): void
    {
        $this->seedCatalog();

        $query = app(CatalogQuery::class);

        $this->assertSame(2, $query->models(toolCall: true)->total());
        $this->assertSame(1, $query->models(toolCall: false)->total());
        $this->assertSame(1, $query->models(reasoning: true)->total());
        $this->assertSame(1, $query->models(attachments: true)->total());
        $this->assertSame(1, $query->models(openWeights: true)->total());
    }

    public function test_models_modality_filter_matches_json_array(): void
    {
        $this->seedCatalog();

        $page = app(CatalogQuery::class)->models(withModality: 'image');

        $this->assertSame(1, $page->total());
        $this->assertSame('gpt-4o', $page->items()[0]->model_id);
    }

    public function test_models_max_input_cost_treats_unpriced_as_free(): void
    {
        $this->seedCatalog();

        $page = app(CatalogQuery::class)->models(maxInputCost: 5.0);

        // llama3:8b (no price = free) + gpt-4o (2.5) match; gpt-o3 (10) not.
        $this->assertSame(2, $page->total());

        $freeOnly = app(CatalogQuery::class)->models(freeOnly: true);

        $this->assertSame(1, $freeOnly->total());
        $this->assertSame('llama3:8b', $freeOnly->items()[0]->model_id);
    }

    public function test_models_ordering_variants(): void
    {
        $this->seedCatalog();

        $query = app(CatalogQuery::class);

        $cheapest = $query->models(orderBy: 'cheapest_input')->items();
        $this->assertSame('llama3:8b', $cheapest[0]->model_id);
        $this->assertSame('gpt-4o', $cheapest[1]->model_id);

        $newest = $query->models(orderBy: 'newest')->items();
        $this->assertSame('gpt-o3', $newest[0]->model_id);
    }

    public function test_models_eager_loads_provider(): void
    {
        $this->seedCatalog();

        $page = app(CatalogQuery::class)->models(providerSlug: 'openai');

        $this->assertTrue($page->items()[0]->relationLoaded('provider'));
        $this->assertSame('OpenAI', $page->items()[0]->provider->name);
    }

    public function test_models_pagination_caps_per_page(): void
    {
        $this->seedCatalog();

        $page = app(CatalogQuery::class)->models(perPage: 9999);

        $this->assertSame(3, $page->total());
        $this->assertSame(100, $page->perPage());
    }

    // ----- CatalogPrefill --------------------------------------------------

    public function test_prefill_builds_suggestion_from_provider_and_model(): void
    {
        $this->seedCatalog();

        $payload = app(CatalogPrefill::class)->prefill('openai', 'gpt-4o');

        $this->assertNotNull($payload);
        $this->assertSame('OpenAI', $payload['name']);
        $this->assertSame('openai', $payload['type']);
        $this->assertSame('https://api.openai.com/v1', $payload['base_url']);
        $this->assertSame('gpt-4o', $payload['model']);
        $this->assertSame('cloud', $payload['privacy_level']);

        // Prefill keys are 1:1 with the ai_providers fillable columns.
        $this->assertSame(128000, $payload['context_window']);
        $this->assertSame('2.500000', $payload['cost_input']);
        $this->assertTrue($payload['tool_call']);
        $this->assertFalse($payload['reasoning']);
        $this->assertSame(['text', 'image'], $payload['modalities_input']);
    }

    public function test_prefill_falls_back_to_openai_compatible_for_unknown_slug(): void
    {
        $provider = ModelsDevProviderFactory::new()->create(['slug' => 'obscure-vendor']);
        ModelsDevModelFactory::new()->create([
            'models_dev_provider_id' => $provider->id,
            'model_id' => 'obscure-xl',
        ]);

        $payload = app(CatalogPrefill::class)->prefill('obscure-vendor', 'obscure-xl');

        $this->assertNotNull($payload);
        $this->assertSame('openai-compatible', $payload['type']);
    }

    public function test_prefill_returns_null_for_missing_pair(): void
    {
        $this->seedCatalog();

        $query = app(CatalogPrefill::class);

        $this->assertNull($query->prefill('openai', 'does-not-exist'));
        $this->assertNull($query->prefill('no-such-provider', 'gpt-4o'));
    }

    public function test_prefill_scopes_model_id_to_provider(): void
    {
        // model_id collides across providers; the pair must disambiguate.
        $a = ModelsDevProviderFactory::new()->create(['slug' => 'vendor-a', 'name' => 'Vendor A']);
        $b = ModelsDevProviderFactory::new()->create(['slug' => 'vendor-b', 'name' => 'Vendor B']);

        ModelsDevModelFactory::new()->create([
            'models_dev_provider_id' => $a->id,
            'model_id' => 'shared/model',
        ]);
        ModelsDevModelFactory::new()->create([
            'models_dev_provider_id' => $b->id,
            'model_id' => 'shared/model',
        ]);

        $payload = app(CatalogPrefill::class)->prefill('vendor-b', 'shared/model');

        $this->assertNotNull($payload);
        $this->assertSame('Vendor B', $payload['name']);
        $this->assertSame('shared/model', $payload['model']);
    }
}
