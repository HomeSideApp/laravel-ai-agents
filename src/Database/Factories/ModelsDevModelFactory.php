<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Database\Factories;

use HomeSide\AiAgents\Models\ModelsDevModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for ModelsDevModel.
 *
 * @extends Factory<ModelsDevModel>
 */
class ModelsDevModelFactory extends Factory
{
    protected $model = ModelsDevModel::class;

    public function definition(): array
    {
        return [
            'models_dev_provider_id' => ModelsDevProviderFactory::new(),
            'model_id' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'family' => 'test-family',
            'attachment' => false,
            'reasoning' => false,
            'reasoning_options' => null,
            'tool_call' => true,
            'structured_output' => true,
            'temperature' => true,
            'open_weights' => false,
            'release_date' => '2024-05-13',
            'last_updated' => '2024-08-01',
            'modalities_input' => ['text'],
            'modalities_output' => ['text'],
            'context_window' => 128000,
            'max_input_tokens' => null,
            'max_output_tokens' => 16384,
            'cost_input' => '2.500000',
            'cost_output' => '10.000000',
            'cost_cache_read' => '1.250000',
            'cost_cache_write' => null,
            'last_synced_at' => now(),
        ];
    }

    /**
     * A multimodal model (image/audio input).
     */
    public function multimodal(): static
    {
        return $this->state(fn (): array => [
            'attachment' => true,
            'modalities_input' => ['text', 'image', 'audio'],
        ]);
    }

    /**
     * An open-weights model without pricing (local providers).
     */
    public function free(): static
    {
        return $this->state(fn (): array => [
            'open_weights' => true,
            'cost_input' => null,
            'cost_output' => null,
            'cost_cache_read' => null,
            'cost_cache_write' => null,
        ]);
    }
}
