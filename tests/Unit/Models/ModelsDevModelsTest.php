<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Models;

use HomeSide\AiAgents\Database\Factories\ModelsDevModelFactory;
use HomeSide\AiAgents\Database\Factories\ModelsDevProviderFactory;
use HomeSide\AiAgents\Models\ModelsDevModel;
use HomeSide\AiAgents\Models\ModelsDevProvider;
use HomeSide\AiAgents\Tests\TestCase;

/**
 * ModelsDevProvider / ModelsDevModel: casts, relationships and cascading
 * deletes against the real migrations.
 */
final class ModelsDevModelsTest extends TestCase
{
    public function test_provider_persists_with_casts(): void
    {
        $provider = ModelsDevProviderFactory::new()->create();

        $this->assertSame(['EXAMPLE_API_KEY'], $provider->env_vars);
        $this->assertNotNull($provider->last_synced_at);

        $fresh = ModelsDevProvider::query()->where('slug', $provider->slug)->firstOrFail();
        $this->assertSame($provider->id, $fresh->id);
    }

    public function test_provider_has_models_relationship(): void
    {
        $provider = ModelsDevProviderFactory::new()->create();
        ModelsDevModelFactory::new()->count(3)->create([
            'models_dev_provider_id' => $provider->id,
        ]);

        $this->assertSame(3, $provider->models()->count());
    }

    public function test_remote_logo_url_uses_slug(): void
    {
        $provider = ModelsDevProviderFactory::new()->create(['slug' => 'openai']);

        $this->assertSame('https://models.dev/logos/openai.svg', $provider->remoteLogoUrl());
    }

    public function test_model_casts_booleans_numbers_and_dates(): void
    {
        $model = ModelsDevModelFactory::new()->create([
            'attachment' => true,
            'context_window' => '64000',
            'cost_input' => '0.000250',
            'release_date' => '2025-01-15',
        ]);

        $this->assertTrue($model->attachment);
        $this->assertSame(64000, $model->context_window);
        $this->assertSame('0.000250', $model->cost_input);
        $this->assertSame('2025-01-15', $model->release_date?->toDateString());
    }

    public function test_model_has_modality_checks_input_and_output(): void
    {
        $model = ModelsDevModelFactory::new()->multimodal()->create([
            'modalities_output' => ['text'],
        ]);

        $this->assertTrue($model->hasModality('image'));
        $this->assertTrue($model->hasModality('audio'));
        $this->assertTrue($model->hasModality('text', 'output'));
        $this->assertFalse($model->hasModality('image', 'output'));
        $this->assertFalse($model->hasModality('video'));
    }

    public function test_model_has_modality_returns_false_when_null(): void
    {
        $model = ModelsDevModelFactory::new()->create([
            'modalities_input' => null,
            'modalities_output' => null,
        ]);

        $this->assertFalse($model->hasModality('text'));
        $this->assertFalse($model->hasModality('text', 'output'));
    }

    public function test_models_table_declares_cascading_foreign_key(): void
    {
        // Actual CASCADE enforcement is engine behaviour (and SQLite's
        // foreign_keys pragma is a no-op inside RefreshDatabase's
        // transaction), so assert the migration wired it instead.
        $foreignKeys = ModelsDevModel::query()->getConnection()
            ->getSchemaBuilder()
            ->getForeignKeys('models_dev_models');

        $this->assertNotEmpty($foreignKeys);

        $matching = array_values(array_filter(
            $foreignKeys,
            fn (array $fk): bool => in_array('models_dev_provider_id', $fk['columns'] ?? [], true),
        ));

        $this->assertNotEmpty($matching);

        $foreignKey = $matching[0];
        $referencedTable = $foreignKey['references_table']
            ?? $foreignKey['foreign_table']
            ?? $foreignKey['on_table']
            ?? null;
        $onDelete = $foreignKey['on_delete'] ?? null;

        $this->assertSame('models_dev_providers', $referencedTable);
        $this->assertSame('cascade', $onDelete);
    }
}
