<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;

/**
 * Result of a provider connection test.
 *
 * Returned by {@see AiProviderTester::testProvider()},
 * {@see AiProviderTester::testConfig()} and
 * {@see ImageGenerationProviderTester::testConfig()}.
 *
 * Implements {@see Arrayable} and {@see Jsonable} so controllers can
 * pass it directly to `response()->json()`.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ProviderTestData implements Arrayable, Jsonable
{
    /**
     * Create a successful test result.
     */
    public static function ok(int $latencyMs, string $reply): self
    {
        return new self(
            status: 'ok',
            latencyMs: $latencyMs,
            reply: $reply,
        );
    }

    /**
     * Create a failed test result.
     */
    public static function error(string $message): self
    {
        return new self(
            status: 'error',
            latencyMs: 0,
            message: $message,
        );
    }

    public function __construct(
        public string $status,
        public int $latencyMs,
        public ?string $reply = null,
        public ?string $message = null,
    ) {}

    /**
     * @return array{status: string, latency_ms: int, reply?: string, message?: string}
     */
    public function toArray(): array
    {
        $result = [
            'status' => $this->status,
            'latency_ms' => $this->latencyMs,
        ];

        if ($this->reply !== null) {
            $result['reply'] = $this->reply;
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
