<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;

/**
 * Structured result of an embeddings probe.
 *
 * Keeps the probed dimensions as a first-class value (not squeezed into a
 * reply string), so a successful probe can seed embedding_dimensions.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class EmbeddingProviderTestData implements Arrayable, Jsonable
{
    public static function ok(int $latencyMs, int $dimensions): self
    {
        return new self(
            status: 'ok',
            latencyMs: $latencyMs,
            dimensions: $dimensions,
        );
    }

    public static function error(string $message): self
    {
        return new self(
            status: 'error',
            latencyMs: 0,
            dimensions: null,
            message: $message,
        );
    }

    public function __construct(
        public string $status,
        public int $latencyMs,
        public ?int $dimensions = null,
        public ?string $message = null,
    ) {}

    /**
     * @return array{status: string, latency_ms: int, dimensions?: int, message?: string}
     */
    public function toArray(): array
    {
        $result = [
            'status' => $this->status,
            'latency_ms' => $this->latencyMs,
        ];

        if ($this->dimensions !== null) {
            $result['dimensions'] = $this->dimensions;
        }

        if ($this->message !== null) {
            $result['message'] = $this->message;
        }

        return $result;
    }

    /**
     * @param  int  $options  JSON encoding options (passed to `json_encode`).
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }
}
