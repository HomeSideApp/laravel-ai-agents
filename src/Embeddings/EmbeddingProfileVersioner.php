<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Embeddings;

use HomeSide\AiAgents\Models\AiProviderModel;
use Illuminate\Support\Facades\DB;

/**
 * Explicitly invalidates an embedding space.
 *
 * The fingerprint already changes automatically when the model, dimensions,
 * endpoint or options change. When the vector space changes but the visible
 * configuration does not (e.g. re-deployed weights behind the same alias), an
 * administrator bumps embedding_profile_version instead.
 *
 * Only the version is touched: enabled, defaults, dimensions and
 * capabilities are never modified. The package never re-indexes; the host
 * compares stored fingerprints to detect stale vectors.
 */
final class EmbeddingProfileVersioner
{
    /**
     * Increment the model's embedding profile version atomically.
     *
     * @return int The new version.
     */
    public function bump(AiProviderModel $model): int
    {
        return DB::transaction(function () use ($model): int {
            /** @var AiProviderModel $current */
            $current = AiProviderModel::query()
                ->whereKey($model->id)
                ->lockForUpdate()
                ->firstOrFail();

            $next = ($current->embedding_profile_version ?? 1) + 1;

            $current->forceFill(['embedding_profile_version' => $next])->save();
            $model->setAttribute('embedding_profile_version', $next);

            return $next;
        });
    }
}
