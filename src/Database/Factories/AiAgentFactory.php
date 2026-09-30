<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Database\Factories;

use HomeSide\AiAgents\Models\AiAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for AiAgent.
 *
 * @extends Factory<AiAgent>
 */
class AiAgentFactory extends Factory
{
    protected $model = AiAgent::class;

    public function definition(): array
    {
        return [
            'key' => 'recipes.generator',
            'module' => 'recipes',
            'label' => fake()->words(3, true),
            'description' => null,
            'platform_prompt' => fake()->sentence(),
            'prompt_version' => 1,
            'enabled' => true,
            'parameters' => null,
        ];
    }
}
