<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Enums\AiDriver;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModelDefault;
use Illuminate\Support\Facades\Config;

/**
 * Registers dynamic providers in the Laravel AI SDK configuration.
 *
 * Centralises Config::set('ai.providers.{name}', ...) so every module uses
 * the same mechanism.
 *
 * The connectable driver set mirrors the SDK's Lab enum: openai, anthropic,
 * gemini, ollama, openai-compatible, openrouter, xai, groq, deepseek,
 * mistral, cohere, typesafe, azure, bedrock, eleven, jina and voyageai.
 */
final class DynamicProviderRegistrar
{
    /**
     * Register a system AiProvider as a dynamic SDK provider.
     *
     * The SDK-facing `models` block is built exclusively from the stored
     * data (per-capability defaults + the model rows), never invented. The
     * text default falls back to the legacy AiProvider.model during the
     * transition; embeddings/reranking entries appear only when a default
     * exists, since the SDK understands `models.{capability}.default` and,
     * for embeddings, `models.embeddings.dimensions`.
     *
     * @return string The registered provider name (e.g. 'dynamic-ai-abc123').
     */
    public function register(AiProvider $provider): string
    {
        $driver = AiDriver::fromColumn($provider->type)->toSdkDriver();
        $dynamicName = 'dynamic-ai-'.$provider->id;

        Config::set("ai.providers.{$dynamicName}", [
            'driver' => $driver,
            'url' => $provider->base_url,
            'key' => $provider->api_key,
            'models' => $this->modelsFor($provider),
        ]);

        return $dynamicName;
    }

    /**
     * Build the SDK `models` configuration block for a provider.
     *
     * @return array<string, array<string, mixed>>
     */
    private function modelsFor(AiProvider $provider): array
    {
        $defaults = $provider->modelDefaults()->with('model')->get();

        /** @var array<int, Capability> $capabilities */
        $capabilities = [
            Capability::Text,
            Capability::Embeddings,
            Capability::Reranking,
        ];

        $models = [];

        foreach ($capabilities as $capability) {
            /** @var AiProviderModelDefault|null $default */
            $default = $defaults->first(
                fn (AiProviderModelDefault $row): bool => $row->capability === $capability,
            );

            $model = $default?->model;

            if ($model === null) {
                // Transition only: the text default may still live on the
                // legacy AiProvider.model column.
                if ($capability === Capability::Text && $provider->model !== '') {
                    $models['text'] = ['default' => $provider->model];
                }

                continue;
            }

            $entry = ['default' => $model->model];

            if ($capability === Capability::Embeddings && $model->embedding_dimensions !== null) {
                $entry['dimensions'] = $model->embedding_dimensions;
            }

            $models[$capability->value] = $entry;
        }

        return $models;
    }

    /**
     * Register a provider from loose data (for testing/unsaved configuration).
     *
     * @param  array{type: string, base_url: string, api_key: string, model: string}  $data
     * @return string The registered provider name.
     */
    public function registerFromData(array $data): string
    {
        $driver = AiDriver::fromColumn($data['type'])->toSdkDriver();
        $dynamicName = 'test-'.md5($data['base_url'].$data['model']);

        Config::set("ai.providers.{$dynamicName}", [
            'driver' => $driver,
            'url' => $data['base_url'],
            'key' => $data['api_key'],
            'models' => [
                'text' => [
                    'default' => $data['model'],
                ],
            ],
        ]);

        return $dynamicName;
    }

    /**
     * Translate a host provider type into the SDK driver identifier.
     *
     * Unknown types map to 'openai-compatible' so hosts using generic
     * OpenAI-protocol endpoints (NaN Builders, Together AI, ...) work
     * without declaring a dedicated driver.
     *
     * @param  string  $type  The provider type as stored on ai_providers.type
     *                        (e.g. 'openai', 'ollama', 'openai-compatible').
     * @return string The matching SDK driver name, never empty.
     */
    public function resolveDriver(string $type): string
    {
        return AiDriver::fromColumn($type)->toSdkDriver();
    }

    /**
     * List the full provider-type → SDK-driver map.
     *
     * Used by validation (AiProvider::createValidated rejects unsupported
     * types) and by admin UIs to enumerate connectable provider types.
     *
     * @return array<string, string> Map of provider type to SDK driver name,
     *                               covering the SDK v0.11 driver set.
     */
    public static function supportedDrivers(): array
    {
        return array_combine(
            array_column(AiDriver::cases(), 'value'),
            array_column(AiDriver::cases(), 'value'),
        );
    }
}
