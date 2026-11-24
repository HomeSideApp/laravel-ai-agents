<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Taint;

use HomeSide\AiAgents\Security\Tools\Taintedness;

/**
 * Run-scoped taint state.
 *
 * Monotonic on purpose: once a run has touched untrusted content, every later
 * tool call in that run is treated as tainted. Washing a taint back to clean
 * would require proving the model did not carry any of it forward, which no
 * amount of string inspection can establish — the model paraphrases. A sticky
 * flag over-blocks in exchange for never silently under-blocking.
 */
final class TaintScope
{
    /** @var list<TaintStamp> */
    private array $stamps = [];

    public function mark(TaintStamp $stamp): void
    {
        foreach ($this->stamps as $existing) {
            if ($existing->source === $stamp->source && $existing->label === $stamp->label) {
                return;
            }
        }

        $this->stamps[] = $stamp;
    }

    public function tainted(): Taintedness
    {
        return $this->stamps === [] ? Taintedness::Clean : Taintedness::Untrusted;
    }

    public function isTainted(): bool
    {
        return $this->stamps !== [];
    }

    /** @return list<TaintStamp> */
    public function stamps(): array
    {
        return $this->stamps;
    }

    /**
     * Summary for logs and findings: sources and labels only, never content.
     *
     * @return list<string>
     */
    public function summary(): array
    {
        return array_map(
            static fn (TaintStamp $stamp): string => $stamp->source->value.':'.$stamp->label,
            $this->stamps,
        );
    }
}
