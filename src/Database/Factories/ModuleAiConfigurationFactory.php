<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Database\Factories;

use HomeSide\AiAgents\Models\ModuleAiConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for ModuleAiConfiguration.
 *
 * @extends Factory<ModuleAiConfiguration>
 */
class ModuleAiConfigurationFactory extends Factory
{
    protected $model = ModuleAiConfiguration::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'ai_provider_id' => null,
            'provider_model_id' => null,
            'module' => 'recipes',
            'agent_name' => 'recipe_generator',
            'label' => fake()->words(3, true),
            'system_prompt' => fake()->sentence(),
            'instructions' => null,
            'additional_instructions' => null,
            'description' => null,
            'model' => null,
            'parameters' => null,
            'enabled' => true,
        ];
    }

    /**
     * A tenant-owned configuration (collective per household/team).
     */
    public function forTenant(int|string|null $tenantId): static
    {
        $foreignKey = (string) config('ai-agents.tenant.foreign_key', 'household_id');

        return $this->state(fn (): array => [$foreignKey => $tenantId]);
    }

    /**
     * A user-owned configuration.
     */
    public function forUser(int|string|null $userId): static
    {
        return $this->state(fn (): array => ['user_id' => $userId]);
    }
}
