<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Enums\EmbeddingDimensionsSource;
use HomeSide\AiAgents\Exceptions\StaleProviderModelProbeResultException;
use HomeSide\AiAgents\Models\AiProviderModel;
use Illuminate\Support\Facades\DB;

/**
 * Persists the outcome of an embeddings probe onto an AiProviderModel.
 *
 * Kept separate from {@see EmbeddingProviderTester} (which only observes) so
 * probing is side-effect free and applying the result is an explicit,
 * auditable operation.
 *
 * A probe result is an optimistic snapshot taken at T0. The applier RE-READS
 * the model under a row lock at T1 and only applies the snapshot when the
 * current dimensions are still compatible:
 *
 *   configured: current MUST still equal the probed dimensions (verified,
 *               not discovered).
 *   discovered: current may be null (persist) or equal (idempotent retry),
 *               but never a different value.
 *
 * A conflict throws {@see StaleProviderModelProbeResultException} and changes
 * nothing — never overwriting dimensions, never adding the capability, never
 * marking the probe as ok. A failed probe only updates bookkeeping.
 *
 * As with the tester, capabilities_detected is updated but
 * capabilities_override is never touched: an automatic probe can never win
 * over an explicit admin decision. The applier never promotes the model to a
 * capability default either.
 */
final class ProviderModelProbeResultApplier
{
    /**
     * Persist a probe result onto the model.
     *
     * @return bool True when the result was applied (the probe bookkeeping is
     *              always refreshed), false when the model row disappeared.
     *
     * @throws StaleProviderModelProbeResultException When the snapshot no
     *                                                longer matches the row.
     */
    public function apply(AiProviderModel $model, EmbeddingProviderTestData $result): bool
    {
        return DB::transaction(function () use ($model, $result): bool {
            // The locked, freshly-read row is the source of truth; the given
            // instance may be stale.
            $current = AiProviderModel::query()
                ->whereKey($model->id)
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                return false;
            }

            $now = now();

            if (! $result->successful()) {
                // Only bookkeeping: never touch capabilities or dimensions on
                // a failed probe (a mismatch must stay visible to the admin).
                $current->forceFill([
                    'last_probed_at' => $now,
                    'last_probe_status' => 'error',
                ])->save();

                return true;
            }

            // A successful result always carries dimensions and a source.
            $probed = $result->dimensions
                ?? throw new \LogicException('A successful probe result must carry dimensions.');

            $attributes = $this->resolveDimensionAttributes($current, $result, $probed);

            $attributes['capabilities_detected'] = $this->withCapability(
                $current->capabilities_detected,
                Capability::Embeddings,
            );
            $attributes['last_probed_at'] = $now;
            $attributes['last_probe_status'] = 'ok';

            $current->forceFill($attributes)->save();

            return true;
        });
    }

    /**
     * Validate the snapshot against the current row and return the dimension
     * attributes to persist, or throw when the result is stale.
     *
     * @return array<string, mixed>
     *
     * @throws StaleProviderModelProbeResultException
     */
    private function resolveDimensionAttributes(
        AiProviderModel $current,
        EmbeddingProviderTestData $result,
        int $probed,
    ): array {
        $currentDimensions = $current->embedding_dimensions;

        if ($result->dimensionsSource === EmbeddingDimensionsSource::Configured) {
            // Verified, not discovered: the current value must still be the
            // exact value that was probed.
            if ($currentDimensions !== $probed) {
                throw $currentDimensions === null
                    ? StaleProviderModelProbeResultException::dimensionsRemoved($current->model, $probed)
                    : StaleProviderModelProbeResultException::dimensionsChanged($current->model, $probed, (int) $currentDimensions);
            }

            return [];
        }

        // Discovered: null → persist; equal → idempotent; different → stale.
        if ($currentDimensions === null) {
            return ['embedding_dimensions' => $probed];
        }

        if ((int) $currentDimensions !== $probed) {
            throw StaleProviderModelProbeResultException::dimensionsChanged($current->model, $probed, (int) $currentDimensions);
        }

        return [];
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
