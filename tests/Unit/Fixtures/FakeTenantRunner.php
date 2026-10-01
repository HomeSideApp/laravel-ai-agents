<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Fixtures;

use HomeSide\AiAgents\Contracts\RunsForEachTenant;

/**
 * Fake RunsForEachTenant for multi-tenant tests.
 *
 * Configurable number of invocations and execution of callbacks.
 */
final class FakeTenantRunner implements RunsForEachTenant
{
    /**
     * Number of times the callback should be invoked.
     */
    public int $invokeCount = 1;

    /**
     * Whether to execute the callbacks.
     */
    public bool $execute = true;

    /**
     * Total number of actual invocations performed.
     */
    public int $actualInvocations = 0;

    /**
     * @var list<callable>
     */
    public array $callbacksExecuted = [];

    /**
     * Execute the callback N times.
     *
     * @param  callable  $callback  The callback to execute.
     */
    public function each(callable $callback): void
    {
        $this->actualInvocations = 0;

        for ($i = 0; $i < $this->invokeCount; $i++) {
            $this->callbacksExecuted[] = $callback;

            if ($this->execute) {
                $callback();
            }

            $this->actualInvocations++;
        }
    }
}
