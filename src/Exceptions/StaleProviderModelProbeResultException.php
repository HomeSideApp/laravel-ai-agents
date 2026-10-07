<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Exceptions;

use RuntimeException;

/**
 * Raised when a probe result no longer matches the current model state.
 *
 * A probe result is an optimistic snapshot taken at T0. Applying it is only
 * safe if the relevant model state is still compatible at T1; otherwise the
 * result is STALE and must be rejected rather than overwriting newer
 * configuration (which could hide an unexpected model change).
 */
final class StaleProviderModelProbeResultException extends RuntimeException
{
    /**
     * The probed dimensions differ from the model's current dimensions.
     */
    public static function dimensionsChanged(string $model, int $probed, int $current): self
    {
        return new self(
            "The probe result for model [{$model}] is stale: probed {$probed} dimensions, "
            ."but the model now declares {$current}.",
        );
    }

    /**
     * The probe VERIFIED configured dimensions that have since been removed.
     */
    public static function dimensionsRemoved(string $model, int $probed): self
    {
        return new self(
            "The probe result for model [{$model}] is stale: it verified {$probed} dimensions, "
            .'but the model no longer declares any.',
        );
    }
}
