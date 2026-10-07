<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Contracts\ResolvesTenant;
use HomeSide\AiAgents\Enums\FallbackPolicy;
use HomeSide\AiAgents\Enums\PrivacyLevel;
use HomeSide\AiAgents\Enums\TenantIsolation;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves AI providers consistently across every application module.
 *
 * Modules are plain strings, so hosts may declare domain-specific modules
 * without touching package code. When a module has no dedicated provider,
 * the resolver falls back to the module configured in
 * config('ai-agents.fallback_module') (default 'general').
 *
 * Scope priority is user → tenant → system: the user's personal provider
 * wins, then the tenant's, then the installation-wide one. Within a scope,
 * the requested module takes priority over the fallback module.
 *
 * Privacy governance: once a provider is chosen, its fallback_policy gates
 * every subsequent candidate (fallback module and lower scopes). Candidates
 * whose privacy_level does not satisfy the chosen provider's policy are
 * excluded, so a local provider with local_only can never silently degrade
 * to cloud.
 */
final class ProviderResolver
{
    public function __construct(
        private readonly ResolvesTenant $tenantResolver,
    ) {}

    /**
     * Resolve the highest-priority provider honouring privacy fallback policy.
     *
     * Walks the scope chain user → tenant → system. The first provider found
     * in a scope becomes the primary; its fallback_policy then filters every
     * later candidate. When the primary comes from the fallback module
     * (module degradation), lower-priority candidates for the requested
     * module may still be considered only if the policy accepts them.
     *
     * @param  string  $module  The module that needs an AI provider.
     * @param  int|string|null  $userId  The user identifier used for personal providers.
     * @param  int|string|null  $tenantId  The explicit tenant identifier, if any.
     * @return AiProvider|null The selected provider, or null when no accessible
     *                         enabled provider satisfies the privacy policy.
     */
    public function resolve(
        string $module,
        int|string|null $userId = null,
        int|string|null $tenantId = null,
    ): ?AiProvider {
        $accessibleTenantId = $this->tenantResolver->resolveAccessible($userId, $tenantId);

        // Scope chain in priority order: user → tenant → system.
        $scopes = [];

        if ($userId !== null) {
            $scopes[] = ['userId' => $userId, 'tenantId' => null];
        }

        if ($accessibleTenantId !== null) {
            $scopes[] = ['userId' => null, 'tenantId' => $accessibleTenantId];
        }

        $scopes[] = ['userId' => null, 'tenantId' => null];

        // First provider found in a scope becomes the primary and its
        // fallback_policy governs the rest of the chain: later candidates
        // whose privacy_level the policy rejects are skipped. A candidate
        // that serves the requested module while the primary only exists
        // through module degradation replaces it — when the policy allows.
        $primary = null;

        foreach ($scopes as $scope) {
            $candidate = $this->resolveForScope(
                $module,
                userId: $scope['userId'],
                tenantId: $scope['tenantId'],
            );

            if ($candidate === null) {
                continue;
            }

            if ($primary === null) {
                $primary = $candidate;

                continue;
            }

            $policy = FallbackPolicy::fromColumn($primary->fallback_policy);

            $policyAccepts = $policy->accepts(
                PrivacyLevel::fromColumn($primary->privacy_level),
                PrivacyLevel::fromColumn($candidate->privacy_level),
            );

            $candidateServesRequestedModule = $candidate->servesModule($module)
                && ! $primary->servesModule($module);

            if ($policyAccepts && $candidateServesRequestedModule) {
                $primary = $candidate;
            }
        }

        return $primary;
    }

    /**
     * Resolve a provider and register it as a dynamic Laravel AI SDK provider.
     *
     * @param  string  $module  The module that needs an AI provider.
     * @param  DynamicProviderRegistrar  $registrar  The registrar that exposes the provider to the SDK.
     * @param  int|string|null  $userId  The user identifier used for personal providers.
     * @param  int|string|null  $tenantId  The explicit tenant identifier, if any.
     *
     * @throws \RuntimeException When no accessible enabled provider is configured.
     */
    public function resolveAndRegister(
        string $module,
        DynamicProviderRegistrar $registrar,
        int|string|null $userId = null,
        int|string|null $tenantId = null,
    ): ResolvedProviderData {
        $provider = $this->resolve($module, $userId, $tenantId);

        if ($provider === null) {
            throw new \RuntimeException(
                "No enabled AI provider is configured for module [{$module}].",
            );
        }

        $dynamicName = $registrar->register($provider);

        return new ResolvedProviderData(
            provider: $provider,
            dynamicName: $dynamicName,
        );
    }

    /**
     * Resolve the best provider that has a default model for a capability,
     * without ever violating the privacy fallback policy of the provider the
     * scope chain would normally select.
     *
     * The normally-resolved provider acts as a PRIVACY ANCHOR: if it already
     * serves the capability it is returned; otherwise candidates are walked
     * in the SAME priority order as resolve() (user → tenant → system, and
     * within a scope requested module → fallback module), keeping only those
     * the anchor's fallback policy accepts. Iterating the ordered chain — not
     * the unordered listAvailable() — is what keeps a lower scope from
     * winning over a higher one.
     *
     * A local_only anchor with no embedding model therefore fails instead of
     * silently degrading to a cloud embedding provider: the caller sees null
     * and raises a capability-specific exception.
     *
     * @param  string  $module  The module that needs the capability.
     * @param  Capability  $capability  The routable capability to resolve for.
     * @param  int|string|null  $userId  The user identifier used for personal providers.
     * @param  int|string|null  $tenantId  The explicit tenant identifier, if any.
     * @return AiProvider|null The provider with the capability (anchor first), or null.
     */
    public function resolveForCapability(
        string $module,
        Capability $capability,
        int|string|null $userId = null,
        int|string|null $tenantId = null,
    ): ?AiProvider {
        $anchor = $this->resolve($module, $userId, $tenantId);

        if ($anchor === null) {
            return null;
        }

        if ($this->hasCapabilityDefault($anchor, $capability)) {
            return $anchor;
        }

        $policy = FallbackPolicy::fromColumn($anchor->fallback_policy);
        $anchorPrivacy = PrivacyLevel::fromColumn($anchor->privacy_level);

        foreach ($this->orderedScopes($module, $userId, $tenantId) as $scope) {
            $candidate = $this->resolveForScopeAndModule($scope['module'], $scope['userId'], $scope['tenantId']);

            if ($candidate === null || $candidate->id === $anchor->id) {
                continue;
            }

            if (! $this->hasCapabilityDefault($candidate, $capability)) {
                continue;
            }

            if (! $policy->accepts($anchorPrivacy, PrivacyLevel::fromColumn($candidate->privacy_level))) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Validate and return an explicitly pinned provider for a capability.
     *
     * Knowing a provider UUID never bypasses authorisation: the provider must
     * be accessible in the caller's scope chain, enabled, serve the module
     * (or the fallback module), declare a default for the capability and be
     * accepted by the privacy anchor's fallback policy. This is the
     * provider-level equivalent of the conversation access guard, so an
     * explicit id cannot turn into an IDOR.
     *
     * @param  string  $providerId  The pinned provider id.
     * @param  string  $module  The module that needs the capability.
     * @param  Capability  $capability  The routable capability to resolve for.
     * @param  int|string|null  $userId  The user identifier used for personal providers.
     * @param  int|string|null  $tenantId  The explicit tenant identifier, if any.
     * @param  string|null  $pinnedModelId  An explicitly pinned model id, which
     *                                      removes the need for a default.
     * @return AiProvider|null The pinned provider when fully authorised, or null.
     */
    public function resolveExplicitForCapability(
        string $providerId,
        string $module,
        Capability $capability,
        int|string|null $userId = null,
        int|string|null $tenantId = null,
        ?string $pinnedModelId = null,
    ): ?AiProvider {
        $provider = AiProvider::query()->find($providerId);

        if ($provider === null || ! $provider->enabled) {
            return null;
        }

        if (! $this->isAccessible($provider, $userId, $tenantId)) {
            return null;
        }

        $fallbackModule = (string) config('ai-agents.fallback_module', 'general');

        if (! $provider->servesModule($module) && ! $provider->servesModule($fallbackModule)) {
            return null;
        }

        // A pinned model id is validated separately by ProviderModelResolver,
        // so a default is only required when no explicit model was supplied.
        if (($pinnedModelId === null || $pinnedModelId === '')
            && ! $this->hasCapabilityDefault($provider, $capability)) {
            return null;
        }

        // Privacy anchor: even a pinned provider must satisfy the fallback
        // policy of the provider the scope chain would normally select.
        $anchor = $this->resolve($module, $userId, $tenantId);

        if ($anchor !== null && $anchor->id !== $provider->id) {
            $policy = FallbackPolicy::fromColumn($anchor->fallback_policy);

            if (! $policy->accepts(
                PrivacyLevel::fromColumn($anchor->privacy_level),
                PrivacyLevel::fromColumn($provider->privacy_level),
            )) {
                return null;
            }
        }

        return $provider;
    }

    /**
     * The ordered scope + module chain used for capability resolution.
     *
     * Priority: user → tenant → system; within a scope, the requested module
     * precedes the fallback module. This mirrors resolve()'s walk so
     * capability fallback never inverts scope precedence.
     *
     * @return list<array{userId: int|string|null, tenantId: int|string|null, module: string}>
     */
    private function orderedScopes(
        string $module,
        int|string|null $userId,
        int|string|null $tenantId,
    ): array {
        $accessibleTenantId = $this->tenantResolver->resolveAccessible($userId, $tenantId);
        $fallbackModule = (string) config('ai-agents.fallback_module', 'general');

        $scopes = [];

        if ($userId !== null) {
            $scopes[] = ['userId' => $userId, 'tenantId' => null];
        }

        if ($accessibleTenantId !== null) {
            $scopes[] = ['userId' => null, 'tenantId' => $accessibleTenantId];
        }

        $scopes[] = ['userId' => null, 'tenantId' => null];

        $chain = [];

        foreach ($scopes as $scope) {
            $modules = $module === $fallbackModule ? [$module] : [$module, $fallbackModule];

            foreach ($modules as $candidateModule) {
                $chain[] = [
                    'userId' => $scope['userId'],
                    'tenantId' => $scope['tenantId'],
                    'module' => $candidateModule,
                ];
            }
        }

        return $chain;
    }

    /**
     * Whether the provider is reachable from the caller's scope chain.
     *
     * Global providers are always reachable; a personal provider only by its
     * owner (and only without a tenant); a tenant provider only when the
     * caller's tenant is authorised. In database isolation mode each tenant
     * already has its own connection, so the scope is not re-checked.
     *
     * @param  int|string|null  $userId  The caller's user id.
     * @param  int|string|null  $tenantId  The explicit tenant id, if any.
     */
    private function isAccessible(AiProvider $provider, int|string|null $userId, int|string|null $tenantId): bool
    {
        if ($this->tenantResolver->isolation() === TenantIsolation::Database) {
            return $this->tenantResolver->enabled() || $provider->isGlobal();
        }

        $foreignKey = $this->tenantForeignKey();

        if ($provider->isGlobal()) {
            return true;
        }

        if ($userId !== null
            && $provider->user_id !== null
            && (string) $provider->user_id === (string) $userId
            && ($foreignKey === null || $provider->{$foreignKey} === null)) {
            return true;
        }

        $accessibleTenantId = $this->tenantResolver->resolveAccessible($userId, $tenantId);

        return $accessibleTenantId !== null
            && is_string($foreignKey)
            && $provider->user_id === null
            && $provider->{$foreignKey} !== null
            && (string) $provider->{$foreignKey} === (string) $accessibleTenantId;
    }

    /**
     * Whether the provider declares a default model for the capability.
     */
    private function hasCapabilityDefault(AiProvider $provider, Capability $capability): bool
    {
        return $provider->modelDefaults()
            ->where('capability', $capability->value)
            ->exists();
    }

    /**
     * List every enabled provider available through the user, tenant and system scopes.
     *
     * @param  int|string|null  $userId  The user identifier used for personal providers.
     * @param  int|string|null  $tenantId  The explicit tenant identifier, if any.
     * @return Collection<int, AiProvider> The enabled providers available to the execution context.
     */
    public function listAvailable(int|string|null $userId = null, int|string|null $tenantId = null): Collection
    {
        $accessibleTenantId = $this->tenantResolver->resolveAccessible($userId, $tenantId);
        $foreignKey = $this->tenantForeignKey();

        return AiProvider::query()
            ->where('enabled', true)
            ->where(function (Builder $query) use ($accessibleTenantId, $userId, $foreignKey): void {
                $query->where(function (Builder $globalQuery): void {
                    $globalQuery->global();
                });

                if ($userId !== null) {
                    $query->orWhere(function (Builder $userQuery) use ($userId, $foreignKey): void {
                        $userQuery->where('user_id', $userId);

                        if (is_string($foreignKey)) {
                            $userQuery->whereNull($foreignKey);
                        }
                    });
                }

                if ($accessibleTenantId !== null && is_string($foreignKey)) {
                    $query->orWhere(function (Builder $tenantQuery) use ($accessibleTenantId, $foreignKey): void {
                        $tenantQuery->where($foreignKey, $accessibleTenantId)
                            ->whereNull('user_id');
                    });
                }
            })
            ->get();
    }

    /**
     * Resolve the preferred provider for one scope, falling back to the configured fallback module.
     *
     * @param  string  $module  The requested module.
     * @param  int|string|null  $userId  The user scope identifier, or null when resolving another scope.
     * @param  int|string|null  $tenantId  The tenant scope identifier, or null when resolving another scope.
     * @return AiProvider|null The selected provider for the scope, or null when none is enabled.
     */
    private function resolveForScope(
        string $module,
        int|string|null $userId = null,
        int|string|null $tenantId = null,
    ): ?AiProvider {
        $provider = $this->resolveForScopeAndModule($module, $userId, $tenantId);

        $fallbackModule = (string) config('ai-agents.fallback_module', 'general');

        if ($provider !== null || $module === $fallbackModule) {
            return $provider;
        }

        return $this->resolveForScopeAndModule($fallbackModule, $userId, $tenantId);
    }

    /**
     * Resolve the default or oldest enabled provider for an exact scope and module.
     *
     * @param  string  $module  The exact module assigned to the provider.
     * @param  int|string|null  $userId  The user scope identifier, or null when resolving another scope.
     * @param  int|string|null  $tenantId  The tenant scope identifier, or null when resolving another scope.
     * @return AiProvider|null The matching provider, or null when the scope has no enabled match.
     */
    private function resolveForScopeAndModule(
        string $module,
        int|string|null $userId,
        int|string|null $tenantId,
    ): ?AiProvider {
        $foreignKey = $this->tenantForeignKey();

        $query = AiProvider::query()
            ->whereHas('moduleAssignments', fn (Builder $assignments): Builder => $assignments->where('module', $module))
            ->where('enabled', true);

        // Tenant scope: match the tenant row, or tenant-less rows when no
        // accessible tenant was resolved (only when tenancy is active).
        if (is_string($foreignKey)) {
            $query->when(
                $tenantId !== null,
                fn (Builder $q): Builder => $q->where($foreignKey, $tenantId)->whereNull('user_id'),
                fn (Builder $q): Builder => $q->whereNull($foreignKey),
            );
        }

        return $query
            ->when(
                $userId !== null,
                fn (Builder $q): Builder => $q->where('user_id', $userId),
                fn (Builder $q): Builder => $q->whereNull('user_id'),
            )
            ->orderByDesc(AiProviderModule::query()
                ->selectRaw('CASE WHEN default_scope_key IS NULL THEN 0 ELSE 1 END')
                ->whereColumn('ai_provider_id', 'ai_providers.id')
                ->where('module', $module)
                ->limit(1))
            ->oldest()
            ->orderBy('id')
            ->first();
    }

    /**
     * The configured tenant foreign key, or null when tenant support is disabled.
     *
     * @return string|null The column name to scope by; null disables every
     *                     tenant condition in the resolver's queries.
     */
    private function tenantForeignKey(): ?string
    {
        // In database mode there is no FK column — each tenant has its own
        // database, so scoping queries by tenant_id is redundant.
        if ($this->tenantResolver->isolation() !== TenantIsolation::Column) {
            return null;
        }

        return $this->tenantResolver->foreignKey();
    }
}
