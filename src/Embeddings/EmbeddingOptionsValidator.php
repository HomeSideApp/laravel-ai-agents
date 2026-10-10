<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Embeddings;

use HomeSide\AiAgents\Enums\EmbeddingPurpose;
use InvalidArgumentException;

/**
 * Single source of truth for the embedding_options contract.
 *
 * The stored structure must be exactly:
 *
 *   {generic: map, document: map, query: map}
 *
 * where each bucket is an associative array of provider options. Unknown
 * root keys (e.g. a typo like "documment") are REJECTED, not silently
 * ignored, so a misconfigured profile cannot appear configured while
 * applying nothing.
 *
 * Secret-ish keys (authorization, api_key, token, credentials, headers...)
 * are rejected recursively: embedding options end up in the profile DTO and
 * the fingerprint, and the profile must never carry or leak secrets — those
 * belong exclusively to AiProvider.
 *
 * Used both when persisting (fail fast on writes) and when resolving (fail
 * closed on legacy/corrupt data), so the fingerprint always represents the
 * stored data rather than a sanitised interpretation of it.
 */
final class EmbeddingOptionsValidator
{
    /**
     * Root keys that never belong in embedding options: they are either not
     * purposes or would smuggle transport/auth concerns into provider
     * options. Headers require a future explicit, safe API.
     */
    private const FORBIDDEN_KEYS = [
        'authorization', 'api_key', 'apikey', 'api-key', 'token',
        'access_token', 'secret', 'password', 'credentials', 'headers',
    ];

    /**
     * Validate the stored embedding options and return the well-formed
     * bucket map.
     *
     * @return array{generic: array<string, mixed>, document: array<string, mixed>, query: array<string, mixed>}
     *
     * @throws InvalidArgumentException When the structure or content is invalid.
     */
    public function validate(mixed $stored): array
    {
        if ($stored === null) {
            return ['generic' => [], 'document' => [], 'query' => []];
        }

        if (! is_array($stored)) {
            throw new InvalidArgumentException('Embedding options must be an array.');
        }

        $buckets = [];

        foreach (EmbeddingPurpose::cases() as $purpose) {
            $bucket = $stored[$purpose->value] ?? [];

            if (! is_array($bucket)) {
                throw new InvalidArgumentException(
                    "The embedding options bucket [{$purpose->value}] must be an array.",
                );
            }

            $this->assertNoSecrets($bucket, $purpose->value);

            /** @var array<string, mixed> $bucket */
            $buckets[$purpose->value] = $bucket;
        }

        $unknown = array_diff(array_keys($stored), array_column(EmbeddingPurpose::cases(), 'value'));

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown embedding purpose ['.implode(', ', $unknown).']; '
                .'allowed keys are: generic, document, query.',
            );
        }

        return $buckets;
    }

    /**
     * Recursively reject secret-ish keys inside a bucket.
     *
     * @param  array<mixed>  $bucket
     */
    private function assertNoSecrets(array $bucket, string $path): void
    {
        foreach ($bucket as $key => $value) {
            $key = (string) $key;
            $fullPath = $path.'.'.$key;

            if (in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                throw new InvalidArgumentException(
                    "The embedding options key [{$fullPath}] is not allowed: "
                    .'credentials and transport concerns belong to the provider, not to embedding options.',
                );
            }

            if (is_array($value)) {
                /** @var array<mixed> $value */
                $this->assertNoSecrets($value, $fullPath);
            }
        }
    }
}
