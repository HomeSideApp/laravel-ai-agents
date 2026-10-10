<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Embeddings;

use HomeSide\AiAgents\Enums\AiDriver;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use InvalidArgumentException;
use JsonException;

/**
 * Deterministic fingerprint of a complete embedding space.
 *
 * The fingerprint identifies the FULL profile (both document and query
 * options), not one call, so a document and a query of the same profile
 * share a fingerprint. It changes when the provider endpoint/driver/id, the
 * model id/name, the dimensions, the profile version or ANY per-purpose
 * option changes — and does NOT change for operational metadata such as API
 * key rotation, display names or probe timestamps (those are never included).
 *
 * The payload is canonicalised (recursive key sorting; list order preserved)
 * before hashing, so key order never affects the result.
 */
final class EmbeddingProfileFingerprint
{
    private const SCHEMA = 1;

    /**
     * Compute the fingerprint for a provider/model pair.
     *
     * @param  array{generic: array<string, mixed>, document: array<string, mixed>, query: array<string, mixed>}  $options
     *
     * @throws JsonException When the canonical payload cannot be encoded.
     */
    public function compute(AiProvider $provider, AiProviderModel $model, array $options): string
    {
        $payload = [
            'schema' => self::SCHEMA,
            'provider' => [
                'id' => $provider->id,
                'driver' => AiDriver::fromColumn($provider->type)->value,
                'endpoint' => $this->normaliseEndpoint($provider->base_url),
            ],
            'model' => [
                'id' => $model->id,
                'name' => $model->model,
                'dimensions' => $model->embedding_dimensions,
            ],
            'profile_version' => $model->embedding_profile_version ?? 1,
            'options' => [
                'generic' => $options['generic'],
                'document' => $options['document'],
                'query' => $options['query'],
            ],
        ];

        return hash(
            'sha256',
            json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Recursively sort associative keys while preserving list order.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        return array_map($this->canonicalize(...), $value);
    }

    /**
     * Normalise the endpoint so COSMETIC differences do not change identity.
     *
     * Only the case-insensitive URL parts are lowered (scheme, host); the
     * path and query are preserved as-is because they can be case-sensitive
     * and two different paths may be two different endpoints. Default ports
     * are dropped (https:443, http:80) and trailing slashes removed.
     *
     * @throws InvalidArgumentException When the URL cannot be parsed.
     */
    private function normaliseEndpoint(string $baseUrl): string
    {
        $url = parse_url(trim($baseUrl));

        if ($url === false || ! isset($url['host'])) {
            throw new InvalidArgumentException("Invalid provider endpoint [{$baseUrl}].");
        }

        $scheme = strtolower($url['scheme'] ?? 'https');
        $host = strtolower($url['host']);
        $port = $url['port'] ?? null;

        // Drop the default port for each scheme: it names the same endpoint.
        $isDefaultPort = ($scheme === 'https' && $port === 443)
            || ($scheme === 'http' && $port === 80);

        $portPart = ($port !== null && ! $isDefaultPort) ? ':'.$port : '';
        $path = isset($url['path']) ? rtrim($url['path'], '/') : '';
        $query = isset($url['query']) ? '?'.$url['query'] : '';

        return "{$scheme}://{$host}{$portPart}{$path}{$query}";
    }
}
