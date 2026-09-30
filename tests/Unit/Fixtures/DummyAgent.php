<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Fixtures;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Contracts\DomainAgent;
use HomeSide\AiAgents\Enums\PrivacyLevel;

/**
 * Minimal DomainAgent implementation for registry and manager tests.
 *
 * Declares only what the contracts require; tests override configuration
 * values via config() when they need specific behaviour.
 */
class DummyAgent implements DomainAgent
{
    /**
     * Optional privacy requirement; tests set it when they exercise the gate.
     */
    public ?PrivacyLevel $requiredPrivacyLevel = null;

    /**
     * The canonical key this dummy registers under.
     */
    public function key(): string
    {
        return 'recipes.generator';
    }

    /**
     * The module identifier the dummy belongs to.
     */
    public function module(): string
    {
        return 'recipes';
    }

    /**
     * A fixed version so assertions on recorded runs are deterministic.
     */
    public function version(): int
    {
        return 1;
    }

    /**
     * Text capability only: no tools or structured output needed here.
     *
     * @return list<Capability>
     */
    public function requiredCapabilities(): array
    {
        return [Capability::Text];
    }

    /**
     * Sensible defaults matching the resolver's baseline.
     *
     * @return array{temperature?: float, top_p?: float, max_tokens?: int, max_steps?: int, timeout?: int}
     */
    public function defaultConfiguration(): array
    {
        return [
            'temperature' => 0.5,
            'max_tokens' => 2048,
            'timeout' => 90,
        ];
    }

    /**
     * No context providers required by default.
     *
     * @return list<string>
     */
    public function contextProviders(): array
    {
        return [];
    }

    /**
     * A small ceiling that tests can rely on.
     */
    public function maxContextTokens(): int
    {
        return 8000;
    }

    /**
     * No privacy requirement by default; tests may set a stricter one.
     */
    public function requiredPrivacyLevel(): ?PrivacyLevel
    {
        return $this->requiredPrivacyLevel;
    }
}
