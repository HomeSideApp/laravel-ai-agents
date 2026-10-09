<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Enums;

/**
 * The purpose an embedding is generated for.
 *
 * Many embedding models accept purpose-specific options (e.g. different
 * input types for indexed documents vs. search queries). The purpose selects
 * WHICH options to apply; it does not change the embedding space identity, so
 * Document and Query of the same profile share one fingerprint.
 *
 * - Generic:  backwards-compatible default; uses the generic options.
 * - Document: content that will be indexed/persisted.
 * - Query:    text used to search against already indexed documents.
 */
enum EmbeddingPurpose: string
{
    case Generic = 'generic';
    case Document = 'document';
    case Query = 'query';
}
