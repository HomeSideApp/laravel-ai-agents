<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Execution;

/**
 * Usage data of an agent execution (tokens, latency, cost).
 */
final readonly class AiUsageData
{
    public function __construct(
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $totalTokens = null,
        public int $latencyMs = 0,
        /** @var array{requests?: int, errors?: int} */
        public array $extra = [],
        public ?int $cachedTokens = null,
    ) {}

    /**
     * Serialize to a plain array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'cached_tokens' => $this->cachedTokens,
            'latency_ms' => $this->latencyMs,
            'extra' => $this->extra,
        ];
    }
}
