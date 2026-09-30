<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models;

use HomeSide\AiAgents\Database\Factories\AiProviderFactory;
use HomeSide\AiAgents\Enums\FallbackPolicy;
use HomeSide\AiAgents\Enums\PrivacyLevel;
use HomeSide\AiAgents\Enums\TenantIsolation;
use HomeSide\AiAgents\Models\Concerns\ValidatesOnWrite;
use HomeSide\AiAgents\Providers\AiProviderEndpointPolicy;
use HomeSide\AiAgents\Providers\DynamicProviderRegistrar;
use HomeSide\AiAgents\Tenancy\BelongsToTenant;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * An AI provider configuration, scoped to a tenant (optional), a user or the
 * whole application.
 *
 * Use `createValidated()` / `updateValidated()` to get SSRF-safe URLs, scope
 * exclusivity and driver validation for free. Plain `create()` / `save()`
 * remain available for hosts that manage validation themselves — the
 * `encrypted` cast guarantees the API key is never persisted in plaintext
 * through either path.
 *
 * @property string $id The unique identifier for the provider (UUID).
 * @property string|null $user_id The id of the user the provider belongs to, if any.
 * @property string|null $household_id The tenant id (column name configurable), if any.
 * @property string $name The display name of the provider.
 * @property string $type The type of the provider (e.g. openai-compatible).
 * @property string $driver The driver used to connect to the provider.
 * @property string $base_url The base URL of the provider.
 * @property string $model The default model of the provider.
 * @property string $api_key The API key — encrypted at rest by the `encrypted`
 *                           cast, transparently decrypted on read and hidden
 *                           from serialisation.
 * @property bool $enabled Whether the provider is enabled.
 * @property string $module The module identifier the provider serves (host-defined string).
 * @property array<string, mixed>|null $configuration Additional configuration options of the provider.
 * @property string|null $created_by The id of the user who created the provider, if any.
 * @property bool $is_default Whether the provider is the default for its scope.
 * @property string|null $privacy_level The privacy level of the provider.
 * @property string|null $fallback_policy The fallback policy of the provider.
 * @property string|null $family The model family (catalog-aligned), if any.
 * @property string|null $description The model description (catalog-aligned), if any.
 * @property bool $attachment Whether the model supports file attachments.
 * @property bool $reasoning Whether the model supports reasoning.
 * @property array<int, array<string, mixed>>|null $reasoning_options Reasoning configuration options.
 * @property bool $tool_call Whether the model supports tool/function calling.
 * @property bool $structured_output Whether the model supports structured output.
 * @property bool $temperature Whether the model supports temperature control.
 * @property bool $open_weights Whether the model has open weights.
 * @property list<string>|null $modalities_input Input modalities (text, image, audio, video).
 * @property list<string>|null $modalities_output Output modalities.
 * @property int|null $context_window The context window size in tokens.
 * @property int|null $max_input_tokens The maximum input tokens, if declared separately.
 * @property int|null $max_output_tokens The maximum output tokens.
 * @property string|null $cost_input USD cost per million input tokens.
 * @property string|null $cost_output USD cost per million output tokens.
 * @property string|null $cost_cache_read USD cost per million cached input tokens read.
 * @property string|null $cost_cache_write USD cost per million cached input tokens written.
 * @property Carbon|null $created_at The timestamp when the provider was created.
 * @property Carbon|null $updated_at The timestamp when the provider was last updated.
 *
 * Relationships:
 * @property Model|null $user The user the provider belongs to (resolved via config).
 * @property Model|null $creator The user who created the provider, if any.
 * @property Collection<int, AiProviderModel> $models The models exposed by the provider.
 * @property Collection<int, ModuleAiConfiguration> $moduleConfigurations The module configurations of the provider.
 */
class AiProvider extends Model
{
    use BelongsToTenant, HasUuids;

    /** @use HasFactory<AiProviderFactory> */
    use HasFactory;

    use ValidatesOnWrite {
        createValidated as protected createValidatedRow;
        updateValidated as protected updateValidatedRow;
    }

    /** @param array<string, mixed> $attributes */
    public static function createValidated(array $attributes): static
    {
        $modules = self::extractModules($attributes);

        return DB::transaction(function () use ($attributes, $modules): static {
            $provider = static::createValidatedRow($attributes);
            $provider->setModules($modules);

            return $provider;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateValidated(array $attributes): bool
    {
        $modules = self::extractModules($attributes, $this);

        return DB::transaction(function () use ($attributes, $modules): bool {
            $saved = $this->updateValidatedRow($attributes);
            $this->setModules($modules);

            return $saved;
        });
    }

    /** @param array<string, mixed> $attributes
     * @return list<string>
     */
    private static function extractModules(array &$attributes, ?self $provider = null): array
    {
        $hasModule = array_key_exists('module', $attributes);
        $hasModules = array_key_exists('modules', $attributes);

        if ($hasModule && $hasModules) {
            throw ValidationException::withMessages(['modules' => 'Use either module or modules, not both.']);
        }

        $modules = $hasModules
            ? $attributes['modules']
            : ($hasModule ? [$attributes['module']] : $provider?->assignedModules() ?? []);

        Validator::make(['modules' => $modules], [
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => ['required', 'string', 'distinct', 'max:255', 'regex:/^[a-z0-9_]+$/'],
        ])->validate();

        unset($attributes['modules']);

        if (! $hasModule && $hasModules) {
            $attributes['module'] = $modules[0];
        }

        return $modules;
    }

    protected static function booted(): void
    {
        static::created(function (self $provider): void {
            $provider->moduleAssignments()->create([
                'module' => $provider->legacyModuleName(),
                'default_scope_key' => null,
            ]);

            if ($provider->is_default) {
                $provider->markAsDefaultForModule($provider->legacyModuleName());
            }
        });
    }

    /**
     * Attributes never included in arrays/JSON serialisation.
     *
     * The encrypted cast would auto-decrypt api_key on read, so leaving it
     * visible would leak the plaintext key through any toArray()/toJson()
     * path — API resources, logs, debugging dumps.
     *
     * @var list<string>
     */
    protected $hidden = ['api_key'];

    /**
     * api_key IS fillable on purpose: the `encrypted` cast encrypts whatever
     * enters it before persistence, so mass assignment cannot store plaintext
     * — the worst an attacker can do is store an encrypted key they know,
     * which is equivalent to stealing it anyway. Hiding it from fillable
     * would push hosts toward ad-hoc attribute writes that skip validation.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'name', 'type', 'driver', 'base_url', 'model',
        'api_key', 'enabled', 'module', 'configuration', 'created_by',
        'is_default', 'privacy_level', 'fallback_policy',
        // Catalog-aligned spec columns (mirror models_dev_models).
        'family', 'description',
        'attachment', 'reasoning', 'reasoning_options',
        'tool_call', 'structured_output', 'temperature', 'open_weights',
        'modalities_input', 'modalities_output',
        'context_window', 'max_input_tokens', 'max_output_tokens',
        'cost_input', 'cost_output', 'cost_cache_read', 'cost_cache_write',
    ];

    /**
     * Get the attribute casts for the model.
     *
     * The `encrypted` cast is the single source of encryption: it encrypts on
     * write and decrypts on read, no matter which write path was used
     * (validated helpers, mass assignment, explicit set). The module column
     * stays a plain string so hosts can declare domain-specific modules
     * without touching package code.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'enabled' => 'boolean',
            'is_default' => 'boolean',
            'configuration' => 'array',
            // Catalog-aligned spec columns.
            'attachment' => 'boolean',
            'reasoning' => 'boolean',
            'reasoning_options' => 'array',
            'tool_call' => 'boolean',
            'structured_output' => 'boolean',
            'temperature' => 'boolean',
            'open_weights' => 'boolean',
            'modalities_input' => 'array',
            'modalities_output' => 'array',
            'context_window' => 'integer',
            'max_input_tokens' => 'integer',
            'max_output_tokens' => 'integer',
            'cost_input' => 'decimal:6',
            'cost_output' => 'decimal:6',
            'cost_cache_read' => 'decimal:6',
            'cost_cache_write' => 'decimal:6',
        ];
    }

    /**
     * Validation rules for validated writes.
     *
     * @return array<string, mixed>
     */
    protected static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! array_key_exists((string) $value, DynamicProviderRegistrar::supportedDrivers())) {
                    $fail("The provider type [{$value}] is not supported.");
                }
            }],
            'driver' => ['nullable', 'string', 'max:255'],
            'base_url' => ['required', 'url', 'max:2048'],
            'model' => ['required', 'string', 'max:255'],
            // Catalog-aligned spec columns.
            'family' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'attachment' => ['boolean'],
            'reasoning' => ['boolean'],
            'reasoning_options' => ['nullable', 'array'],
            'tool_call' => ['boolean'],
            'structured_output' => ['boolean'],
            'temperature' => ['boolean'],
            'open_weights' => ['boolean'],
            'modalities_input' => ['nullable', 'array'],
            'modalities_input.*' => ['string', Rule::in(['text', 'image', 'audio', 'video'])],
            'modalities_output' => ['nullable', 'array'],
            'modalities_output.*' => ['string', Rule::in(['text', 'image', 'audio', 'video'])],
            'context_window' => ['nullable', 'integer', 'min:1'],
            'max_input_tokens' => ['nullable', 'integer', 'min:1'],
            'max_output_tokens' => ['nullable', 'integer', 'min:1'],
            'cost_input' => ['nullable', 'numeric', 'min:0'],
            'cost_output' => ['nullable', 'numeric', 'min:0'],
            'cost_cache_read' => ['nullable', 'numeric', 'min:0'],
            'cost_cache_write' => ['nullable', 'numeric', 'min:0'],
            // The key is required on creation only when the row does not
            // carry one yet; updateValidated backfills the current value so
            // partial updates omitting api_key still validate.
            'api_key' => ['required', 'string', 'min:8'],
            'enabled' => ['boolean'],
            'module' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/'],
            'configuration' => ['nullable', 'array'],
            // user_id / created_by: int or UUID depending on the host's user
            // model key; referential integrity is enforced by the database.
            'user_id' => ['nullable'],
            'created_by' => ['nullable'],
            'is_default' => ['boolean'],
            'privacy_level' => ['nullable', 'string', Rule::in(array_column(PrivacyLevel::cases(), 'value'))],
            'fallback_policy' => ['nullable', 'string', Rule::in(array_column(FallbackPolicy::cases(), 'value'))],
        ];
    }

    /**
     * Security and cross-field checks applied on validated writes:
     * SSRF-safe endpoint, exclusive scopes, user id types.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected static function validateSecurity(array $attributes, ValidatorContract $validator, ?self $model = null): void
    {
        // 1. SSRF-safe endpoint (blocks cloud metadata always; private
        //    ranges depend on the configured mode).
        $baseUrl = $attributes['base_url'] ?? null;

        if (is_string($baseUrl) && $baseUrl !== '') {
            try {
                app(AiProviderEndpointPolicy::class)->validate($baseUrl);
            } catch (\InvalidArgumentException $e) {
                $validator->errors()->add('base_url', $e->getMessage());
            }
        }

        // 2. Tenant and user scopes are mutually exclusive: a provider is
        //    either tenant-owned, user-owned or global. The `scope` attribute
        //    is already resolved into physical columns at this point.
        $foreignKey = static::resolveTenantForeignKey();
        $tenantId = $foreignKey !== null && array_key_exists($foreignKey, $attributes)
            ? $attributes[$foreignKey]
            : $model?->{$foreignKey ?? ''};

        if ($foreignKey !== null && ($tenantId ?? null) !== null && ($attributes['user_id'] ?? $model?->user_id) !== null) {
            $validator->errors()->add('scope', 'A provider cannot belong to both a tenant and a user.');
        }
    }

    /**
     * The user the provider belongs to (resolved via config('ai-agents.user_model')).
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = (string) config('ai-agents.user_model');

        return $this->belongsTo($userModel, 'user_id');
    }

    /**
     * The user who created the provider (resolved via config('ai-agents.user_model')).
     *
     * @return BelongsTo<Model, $this>
     */
    public function creator(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = (string) config('ai-agents.user_model');

        return $this->belongsTo($userModel, 'created_by');
    }

    /**
     * @return HasMany<AiProviderModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiProviderModel::class, 'ai_provider_id');
    }

    /** @return HasMany<AiProviderModule, $this> */
    public function moduleAssignments(): HasMany
    {
        return $this->hasMany(AiProviderModule::class, 'ai_provider_id');
    }

    /** @return list<string> */
    public function assignedModules(): array
    {
        return array_values(array_map('strval', $this->moduleAssignments()->orderBy('module')->pluck('module')->all()));
    }

    /** @return list<string> */
    public function defaultModules(): array
    {
        return array_values(array_map('strval', $this->moduleAssignments()->whereNotNull('default_scope_key')->orderBy('module')->pluck('module')->all()));
    }

    /** @param list<string> $modules */
    public function setModules(array $modules): void
    {
        $modules = array_values(array_unique($modules));

        if ($modules === [] || array_filter($modules, fn (mixed $module): bool => ! is_string($module) || preg_match('/^[a-z0-9_]+$/', $module) !== 1)) {
            throw new \InvalidArgumentException('At least one valid module is required.');
        }

        DB::transaction(function () use ($modules): void {
            $this->moduleAssignments()->whereNotIn('module', $modules)->delete();

            foreach ($modules as $module) {
                $this->moduleAssignments()->firstOrCreate(['module' => $module]);
            }

            if (! in_array($this->legacyModuleName(), $modules, true)) {
                $this->update(['module' => $modules[0], 'is_default' => false]);
            }
        });
    }

    public function servesModule(string $module): bool
    {
        return $this->moduleAssignments()->where('module', $module)->exists();
    }

    public function markAsDefaultForModule(string $module): void
    {
        DB::transaction(function () use ($module): void {
            $assignment = $this->moduleAssignments()->where('module', $module)->firstOrFail();
            $scopeKey = $this->defaultScopeKey();

            AiProviderModule::query()
                ->where('module', $module)
                ->where('default_scope_key', $scopeKey)
                ->update(['default_scope_key' => null]);

            $assignment->update(['default_scope_key' => $scopeKey]);

            if ($this->legacyModuleName() === $module) {
                $legacySiblings = static::query()->where('module', $module)
                    ->where('user_id', $this->user_id)
                    ->where('id', '!=', $this->id);
                $foreignKey = $this->tenantColumn();

                if ($foreignKey !== null) {
                    $legacySiblings->where($foreignKey, $this->{$foreignKey});
                }

                $legacySiblings->update(['is_default' => false]);
                $this->update(['is_default' => true]);
            }
        });
    }

    private function defaultScopeKey(): string
    {
        if ($this->user_id !== null) {
            return 'user:'.$this->user_id;
        }

        $foreignKey = $this->tenantColumn();

        return $foreignKey !== null && $this->{$foreignKey} !== null
            ? 'tenant:'.$this->{$foreignKey}
            : 'system';
    }

    /**
     * @return HasMany<ModuleAiConfiguration, $this>
     */
    public function moduleConfigurations(): HasMany
    {
        return $this->hasMany(ModuleAiConfiguration::class);
    }

    /**
     * Determine whether the provider is system-wide.
     *
     * Global providers are eligible for every execution context that reaches
     * the system scope: no user owner and — when tenancy is on — no tenant
     * owner either.
     *
     * @return bool True when neither the user column nor the configured
     *              tenant column (if any) is set.
     */
    public function isGlobal(): bool
    {
        $foreignKey = $this->tenantColumn();

        return is_null($this->user_id) && ($foreignKey === null || is_null($this->{$foreignKey}));
    }

    /**
     * Determine whether the provider is user-personal.
     *
     * Personal providers take priority in resolution over global ones for
     * their owner; the provider must not carry a tenant at the same time.
     *
     * @return bool True when a user owner is set and the configured tenant
     *              column (if any) is null.
     */
    public function isUser(): bool
    {
        $foreignKey = $this->tenantColumn();

        return ! is_null($this->user_id) && ($foreignKey === null || is_null($this->{$foreignKey}));
    }

    /**
     * Promote this provider to the default of its exact scope and module.
     *
     * Clears is_default on every sibling provider sharing the same user,
     * module and (when tenancy is on) tenant value, then flags this one.
     * Guarantees at most one default per scope+module, which the resolver's
     * orderByDesc('is_default') relies on.
     */
    public function markAsDefault(): void
    {
        $this->markAsDefaultForModule($this->legacyModuleName());
    }

    /**
     * @param  Builder<AiProvider>  $query
     * @param  string  $module  The module to filter by.
     * @return Builder<AiProvider>
     */
    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->whereHas('moduleAssignments', fn (Builder $assignments): Builder => $assignments->where('module', $module))
            ->where('enabled', true);
    }

    /**
     * Restrict the query to system-wide providers with no tenant or user owner.
     *
     * @param  Builder<AiProvider>  $query  The provider query being scoped.
     * @return Builder<AiProvider> The query restricted to system-wide providers.
     */
    public function scopeGlobal(Builder $query): Builder
    {
        $foreignKey = $this->tenantColumn();

        return $foreignKey !== null
            ? $query->whereNull($foreignKey)->whereNull('user_id')
            : $query->whereNull('user_id');
    }

    /**
     * The configured tenant foreign key column, or null when isolation is not
     * `column` (in `database` mode the FK column does not exist).
     *
     * Protected (not private) so the method is safely callable through
     * static:: from validateSecurity and the tenant helpers in any subclass.
     */
    protected static function resolveTenantForeignKey(): ?string
    {
        if (TenantIsolation::fromConfig() !== TenantIsolation::Column) {
            return null;
        }

        $key = config('ai-agents.tenant.foreign_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * The configured tenant foreign key column, or null when disabled.
     */
    private function tenantColumn(): ?string
    {
        return static::resolveTenantForeignKey();
    }

    private function legacyModuleName(): string
    {
        return $this->module instanceof \BackedEnum ? (string) $this->module->value : (string) $this->module;
    }
}
