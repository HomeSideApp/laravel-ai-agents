<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Enums\EmbeddingDimensionsSource;
use HomeSide\AiAgents\Models\AiProviderModel;
use Illuminate\Support\Facades\DB;

/**
 * Persists the outcome of an embeddings probe onto an AiProviderModel.
 *
 * Kept separate from {@see EmbeddingProviderTester} (which only observes) so
 * probing is side-effect free and applying the result is an explicit,
 * auditable operation.
 *
 * On success it records the Embeddings capability, the embedding dimensions
 * and the probe bookkeeping. When the dimensions were DISCOVERED they are
 * written; when they were CONFIGURED the existing value is simply confirmed
 * (the tester already verified it matches). On failure only the probe
 * bookkeeping is updated — capabilities and dimensions are left untouched.
 *
 * The applier never promotes the model to a capability default: that stays
 * an explicit ProviderModelDefaults::set() decision.
 */
final class ProviderModelProbeResultApplier
{
    /**
     * Persist a probe result onto the model.
     *
     * @return bool Whether the model row changed.
     */
    public function apply(AiProviderModel $model, EmbeddingProviderTestData $result): bool
    {
        return DB::transaction(function () use ($model, $result): bool {
            $now = now();

            if (! $result->successful()) {
                // Only bookkeeping: never touch capabilities or dimensions on
                // a failed probe (a mismatch must stay visible to the admin).
                $model->forceFill([
                    'last_probed_at' => $now,
                    'last_probe_status' => 'error',
                ])->save();

                return true;
            }

            $attributes = [
                'last_probed_at' => $now,
                'last_probe_status' => 'ok',
            ];

            $attributes['capabilities_detected'] = $this->withCapability(
                $model->capabilities_detected,
                Capability::Embeddings,
            );

            // Discovered dimensions are persisted; configured ones are only
            // confirmed (the tester verified they match, so no silent change).
            if ($result->dimensions !== null
                && ($result->dimensionsSource === EmbeddingDimensionsSource::Discovered
                    || ($model->embedding_dimensions ?? 0) <= 0)) {
                $attributes['embedding_dimensions'] = $result->dimensions;
            }

            $model->forceFill($attributes)->save();

            return true;
        });
    }

    /**
     * Return the capability list with the given capability added (once).
     *
     * @param  array<int, string>|null  $capabilities
     * @return list<string>
     */
    private function withCapability(?array $capabilities, Capability $capability): array
    {
        $values = is_array($capabilities) ? array_values($capabilities) : [];

        if (! in_array($capability->value, $values, true)) {
            $values[] = $capability->value;
        }

        return $values;
    }
}
