<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\ModelsDev;

use HomeSide\AiAgents\Models\ModelsDevModel;
use HomeSide\AiAgents\Providers\DynamicProviderRegistrar;

/**
 * Builds form prefill suggestions from a models.dev catalog entry.
 *
 * The catalog is a SUGGESTION source only: this service turns a
 * (provider slug, model id) pair into the field values the host's
 * provider form can autofill. Nothing is persisted here — the host's
 * own controller submits the (possibly user-edited) values to
 * AiProvider::createValidated(), which keeps all validation in one place.
 *
 *   $prefill = app(CatalogPrefill::class);
 *   $suggestion = $prefill->prefill('openai', 'gpt-4o');  // ?array
 *   $suggestion = $prefill->forModel($modelsDevModel);    // for lists
 */
class CatalogPrefill
{
    /**
     * Resolve a catalog model by (provider slug, model_id) and build its
     * prefill payload.
     *
     * @return array<string, mixed>|null Null when the pair does not exist
     *                                   in the catalog — hosts show an
     *                                   empty form instead of an error.
     */
    public function prefill(string $providerSlug, string $modelId): ?array
    {
        /** @var ModelsDevModel|null $model */
        $model = ModelsDevModel::query()
            ->whereHas('provider', fn ($q) => $q->where('slug', $providerSlug))
            ->where('model_id', $modelId)
            ->with('provider')
            ->first();

        return $model === null ? null : $this->forModel($model);
    }

    /**
     * Build the prefill payload from an already-loaded catalog model.
     *
     * Every key matches the AiProvider fillable column it would populate,
     * so the host can merge the payload into its request as-is.
     *
     * @return array<string, mixed>
     */
    public function forModel(ModelsDevModel $model): array
    {
        $provider = $model->provider;

        $supported = DynamicProviderRegistrar::supportedDrivers();
        $suggestedType = $provider !== null && isset($supported[$provider->slug])
            ? $provider->slug
            : 'openai-compatible';

        return [
            'name' => $provider->name ?? $model->name,
            'type' => $suggestedType,
            'base_url' => $provider?->api_url,
            'model' => $model->model_id,
            'privacy_level' => 'cloud',
            // Catalog-aligned spec columns, 1:1 with the ai_providers table.
            'family' => $model->family,
            'description' => $model->description,
            'attachment' => $model->attachment,
            'reasoning' => $model->reasoning,
            'reasoning_options' => $model->reasoning_options,
            'tool_call' => $model->tool_call,
            'structured_output' => $model->structured_output,
            'temperature' => $model->temperature,
            'open_weights' => $model->open_weights,
            'modalities_input' => $model->modalities_input,
            'modalities_output' => $model->modalities_output,
            'context_window' => $model->context_window,
            'max_input_tokens' => $model->max_input_tokens,
            'max_output_tokens' => $model->max_output_tokens,
            'cost_input' => $model->cost_input,
            'cost_output' => $model->cost_output,
            'cost_cache_read' => $model->cost_cache_read,
            'cost_cache_write' => $model->cost_cache_write,
        ];
    }
}
