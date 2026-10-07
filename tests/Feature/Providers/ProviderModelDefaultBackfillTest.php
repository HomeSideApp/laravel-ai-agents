<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Providers;

use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Backfill of text defaults: is_default first, then the model matching the
 * legacy AiProvider.model.
 */
final class ProviderModelDefaultBackfillTest extends TestCase
{
    private function makeProvider(string $model): AiProvider
    {
        return AiProvider::createValidated([
            'name' => 'P'.uniqid(),
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => $model,
            'api_key' => 'sk-test-1234',
            'module' => 'assistant',
            'privacy_level' => 'cloud',
            'fallback_policy' => 'same_privacy_level',
            'scope' => 'global',
        ]);
    }

    /**
     * Run the backfill migration logic in isolation (the suite already ran
     * migrations, so re-invoke the same statements against the current data).
     */
    private function runBackfill(): void
    {
        $migration = require database_path_migration('2026_10_08_000003_backfill_text_provider_model_defaults.php');
        $migration->up();
    }

    public function test_backfill_uses_is_default_when_present(): void
    {
        $provider = $this->makeProvider('gpt-4o');

        $flagged = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'gpt-4o-mini',
            'enabled' => true,
            'is_default' => true,
        ]);

        AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'gpt-4o',
            'enabled' => true,
        ]);

        $this->runBackfill();

        $default = DB::table('ai_provider_model_defaults')
            ->where('ai_provider_id', $provider->id)
            ->where('capability', 'text')
            ->value('ai_provider_model_id');

        $this->assertSame($flagged->id, $default);
    }

    public function test_backfill_falls_back_to_provider_model_match(): void
    {
        $provider = $this->makeProvider('gpt-4o');

        $matching = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'gpt-4o',
            'enabled' => true,
        ]);

        AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'other',
            'enabled' => true,
        ]);

        $this->runBackfill();

        $default = DB::table('ai_provider_model_defaults')
            ->where('ai_provider_id', $provider->id)
            ->where('capability', 'text')
            ->value('ai_provider_model_id');

        $this->assertSame($matching->id, $default);
    }

    public function test_backfill_does_not_create_embeddings_defaults(): void
    {
        $provider = $this->makeProvider('gpt-4o');

        AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => 'gpt-4o',
            'enabled' => true,
            'capabilities_override' => ['text', 'embeddings'],
            'embedding_dimensions' => 1536,
        ]);

        $this->runBackfill();

        $this->assertSame(0, DB::table('ai_provider_model_defaults')
            ->where('capability', 'embeddings')
            ->count());
    }
}

/**
 * Resolve a package migration file path for the backfill test.
 */
function database_path_migration(string $file): string
{
    return dirname(__DIR__, 3).'/database/migrations/'.$file;
}
