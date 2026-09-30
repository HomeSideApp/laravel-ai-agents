<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Database\Factories;

use HomeSide\AiAgents\Models\AiGlobalSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for AiGlobalSetting.
 *
 * @extends Factory<AiGlobalSetting>
 */
class AiGlobalSettingFactory extends Factory
{
    protected $model = AiGlobalSetting::class;

    public function definition(): array
    {
        return [
            'module' => null,
            'extra_prompt' => fake()->sentence(),
        ];
    }

    /**
     * The installation-wide global policy row (module null).
     */
    public function global(): static
    {
        return $this->state(fn (): array => ['module' => null]);
    }

    /**
     * The policy row of one specific module.
     */
    public function forModule(string $module): static
    {
        return $this->state(fn (): array => ['module' => $module]);
    }
}
