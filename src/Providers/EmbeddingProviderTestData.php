<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use HomeSide\AiAgents\Enums\EmbeddingDimensionsSource;
use HomeSide\AiAgents\Enums\EmbeddingProviderTestError;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;

/**
 * Structured result of an embeddings probe.
 *
 * Keeps the probed dimensions, their source (verified or discovered) and a
 * machine-readable error as first-class values, so a successful probe can
 * seed embedding_dimensions and a failed one can drive a precise UI.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class EmbeddingProviderTestData implements Arrayable, Jsonable
{
    /**
     * A successful probe.
     *
     * @param  EmbeddingDimensionsSource  $dimensionsSource  Whether the
     *                                                       dimensions were verified (already configured) or discovered.
     */
    public static function ok(
        int $latencyMs,
        int $dimensions,
        EmbeddingDimensionsSource $dimensionsSource,
    ): self {
        return new self(
            status: 'ok',
            latencyMs: $latencyMs,
            dimensions: $dimensions,
            dimensionsSource: $dimensionsSource,
        );
    }

    /**
     * A failed probe with a machine-readable reason.
     */
    public static function error(EmbeddingProviderTestError $error, string $message): self
    {
        return new self(
            status: 'error',
            latencyMs: 0,
            dimensions: null,
            message: $message,
            error: $error,
        );
    }

    public function __construct(
        public string $status,
        public int $latencyMs,
        public ?int $dimensions = null,
        public ?string $message = null,
        public ?EmbeddingDimensionsSource $dimensionsSource = null,
        public ?EmbeddingProviderTestError $error = null,
    ) {}

    /**
     * Whether the probe succeeded.
     */
    public function successful(): bool
    {
        return $this->status === 'ok';
    }

    /**
     * @return array{status: string, latency_ms: int, dimensions?: int, dimensions_source?: string, message?: string, error?: string}
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

        if ($this->dimensionsSource !== null) {
            $result['dimensions_source'] = $this->dimensionsSource->value;
        }

        if ($this->message !== null) {
            $result['message'] = $this->message;
        }

        if ($this->error !== null) {
            $result['error'] = $this->error->value;
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
