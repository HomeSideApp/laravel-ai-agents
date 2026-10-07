<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Enums;

/**
 * Where the dimensions reported by an embeddings probe came from.
 *
 * - Configured: the model already declared embedding_dimensions and the
 *   probe simply VERIFIED that the provider returns that length.
 * - Discovered: the model had no dimensions and the provider (on a driver
 *   that supports native embedding dimensions) returned a vector whose
 *   length was read from the response.
 */
enum EmbeddingDimensionsSource: string
{
    case Configured = 'configured';
    case Discovered = 'discovered';
}
