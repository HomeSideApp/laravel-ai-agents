<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Enums;

/**
 * Machine-readable reason an embeddings probe failed.
 *
 * Lets UIs and callers react precisely (e.g. "enter the model dimensions")
 * instead of parsing free-text messages.
 */
enum EmbeddingProviderTestError: string
{
    /** Dimensions are unknown and the driver cannot discover them. */
    case DimensionsRequired = 'dimensions_required';

    /** The provider call failed (network, auth, unsupported model...). */
    case ProviderError = 'provider_error';

    /** The response did not contain exactly one vector. */
    case VectorCountMismatch = 'vector_count_mismatch';

    /** The returned vector was empty. */
    case EmptyVector = 'empty_vector';

    /** The returned vector contained non-numeric values. */
    case InvalidVector = 'invalid_vector';

    /** The returned vector length did not match the configured dimensions. */
    case DimensionMismatch = 'dimension_mismatch';
}
