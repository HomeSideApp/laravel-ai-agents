<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Fixtures;

use HomeSide\AiAgents\Contracts\ModuleAiProvider;

/**
 * Minimal ModuleAiProvider implementation for registry tests.
 */
class DummyModule implements ModuleAiProvider
{
    /**
     * The module identifier the dummy registers under.
     */
    public function module(): string
    {
        return 'recipes';
    }

    /**
     * One seeded agent so synchroniser tests have data to work with.
     *
     * @return array<string, array{label: string, system_prompt: string, description: string|null, parameters: array{temperature?: float, max_tokens?: int}|null}>
     */
    public function agents(): array
    {
        return [
            'generator' => [
                'label' => 'Generator',
                'system_prompt' => 'You generate recipes.',
                'description' => null,
                'parameters' => ['temperature' => 0.5],
            ],
        ];
    }

    /**
     * Empty module-level configuration.
     *
     * @return array<string, mixed>
     */
    public function defaultConfiguration(): array
    {
        return [];
    }
}
