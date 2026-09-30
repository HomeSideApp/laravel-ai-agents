<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Database\Factories;

use HomeSide\AiAgents\Models\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for AiProvider.
 *
 * Living inside the package lets host tests and seeders call
 * AiProvider::factory() without publishing anything: the HasFactory trait
 * resolves the factory from the model's own namespace first.
 *
 * @extends Factory<AiProvider>
 */
class AiProviderFactory extends Factory
{
    protected $model = AiProvider::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'name' => fake()->company(),
            'type' => 'openai-compatible',
            'driver' => null,
            'base_url' => 'https://api.example.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-test-'.fake()->uuid(),
            'enabled' => true,
            'module' => 'general',
            'configuration' => null,
            'created_by' => null,
            'is_default' => false,
            'privacy_level' => 'cloud',
            'fallback_policy' => 'same_privacy_level',
            // Catalog-aligned spec columns.
            'family' => null,
            'description' => null,
            'attachment' => false,
            'reasoning' => false,
            'reasoning_options' => null,
            'tool_call' => true,
            'structured_output' => true,
            'temperature' => true,
            'open_weights' => false,
            'modalities_input' => ['text'],
            'modalities_output' => ['text'],
            'context_window' => 128000,
            'max_input_tokens' => null,
            'max_output_tokens' => 16384,
            'cost_input' => null,
            'cost_output' => null,
            'cost_cache_read' => null,
            'cost_cache_write' => null,
        ];
    }

    /**
     * A system-wide provider (no owner).
     */
    public function global(): static
    {
        return $this->state(fn (): array => [
            'user_id' => null,
            'household_id' => null,
        ]);
    }

    /**
     * A personal provider owned by the given user.
     */
    public function forUser(int|string|null $userId): static
    {
        return $this->state(fn (): array => ['user_id' => $userId]);
    }

    /**
     * A provider serving one module.
     */
    public function forModule(string $module): static
    {
        return $this->state(fn (): array => ['module' => $module]);
    }
}
