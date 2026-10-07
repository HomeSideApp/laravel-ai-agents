<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Configuration;

use RuntimeException;

/**
 * Resolves the effective capabilities of a model/provider by combining:
 * 1. Driver baseline (per provider type)
 * 2. Probe detection (capabilities_detected on ai_provider_models)
 * 3. Manual override (capabilities_override on ai_provider_models)
 *
 * effective = override ?? detected ?? driver_baseline
 *
 * It also validates that the effective capabilities cover the agent's
 * requirements.
 */
final class CapabilityResolver
{
    /**
     * Calculate the effective capabilities of a provider model.
     *
     * @param  string  $driver  The provider driver (openai, ollama, etc.)
     * @param  array<string>|null  $detected  Capabilities detected by the probe.
     * @param  array<string>|null  $override  Manual capability overrides.
     * @return Capability[]
     */
    public function resolve(
        string $driver,
        ?array $detected = null,
        ?array $override = null,
    ): array {
        if ($override !== null && $override !== []) {
            return array_map(
                fn (string $c) => Capability::from($c),
                $override,
            );
        }

        if ($detected !== null && $detected !== []) {
            return array_map(
                fn (string $c) => Capability::from($c),
                $detected,
            );
        }

        // No explicit per-model signal: trust the driver baseline for its
        // text features, but strip capabilities that require explicit model
        // support (Embeddings, Reranking). A driver that *can* embed does
        // not mean every model it serves can, so those must be declared
        // per model (detected/override) to be routable.
        return array_values(array_filter(
            Capability::driverBaseline($driver),
            static fn (Capability $capability): bool => ! $capability->requiresExplicitModelSupport(),
        ));
    }

    /**
     * Determine which required capabilities are missing from the effective set.
     *
     * @param  Capability[]  $effective
     * @param  Capability[]  $required
     * @return Capability[] Missing capabilities (empty when compatible)
     */
    public function missing(
        array $effective,
        array $required,
    ): array {
        $effectiveKeys = array_column($effective, 'value');

        return array_values(array_filter(
            $required,
            fn (Capability $req) => ! in_array($req->value, $effectiveKeys, true),
        ));
    }

    /**
     * Determine whether a model covers all of an agent's requirements.
     *
     * @param  Capability[]  $effective  The model's effective capabilities.
     * @param  Capability[]  $required  The agent's required capabilities.
     * @return bool True when nothing from the required set is missing.
     */
    public function isCompatible(
        array $effective,
        array $required,
    ): bool {
        return $this->missing($effective, $required) === [];
    }

    /**
     * Assert compatibility, failing loudly when the model falls short.
     *
     * Use at execution boundaries where proceeding with a partial model
     * would produce broken output (e.g. structured output on a text-only
     * model) rather than a soft degradation.
     *
     * @param  Capability[]  $effective  The model's effective capabilities.
     * @param  Capability[]  $required  The agent's required capabilities.
     * @param  string  $agentKey  The agent key, echoed into the message.
     * @param  string  $model  The model identifier, echoed into the message.
     *
     * @throws RuntimeException When any required capability is missing; the
     *                          message lists each one by value.
     */
    public function ensureCompatible(
        array $effective,
        array $required,
        string $agentKey = '',
        string $model = '',
    ): void {
        $missing = $this->missing($effective, $required);

        if ($missing === []) {
            return;
        }

        $missingNames = array_map(fn (Capability $c) => $c->value, $missing);

        throw new RuntimeException(sprintf(
            'The model "%s" does not support the capabilities required by the agent "%s": %s',
            $model,
            $agentKey,
            implode(', ', $missingNames),
        ));
    }
}
