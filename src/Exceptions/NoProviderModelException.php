<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Exceptions;

use HomeSide\AiAgents\Configuration\Capability;
use RuntimeException;

/**
 * Raised when no provider model satisfies a capability (plus its secondary
 * requirements) for the resolved provider.
 *
 * The resolver never silently falls back to another model once the caller
 * has pinned one, and never degrades privacy to find a match — failing
 * loudly is the only safe behaviour.
 */
final class NoProviderModelException extends RuntimeException
{
    /**
     * No default model is configured for the capability.
     */
    public static function noDefault(string $providerName, Capability $capability): self
    {
        return new self(
            "The provider [{$providerName}] has no default model for the "
            ."[{$capability->value}] capability.",
        );
    }

    /**
     * The pinned model does not belong to the provider it is resolved against.
     */
    public static function notOwned(string $modelId, string $providerName): self
    {
        return new self(
            "The provider model [{$modelId}] does not belong to the provider "
            ."[{$providerName}].",
        );
    }

    /**
     * The pinned/selected model exists but is disabled.
     */
    public static function disabled(string $model): self
    {
        return new self("The provider model [{$model}] is disabled.");
    }

    /**
     * The model does not support a required capability.
     *
     * @param  list<string>  $missing  The missing capability values.
     */
    public static function missingCapabilities(string $model, Capability $capability, array $missing): self
    {
        return new self(sprintf(
            'The provider model [%s] does not support the [%s] capability: missing %s.',
            $model,
            $capability->value,
            implode(', ', $missing),
        ));
    }

    /**
     * The provider model id could not be found at all.
     */
    public static function notFound(string $modelId): self
    {
        return new self("The provider model [{$modelId}] could not be found.");
    }
}
