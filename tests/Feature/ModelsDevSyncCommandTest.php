<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature;

use HomeSide\AiAgents\Models\ModelsDevModel;
use HomeSide\AiAgents\Models\ModelsDevProvider;
use HomeSide\AiAgents\ModelsDev\ModelsDevSynchronizer;
use HomeSide\AiAgents\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The models.dev sync command: catalog upserts, logo downloads and
 * idempotent re-runs against a faked API.
 */
final class ModelsDevSyncCommandTest extends TestCase
{
    /** A minimal but representative slice of the models.dev catalog. */
    private function catalogPayload(): array
    {
        return [
            'testprovider' => [
                'id' => 'testprovider',
                'env' => ['TESTPROVIDER_API_KEY'],
                'api' => 'https://api.testprovider.dev/v1',
                'name' => 'TestProvider',
                'doc' => 'https://docs.testprovider.dev',
                'models' => [
                    'testprovider/flagship-v1' => [
                        'id' => 'testprovider/flagship-v1',
                        'name' => 'Flagship V1',
                        'description' => 'A multimodal reasoning model',
                        'family' => 'flagship',
                        'attachment' => true,
                        'reasoning' => true,
                        'reasoning_options' => [['type' => 'toggle']],
                        'tool_call' => true,
                        'structured_output' => true,
                        'temperature' => true,
                        'release_date' => '2026-06-13',
                        'last_updated' => '2026-06-13',
                        'modalities' => ['input' => ['text', 'image'], 'output' => ['text']],
                        'open_weights' => false,
                        'limit' => ['context' => 1000000, 'output' => 131072],
                        'cost' => ['input' => 1.4, 'output' => 4.4, 'cache_read' => 0.26],
                    ],
                    'testprovider/free-mini' => [
                        'id' => 'testprovider/free-mini',
                        'name' => 'Free Mini',
                        'family' => 'mini',
                        'tool_call' => true,
                        'open_weights' => true,
                        'modalities' => ['input' => ['text'], 'output' => ['text']],
                        'limit' => ['context' => 32768, 'output' => 8192],
                    ],
                ],
            ],
        ];
    }

    /** Catalog + logo responses for every URL the sync touches. */
    private function fakeHttp(): void
    {
        Storage::fake('local');

        Http::fake([
            'https://models.dev/api.json' => Http::response($this->catalogPayload()),
            'https://models.dev/logos/testprovider.svg' => Http::response(
                '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>',
            ),
        ]);
    }

    public function test_sync_creates_providers_and_models(): void
    {
        $this->fakeHttp();

        $this->artisan('ai-agents:models-dev:sync')->assertSuccessful();

        $provider = ModelsDevProvider::query()->where('slug', 'testprovider')->firstOrFail();

        $this->assertSame('TestProvider', $provider->name);
        $this->assertSame(['TESTPROVIDER_API_KEY'], $provider->env_vars);
        $this->assertNotNull($provider->last_synced_at);

        $this->assertSame(2, $provider->models()->count());

        $flagship = ModelsDevModel::query()
            ->where('model_id', 'testprovider/flagship-v1')
            ->firstOrFail();

        $this->assertTrue($flagship->attachment);
        $this->assertTrue($flagship->reasoning);
        $this->assertTrue($flagship->hasModality('image'));
        $this->assertFalse($flagship->hasModality('audio'));
        $this->assertSame(1000000, $flagship->context_window);
        $this->assertSame('1.400000', $flagship->cost_input);

        // Cost absent from the payload stays null, not zero.
        $free = ModelsDevModel::query()
            ->where('model_id', 'testprovider/free-mini')
            ->firstOrFail();

        $this->assertNull($free->cost_input);
        $this->assertTrue($free->open_weights);
    }

    public function test_sync_downloads_provider_logo(): void
    {
        $this->fakeHttp();

        $this->artisan('ai-agents:models-dev:sync')->assertSuccessful();

        $provider = ModelsDevProvider::query()->where('slug', 'testprovider')->firstOrFail();

        Storage::disk('local')->assertExists('models-dev/logos/testprovider.svg');
        $this->assertSame('models-dev/logos/testprovider.svg', $provider->logo_path);
    }

    public function test_sync_is_idempotent_and_updates_in_place(): void
    {
        $this->fakeHttp();

        $this->artisan('ai-agents:models-dev:sync')->assertSuccessful();
        $this->artisan('ai-agents:models-dev:sync')->assertSuccessful();

        $this->assertSame(1, ModelsDevProvider::query()->count());
        $this->assertSame(2, ModelsDevModel::query()->count());
    }

    public function test_sync_updated_payload_overwrites_existing_values(): void
    {
        Storage::fake('local');

        $logo = '<svg xmlns="http://www.w3.org/2000/svg"/>';

        Http::fake([
            'https://models.dev/api.json' => Http::sequence()
                ->push($this->catalogPayload())
                ->push([
                    'testprovider' => [
                        'name' => 'TestProvider Renamed',
                        'models' => [
                            'testprovider/flagship-v1' => [
                                'name' => 'Flagship V1',
                                'limit' => ['context' => 2000000, 'output' => 131072],
                                'cost' => ['input' => 2.0, 'output' => 6.0],
                            ],
                        ],
                    ],
                ]),
            'https://models.dev/logos/testprovider.svg' => Http::response($logo),
        ]);

        $this->artisan('ai-agents:models-dev:sync')->assertSuccessful();
        $this->artisan('ai-agents:models-dev:sync')->assertSuccessful();

        $provider = ModelsDevProvider::query()->where('slug', 'testprovider')->firstOrFail();
        $flagship = $provider->models()->where('model_id', 'testprovider/flagship-v1')->firstOrFail();

        $this->assertSame('TestProvider Renamed', $provider->name);
        $this->assertSame(2000000, $flagship->context_window);
        $this->assertSame('2.000000', $flagship->cost_input);
    }

    public function test_sync_skips_logos_when_disabled(): void
    {
        Storage::fake('local');

        Http::fake([
            'https://models.dev/api.json' => Http::response($this->catalogPayload()),
        ]);

        $this->artisan('ai-agents:models-dev:sync', ['--skip-logos' => true])->assertSuccessful();

        $provider = ModelsDevProvider::query()->where('slug', 'testprovider')->firstOrFail();

        Storage::disk('local')->assertMissing('models-dev/logos/testprovider.svg');
        $this->assertNull($provider->logo_path);
    }

    public function test_sync_survives_logo_failure(): void
    {
        Storage::fake('local');

        Http::fake([
            'https://models.dev/api.json' => Http::response($this->catalogPayload()),
            'https://models.dev/logos/testprovider.svg' => Http::response('', 404),
        ]);

        $this->artisan('ai-agents:models-dev:sync')->assertSuccessful();

        $provider = ModelsDevProvider::query()->where('slug', 'testprovider')->firstOrFail();

        $this->assertNull($provider->logo_path);
        $this->assertSame(2, $provider->models()->count());
    }

    public function test_sync_fails_when_catalog_unreachable(): void
    {
        Storage::fake('local');

        Http::fake([
            'https://models.dev/api.json' => Http::response('', 500),
        ]);

        $this->artisan('ai-agents:models-dev:sync')->assertFailed();

        $this->assertSame(0, ModelsDevProvider::query()->count());
    }

    public function test_synchronizer_respects_configured_api_url(): void
    {
        Storage::fake('local');

        Http::fake([
            'https://mirror.example.test/api.json' => Http::response($this->catalogPayload()),
            'https://mirror.example.test/logos/testprovider.svg' => Http::response('<svg/>'),
        ]);

        $stats = (new ModelsDevSynchronizer(
            apiUrl: 'https://mirror.example.test/api.json',
            logoBaseUrl: 'https://mirror.example.test/logos',
        ))->sync();

        $this->assertSame(1, $stats['providers_created']);
        $this->assertSame(2, $stats['models_created']);
        $this->assertSame(1, $stats['logos_downloaded']);
        $this->assertSame([], $stats['errors']);
    }
}
