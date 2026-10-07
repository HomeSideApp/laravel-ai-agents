<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Enums\AiDriver;
use HomeSide\AiAgents\Exceptions\NoProviderModelException;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Models\AiProviderModelDefault;
use Illuminate\Support\Facades\DB;

/**
 * Writes the per-capability default model of a provider.
 *
 * Replaces the deprecated AiProviderModel::markAsDefault() as the way to
 * promote a model: it validates that the capability is routable, that the
 * model belongs to the provider and that it supports what it is being made
 * the default for — inside a transaction so the unique constraint and the
 * previous default are handled atomically.
 */
final class ProviderModelDefaults
{
    /**
     * Set the default model for a capability, replacing any previous one.
     *
     * @throws NoProviderModelException When the capability is not routable,
     *                                  the model belongs to another provider
     *                                  or it lacks the capability.
     */
    public function set(AiProvider $provider, Capability $capability, AiProviderModel $model): AiProviderModelDefault
    {
        if (! $capability->canBeModelDefault()) {
            throw new NoProviderModelException(
                "The capability [{$capability->value}] cannot be a provider model default.",
            );
        }

        if ($model->ai_provider_id !== $provider->id) {
            throw NoProviderModelException::notOwned($model->id, $provider->name);
        }

        if (! $model->enabled) {
            throw NoProviderModelException::disabled($model->model);
        }

        $driver = AiDriver::fromColumn($provider->type)->value;

        if (! $model->supportsCapability($capability, $driver)) {
            throw NoProviderModelException::missingCapabilities($model->model, $capability, [$capability->value]);
        }

        // Embeddings need an explicit vector length: the SDK requires
        // dimensions when a model is passed (except openai-compatible), and a
        // vector store needs to know N. Persist a valid default or none.
        if ($capability === Capability::Embeddings && ($model->embedding_dimensions ?? 0) <= 0) {
            throw NoProviderModelException::missingDimensions($model->model);
        }

        return DB::transaction(function () use ($provider, $capability, $model): AiProviderModelDefault {
            /** @var AiProviderModelDefault $default */
            $default = AiProviderModelDefault::query()->updateOrCreate(
                [
                    'ai_provider_id' => $provider->id,
                    'capability' => $capability->value,
                ],
                [
                    'ai_provider_model_id' => $model->id,
                ],
            );

            return $default;
        });
    }

    /**
     * Remove the default for a capability (idempotent).
     */
    public function clear(AiProvider $provider, Capability $capability): void
    {
        AiProviderModelDefault::query()
            ->where('ai_provider_id', $provider->id)
            ->where('capability', $capability->value)
            ->delete();
    }
}
