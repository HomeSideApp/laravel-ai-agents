<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Embeddings;

/**
 * The result of an embeddings generation.
 *
 * No Laravel AI internal (EmbeddingsResponse, Meta, Usage) escapes to the
 * caller: only plain values do.
 */
final readonly class EmbeddingResultData
{
    /**
     * @param  list<list<float>>  $embeddings  One vector per input, in order.
     * @param  array{inputTokens: int, outputTokens: int, totalTokens: int}|null  $usage
     */
    public function __construct(
        public array $embeddings,
        public string $providerId,
        public string $providerName,
        public string $providerModelId,
        public string $model,
        public int $dimensions,
        public ?array $usage = null,
    ) {}

    /**
     * Serialize to a plain array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'embeddings' => $this->embeddings,
            'provider_id' => $this->providerId,
            'provider_name' => $this->providerName,
            'provider_model_id' => $this->providerModelId,
            'model' => $this->model,
            'dimensions' => $this->dimensions,
            'usage' => $this->usage,
        ];
    }
}
