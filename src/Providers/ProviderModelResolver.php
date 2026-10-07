<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Enums\AiDriver;
use HomeSide\AiAgents\Exceptions\NoProviderModelException;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;

/**
 * Resolves the concrete AiProviderModel for a capability.
 *
 * Separate from {@see ProviderResolver} on purpose: that one owns
 * scope/module/privacy/fallback; this one owns model/capability/enabled/
 * compatibility. Every path starts from an already authorised AiProvider —
 * a raw UUID is never trusted.
 */
final class ProviderModelResolver
{
    /**
     * Resolve the provider's default model for a capability.
     *
     * @param  list<Capability>  $requiredCapabilities  Secondary capabilities
     *                                                  the model must also satisfy.
     *
     * @throws NoProviderModelException When there is no default, it is
     *                                  disabled, or it lacks a capability.
     */
    public function resolveDefault(
        AiProvider $provider,
        Capability $capability,
        array $requiredCapabilities = [],
    ): AiProviderModel {
        $default = $provider->modelDefaults()
            ->where('capability', $capability->value)
            ->first();

        if ($default === null) {
            throw NoProviderModelException::noDefault($provider->name, $capability);
        }

        return $this->authorize(
            $provider,
            $default->model()->first() ?? throw NoProviderModelException::notFound((string) $default->ai_provider_model_id),
            $capability,
            $requiredCapabilities,
        );
    }

    /**
     * Resolve a specific model, verifying ownership and capabilities.
     *
     * Knowing a model UUID never bypasses the resolver: it must belong to
     * the given provider, be enabled and support the capability.
     *
     * @param  list<Capability>  $requiredCapabilities
     *
     * @throws NoProviderModelException
     */
    public function resolveExplicit(
        AiProvider $provider,
        string $providerModelId,
        Capability $capability,
        array $requiredCapabilities = [],
    ): AiProviderModel {
        $model = AiProviderModel::query()->find($providerModelId)
            ?? throw NoProviderModelException::notFound($providerModelId);

        return $this->authorize($provider, $model, $capability, $requiredCapabilities);
    }

    /**
     * Resolve the text model for an agent execution, honouring the legacy
     * transition chain:
     *
     * 1. explicit provider_model_id (validated against the provider);
     * 2. legacy model string (ModuleAiConfiguration.model);
     * 3. stored default capability=Text;
     * 4. legacy AiProvider.model (transition only).
     *
     * @param  list<Capability>  $requiredCapabilities  The agent's required
     *                                                  capabilities (secondary
     *                                                  to Text).
     *
     * @throws NoProviderModelException When nothing resolves.
     */
    public function resolveForAgent(
        AiProvider $provider,
        ?string $providerModelId,
        ?string $legacyModel,
        array $requiredCapabilities = [],
    ): AiProviderModel {
        if ($providerModelId !== null && $providerModelId !== '') {
            return $this->resolveExplicit($provider, $providerModelId, Capability::Text, $requiredCapabilities);
        }

        if ($legacyModel !== null && $legacyModel !== '') {
            $model = $provider->models()->where('model', $legacyModel)->first();

            if ($model !== null) {
                return $this->authorize($provider, $model, Capability::Text, $requiredCapabilities);
            }

            if ($legacyModel === $provider->model) {
                return $this->legacyModel($provider);
            }

            throw NoProviderModelException::notFound($legacyModel);
        }

        $default = $provider->modelDefaults()
            ->where('capability', Capability::Text->value)
            ->first();

        if ($default !== null) {
            $model = $default->model()->first();

            if ($model !== null) {
                return $this->authorize($provider, $model, Capability::Text, $requiredCapabilities);
            }
        }

        // Transition only: no stored default, fall back to the legacy
        // provider.model so existing installations keep working.
        if ($provider->model !== '') {
            return $this->legacyModel($provider);
        }

        throw NoProviderModelException::noDefault($provider->name, Capability::Text);
    }

    /**
     * Verify ownership, enabled state and capability support.
     *
     * @param  list<Capability>  $requiredCapabilities
     *
     * @throws NoProviderModelException
     */
    private function authorize(
        AiProvider $provider,
        AiProviderModel $model,
        Capability $capability,
        array $requiredCapabilities,
    ): AiProviderModel {
        if ($model->ai_provider_id !== $provider->id) {
            throw NoProviderModelException::notOwned($model->id, $provider->name);
        }

        if (! $model->enabled) {
            throw NoProviderModelException::disabled($model->model);
        }

        $driver = AiDriver::fromColumn($provider->type)->value;
        $effective = $model->effectiveCapabilities($driver);

        $missing = [];
        foreach ([$capability, ...$requiredCapabilities] as $required) {
            if (! in_array($required, $effective, true)) {
                $missing[] = $required->value;
            }
        }

        if ($missing !== []) {
            throw NoProviderModelException::missingCapabilities($model->model, $capability, $missing);
        }

        return $model;
    }

    /**
     * Build an unsaved model for the legacy AiProvider.model transition.
     */
    private function legacyModel(AiProvider $provider): AiProviderModel
    {
        /** @var AiProviderModel $model */
        $model = new AiProviderModel([
            'ai_provider_id' => $provider->id,
            'model' => $provider->model,
            'enabled' => true,
        ]);

        return $model;
    }
}
