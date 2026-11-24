<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Taint;

/**
 * Run-keyed taint scopes.
 *
 * Keyed by whatever identifies a run for the host (run id, conversation id, or
 * an object name). Bounded, because a long-lived worker would otherwise keep one
 * scope per run forever.
 */
final class TaintRegistry
{
    /** @var array<string, TaintScope> */
    private array $scopes = [];

    public function __construct(private readonly int $limit = 500) {}

    public function for(string|int|object $run): TaintScope
    {
        $key = $this->key($run);

        if (! isset($this->scopes[$key])) {
            if (count($this->scopes) >= $this->limit) {
                array_shift($this->scopes);
            }

            $this->scopes[$key] = new TaintScope;
        }

        return $this->scopes[$key];
    }

    public function forget(string|int|object $run): void
    {
        unset($this->scopes[$this->key($run)]);
    }

    /**
     * @param  array<string, TaintScope>  $scopes
     */
    public function replace(array $scopes): void
    {
        $this->scopes = $scopes;
    }

    private function key(string|int|object $run): string
    {
        return is_object($run) ? 'obj:'.spl_object_id($run) : 'key:'.$run;
    }
}
