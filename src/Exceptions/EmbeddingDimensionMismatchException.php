<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Exceptions;

use RuntimeException;

/**
 * Raised when the Embeddings provider returns vectors inconsistent with the
 * request or the pinned model's declared dimensions.
 *
 * Guards vector stores (e.g. a MariaDB VECTOR(N) column): a batch whose
 * count does not match the inputs, or a vector whose length differs from the
 * declared embedding_dimensions, must never reach the index.
 */
final class EmbeddingDimensionMismatchException extends RuntimeException
{
    /**
     * The number of returned vectors differs from the number of inputs.
     */
    public static function countMismatch(int $expected, int $actual): self
    {
        return new self(
            "The embeddings provider returned {$actual} vectors for {$expected} inputs.",
        );
    }

    /**
     * A returned vector's length differs from the expected dimensions.
     */
    public static function vectorMismatch(int $expected, int $actual): self
    {
        return new self(
            "The embeddings provider returned a vector of {$actual} dimensions; "
            ."expected {$expected}.",
        );
    }
}
