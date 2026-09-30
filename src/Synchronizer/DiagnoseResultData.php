<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Synchronizer;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Result of agent diagnosis.
 *
 * Returned by {@see AgentSynchronizer::diagnose()}.
 *
 * @implements Arrayable<string, mixed>
 * @implements ArrayAccess<string, mixed>
 */
final readonly class DiagnoseResultData implements Arrayable, ArrayAccess
{
    /**
     * @param  array<string, string>  $classesWithoutRows  Registered agent keys whose class exists but has no ai_agents row.
     * @param  array<string, string>  $rowsWithoutClasses  ai_agents rows whose key has no matching registered class.
     */
    public function __construct(
        public array $classesWithoutRows,
        public array $rowsWithoutClasses,
    ) {}

    /**
     * @return array{classes_without_rows: array<string, string>, rows_without_classes: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'classes_without_rows' => $this->classesWithoutRows,
            'rows_without_classes' => $this->rowsWithoutClasses,
        ];
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Readonly DTO — no mutation allowed.
    }

    public function offsetUnset(mixed $offset): void
    {
        // Readonly DTO — no mutation allowed.
    }
}
