<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Synchronizer;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Result of agent synchronisation.
 *
 * Returned by {@see AgentSynchronizer::sync()}.
 *
 * @implements Arrayable<string, mixed>
 * @implements ArrayAccess<string, mixed>
 */
final readonly class SyncResultData implements Arrayable, ArrayAccess
{
    /**
     * @param  int  $created  Number of new ai_agents rows created.
     * @param  int  $unchanged  Number of existing rows that matched.
     * @param  list<string>  $orphanedKeys  Registered agent keys with existing rows but no matching class.
     * @param  list<string>  $missingRows  Registered agent keys that have no ai_agents row yet.
     */
    public function __construct(
        public int $created,
        public int $unchanged,
        public array $orphanedKeys,
        public array $missingRows,
    ) {}

    /**
     * @return array{created: int, unchanged: int, orphaned_keys: list<string>, missing_rows: list<string>}
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'unchanged' => $this->unchanged,
            'orphaned_keys' => $this->orphanedKeys,
            'missing_rows' => $this->missingRows,
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
