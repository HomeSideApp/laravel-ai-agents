<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Database\Factories;

use HomeSide\AiAgents\Models\ModelsDevProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for ModelsDevProvider.
 *
 * @extends Factory<ModelsDevProvider>
 */
class ModelsDevProviderFactory extends Factory
{
    protected $model = ModelsDevProvider::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'slug' => str($name)->slug('_')->toString(),
            'name' => $name,
            'api_url' => 'https://api.example.com/v1',
            'doc_url' => 'https://docs.example.com',
            'env_vars' => ['EXAMPLE_API_KEY'],
            'logo_path' => null,
            'last_synced_at' => now(),
        ];
    }

    /**
     * A provider without API or documentation URLs.
     */
    public function minimal(): static
    {
        return $this->state(fn (): array => [
            'api_url' => null,
            'doc_url' => null,
            'env_vars' => null,
        ]);
    }

    /**
     * A provider with its logo already downloaded.
     */
    public function withLogo(): static
    {
        return $this->state(fn (array $attributes): array => [
            'logo_path' => 'models-dev/logos/'.($attributes['slug'] ?? 'unknown').'.svg',
        ]);
    }
}
