<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use HomeSide\AiAgents\Enums\EmbeddingDimensionsSource;
use HomeSide\AiAgents\Enums\EmbeddingProviderTestError;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use InvalidArgumentException;

/**
 * Structured result of an embeddings probe.
 *
 * Snapshots what was observed at probe time. The constructor is private and
 * the factories enforce the invariants, so an inconsistent success (missing
 * dimensions/source) or a success-carrying error is unrepresentable:
 *
 *   success → status=ok, dimensions>=1, source set, error=null
 *   error   → status=error, error set, dimensions/source=null
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class EmbeddingProviderTestData implements Arrayable, Jsonable
{
    /**
     * A successful probe whose dimensions were already configured and were
     * verified against the provider response.
     */
    public static function configured(int $latencyMs, int $dimensions): self
    {
        self::assertSuccessfulValues($latencyMs, $dimensions);

        return new self(
            status: 'ok',
            latencyMs: $latencyMs,
            dimensions: $dimensions,
            dimensionsSource: EmbeddingDimensionsSource::Configured,
        );
    }

    /**
     * A successful probe whose dimensions were discovered from the response.
     */
    public static function discovered(int $latencyMs, int $dimensions): self
    {
        self::assertSuccessfulValues($latencyMs, $dimensions);

        return new self(
            status: 'ok',
            latencyMs: $latencyMs,
            dimensions: $dimensions,
            dimensionsSource: EmbeddingDimensionsSource::Discovered,
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

    private function __construct(
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

    /**
     * Enforce the successful-result invariants.
     */
    private static function assertSuccessfulValues(int $latencyMs, int $dimensions): void
    {
        if ($latencyMs < 0) {
            throw new InvalidArgumentException('Probe latencyMs must be greater than or equal to zero.');
        }

        if ($dimensions < 1) {
            throw new InvalidArgumentException('Probe dimensions must be greater than zero.');
        }
    }
}
