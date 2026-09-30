<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Contracts;

interface AcceptsRuntimeConfiguration
{
    /** @param array<string, int|float> $parameters */
    public function setRuntimeConfiguration(array $parameters): void;
}
