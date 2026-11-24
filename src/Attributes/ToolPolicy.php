<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Attributes;

use Attribute;

/**
 * Declares which tool policy governs an agent's tool calls.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ToolPolicy
{
    public function __construct(public readonly string $name) {}
}
