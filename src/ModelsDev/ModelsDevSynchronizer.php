<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\ModelsDev;

use HomeSide\AiAgents\Models\ModelsDevModel;
use HomeSide\AiAgents\Models\ModelsDevProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Fetches the models.dev catalog (https://models.dev/api.json) and mirrors
 * it into the local reference tables, downloading provider logos as SVG.
 *
 * The sync is idempotent: providers are upserted by slug, models by
 * (provider, model_id). Existing rows are updated in place — nothing is
 * deleted, so providers/models removed upstream stay available locally
 * until a host prunes them deliberately.
 *
 * @phpstan-type SyncStats array{
 *     providers_created: int,
 *     providers_updated: int,
 *     models_created: int,
 *     models_updated: int,
 *     logos_downloaded: int,
 *     errors: list<string>
 * }
 */
class ModelsDevSynchronizer
{
    /**
     * @param  string|null  $apiUrl  Override for the catalog endpoint
     *                               (tests point this at a local fixture).
     * @param  string  $logoBaseUrl  Base URL for provider logos.
     * @param  string  $logoDiskPath  Path (inside the storage app dir) where
     *                                logos are written.
     * @param  bool  $skipLogos  Disable logo downloading entirely.
     */
    public function __construct(
        private readonly ?string $apiUrl = null,
        private readonly string $logoBaseUrl = 'https://models.dev/logos',
        private readonly string $logoDiskPath = 'models-dev/logos',
        private readonly bool $skipLogos = false,
    ) {}

    /**
     * Run the full synchronisation.
     *
     * @param  bool  $forceLogos  Re-download logos even when the local file
     *                            already exists.
     * @param  bool  $skipLogos  Skip logo downloading for this run.
     * @return SyncStats Counters for command/report output.
     *
     * @throws RuntimeException When the catalog cannot be fetched or parsed.
     */
    public function sync(bool $forceLogos = false, ?bool $skipLogos = null): array
    {
        $skipLogos ??= $this->skipLogos;
        $catalog = $this->fetchCatalog();

        $stats = [
            'providers_created' => 0,
            'providers_updated' => 0,
            'models_created' => 0,
            'models_updated' => 0,
            'logos_downloaded' => 0,
            'errors' => [],
        ];

        foreach ($catalog as $slug => $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $slug = (string) $slug;

            try {
                $provider = $this->upsertProvider($slug, $payload, $stats);
                $this->upsertModels($provider, $payload, $stats);
                if (! $skipLogos) {
                    $this->syncLogo($provider, $stats, $forceLogos);
                }
            } catch (\Throwable $exception) {
                $message = "Provider [{$slug}]: {$exception->getMessage()}";
                $stats['errors'][] = $message;

                Log::warning('models.dev sync failed for provider', [
                    'provider' => $slug,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Fetch and decode the catalog.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException On HTTP or JSON failure.
     */
    private function fetchCatalog(): array
    {
        $url = $this->apiUrl ?? 'https://models.dev/api.json';

        try {
            $response = Http::connectTimeout(10)
                ->timeout(60)
                ->retry(2, 500, function (\Throwable $exception): bool {
                    // GET is idempotent: retry connection failures, 429 and 5xx.
                    return $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && ($exception->response->serverError() || $exception->response->status() === 429));
                })
                ->get($url)
                ->throw();
        } catch (\Throwable $exception) {
            throw new RuntimeException('Could not fetch models.dev catalog: '.$exception->getMessage(), 0, $exception);
        }

        $data = $response->json();

        if (! is_array($data) || $data === []) {
            throw new RuntimeException('models.dev catalog returned no usable data.');
        }

        return $data;
    }

    /**
     * Create or update the provider row for a catalog entry.
     *
     * @param  array<string, mixed>  $payload
     * @param  SyncStats  $stats
     */
    private function upsertProvider(string $slug, array $payload, array &$stats): ModelsDevProvider
    {
        /** @var ModelsDevProvider|null $provider */
        $provider = ModelsDevProvider::query()->where('slug', $slug)->first();

        $attributes = [
            'name' => (string) ($payload['name'] ?? $slug),
            'api_url' => isset($payload['api']) && is_string($payload['api']) ? $payload['api'] : null,
            'doc_url' => isset($payload['doc']) && is_string($payload['doc']) ? $payload['doc'] : null,
            'env_vars' => isset($payload['env']) && is_array($payload['env'])
                ? array_values(array_map(strval(...), $payload['env']))
                : null,
            'last_synced_at' => Carbon::now(),
        ];

        if ($provider === null) {
            $provider = ModelsDevProvider::create([...$attributes, 'slug' => $slug]);
            $stats['providers_created']++;
        } else {
            $provider->update($attributes);
            $stats['providers_updated']++;
        }

        return $provider;
    }

    /**
     * Upsert every model of a provider.
     *
     * @param  array<string, mixed>  $payload
     * @param  SyncStats  $stats
     */
    private function upsertModels(ModelsDevProvider $provider, array $payload, array &$stats): void
    {
        $models = $payload['models'] ?? [];

        if (! is_array($models)) {
            return;
        }

        foreach ($models as $modelId => $modelPayload) {
            if (! is_array($modelPayload)) {
                continue;
            }

            $modelId = (string) $modelId;

            try {
                $this->upsertModel($provider, $modelId, $modelPayload, $stats);
            } catch (\Throwable $exception) {
                $message = "Model [{$provider->slug}/{$modelId}]: {$exception->getMessage()}";
                $stats['errors'][] = $message;
            }
        }
    }

    /**
     * Create or update a single model row.
     *
     * @param  array<string, mixed>  $modelPayload
     * @param  SyncStats  $stats
     */
    private function upsertModel(ModelsDevProvider $provider, string $modelId, array $modelPayload, array &$stats): void
    {
        $attributes = $this->mapModelAttributes($modelPayload);

        /** @var ModelsDevModel|null $model */
        $model = ModelsDevModel::query()
            ->where('models_dev_provider_id', $provider->id)
            ->where('model_id', $modelId)
            ->first();

        if ($model === null) {
            ModelsDevModel::create([
                ...$attributes,
                'models_dev_provider_id' => $provider->id,
                'model_id' => $modelId,
            ]);
            $stats['models_created']++;
        } else {
            $model->update($attributes);
            $stats['models_updated']++;
        }
    }

    /**
     * Map one API model payload onto database attributes.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mapModelAttributes(array $payload): array
    {
        $limit = is_array($payload['limit'] ?? null) ? $payload['limit'] : [];
        $cost = is_array($payload['cost'] ?? null) ? $payload['cost'] : [];
        $modalities = is_array($payload['modalities'] ?? null) ? $payload['modalities'] : [];

        return [
            'name' => (string) ($payload['name'] ?? ''),
            'description' => isset($payload['description']) && is_string($payload['description'])
                ? $payload['description']
                : null,
            'family' => isset($payload['family']) && is_string($payload['family'])
                ? $payload['family']
                : null,
            'attachment' => (bool) ($payload['attachment'] ?? false),
            'reasoning' => (bool) ($payload['reasoning'] ?? false),
            'reasoning_options' => isset($payload['reasoning_options']) && is_array($payload['reasoning_options'])
                ? $payload['reasoning_options']
                : null,
            'tool_call' => (bool) ($payload['tool_call'] ?? false),
            'structured_output' => (bool) ($payload['structured_output'] ?? false),
            'temperature' => (bool) ($payload['temperature'] ?? true),
            'open_weights' => (bool) ($payload['open_weights'] ?? false),
            'release_date' => $this->parseDate($payload['release_date'] ?? null),
            'last_updated' => $this->parseDate($payload['last_updated'] ?? null),
            'modalities_input' => is_array($modalities['input'] ?? null)
                ? array_values(array_map(strval(...), $modalities['input']))
                : null,
            'modalities_output' => is_array($modalities['output'] ?? null)
                ? array_values(array_map(strval(...), $modalities['output']))
                : null,
            'context_window' => $this->nullableInt($limit['context'] ?? null),
            'max_input_tokens' => $this->nullableInt($limit['input'] ?? null),
            'max_output_tokens' => $this->nullableInt($limit['output'] ?? null),
            'cost_input' => $this->nullableCost($cost['input'] ?? null),
            'cost_output' => $this->nullableCost($cost['output'] ?? null),
            'cost_cache_read' => $this->nullableCost($cost['cache_read'] ?? null),
            'cost_cache_write' => $this->nullableCost($cost['cache_write'] ?? null),
            'last_synced_at' => Carbon::now(),
        ];
    }

    /**
     * Download (or skip) the provider's SVG logo, storing it under
     * storage/app/private/{logoDiskPath}/{slug}.svg.
     *
     * @param  SyncStats  $stats
     */
    private function syncLogo(ModelsDevProvider $provider, array &$stats, bool $force): void
    {
        if ($this->skipLogos) {
            return;
        }

        $relativePath = $this->logoDiskPath.'/'.$provider->slug.'.svg';

        if (! $force && Storage::disk('local')->exists($relativePath)) {
            return;
        }

        $url = $this->logoBaseUrl.'/'.$provider->slug.'.svg';

        try {
            $response = Http::connectTimeout(5)
                ->timeout(20)
                ->get($url);

            if (! $response->successful()) {
                // A missing logo is not a sync failure: record and move on.
                $stats['errors'][] = "Logo [{$provider->slug}]: HTTP {$response->status()}";

                return;
            }

            $body = $response->body();

            if ($body === '' || ! str_contains($body, '<svg')) {
                $stats['errors'][] = "Logo [{$provider->slug}]: unexpected body";

                return;
            }

            Storage::disk('local')->put($relativePath, $body);
            $provider->update(['logo_path' => $relativePath]);
            $stats['logos_downloaded']++;
        } catch (\Throwable $exception) {
            $stats['errors'][] = "Logo [{$provider->slug}]: {$exception->getMessage()}";
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableCost(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 6, '.', '') : null;
    }

    private function parseDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
