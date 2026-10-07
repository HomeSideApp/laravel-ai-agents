<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Exceptions;

use RuntimeException;

/**
 * Raised when no provider satisfies the Embeddings capability without
 * violating the resolved provider's privacy fallback policy.
 *
 * A local-only provider with no embedding model must NOT silently degrade
 * to a cloud embedding provider; every allowed candidate is exhausted first
 * and failure is explicit.
 */
final class NoEmbeddingProviderException extends RuntimeException
{
    public static function forModule(string $module): self
    {
        return new self(
            "No provider with an embeddings model is available for module [{$module}] "
            .'without violating the privacy fallback policy.',
        );
    }
}
