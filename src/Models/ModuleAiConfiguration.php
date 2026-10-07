<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models;

use HomeSide\AiAgents\Contracts\ResolvesTenant;
use HomeSide\AiAgents\Database\Factories\ModuleAiConfigurationFactory;
use HomeSide\AiAgents\Enums\TenantIsolation;
use HomeSide\AiAgents\Models\Concerns\ValidatesOnWrite;
use HomeSide\AiAgents\Tenancy\BelongsToTenant;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AI configuration of a module for a tenant (optional) and user, including
 * the system prompt and the provider/model used.
 *
 * @property string $id The unique identifier for the configuration (UUID).
 * @property string|null $user_id The id of the user the configuration belongs to, if any.
 * @property string|null $household_id The tenant id (column name configurable), if any.
 * @property string|null $ai_provider_id The id of the AI provider used, if any.
 * @property string|null $provider_model_id The id of the provider model used, if any.
 * @property string $module The module the configuration applies to.
 * @property string|null $agent_name The name of the agent configured for the module, if any.
 * @property string|null $label The label of the configuration, if any.
 * @property string|null $system_prompt The system prompt of the module (legacy, superseded by platform_prompt in ai_agents).
 * @property string|null $instructions The instructions of the module, if any.
 * @property string|null $additional_instructions User-level additional instructions (subordinate to platform prompt).
 * @property string|null $description The description of the configuration, if any.
 * @property string|null $model The model used by the module, if any.
 * @property array<string, mixed> $parameters The parameters of the configuration.
 * @property bool $enabled Whether the configuration is enabled.
 * @property Carbon|null $created_at The timestamp when the configuration was created.
 * @property Carbon|null $updated_at The timestamp when the configuration was last updated.
 *
 * Relationships:
 * @property Model|null $user The user the configuration belongs to (resolved via config).
 * @property AiProvider|null $aiProvider The AI provider used by the configuration.
 */
class ModuleAiConfiguration extends Model
{
    use BelongsToTenant, HasUuids, ValidatesOnWrite;

    /** @use HasFactory<ModuleAiConfigurationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'ai_provider_id', 'provider_model_id', 'module', 'agent_name',
        'label', 'system_prompt', 'instructions', 'additional_instructions', 'description',
        'model', 'parameters', 'enabled',
    ];

    /**
     * Get the attribute casts for the model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'enabled' => 'boolean',
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
            'user_id' => ['nullable'],
            'ai_provider_id' => ['nullable', 'uuid'],
            'provider_model_id' => ['nullable', 'uuid'],
            'module' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/'],
            'agent_name' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/'],
            'label' => ['required', 'string', 'max:255'],
            'system_prompt' => ['required', 'string'],
            'instructions' => ['nullable', 'string'],
            'additional_instructions' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'model' => ['nullable', 'string', 'max:255'],
            'parameters' => ['nullable', 'array'],
            'enabled' => ['boolean'],
        ];
    }

    /**
     * Ensure the (tenant,) user, module, agent_name tuple stays unique —
     * the invariant enforced by the database unique index.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected static function validateSecurity(array $attributes, ValidatorContract $validator, ?self $model = null): void
    {
        // provider_model_id must be coherent with ai_provider_id: a model of
        // another provider (or a model with no provider) is a configuration
        // error, not something discovered at runtime.
        $providerId = $attributes['ai_provider_id'] ?? $model?->ai_provider_id;
        $providerModelId = $attributes['provider_model_id'] ?? $model?->provider_model_id;

        if (is_string($providerModelId) && $providerModelId !== '') {
            if (! is_string($providerId) || $providerId === '') {
                $validator->errors()->add(
                    'provider_model_id',
                    'A provider_model_id requires an ai_provider_id to be set.',
                );
            } elseif (! AiProviderModel::query()->whereKey($providerModelId)->where('ai_provider_id', $providerId)->exists()) {
                $validator->errors()->add(
                    'provider_model_id',
                    "The provider model [{$providerModelId}] does not belong to the selected provider.",
                );
            }
        }

        $module = $attributes['module'] ?? null;
        $agentName = $attributes['agent_name'] ?? null;

        if (! is_string($module) || ! is_string($agentName)) {
            return;
        }

        /** @var ResolvesTenant $tenantResolver */
        $tenantResolver = app(ResolvesTenant::class);
        // Only apply FK checks in column mode.
        $foreignKey = $tenantResolver->isolation() === TenantIsolation::Column
            ? $tenantResolver->foreignKey()
            : null;

        $query = static::query()
            ->where('module', $module)
            ->where('agent_name', $agentName);

        if (is_string($foreignKey)) {
            $tenantValue = $attributes[$foreignKey] ?? $model?->{$foreignKey};
            $query->where($foreignKey, $tenantValue);
        }

        $userId = array_key_exists('user_id', $attributes) ? $attributes['user_id'] : $model?->user_id;
        $query->where('user_id', $userId);

        if ($model !== null) {
            $query->whereKeyNot($model->getKey());
        }

        if ((clone $query)->exists()) {
            $validator->errors()->add(
                'agent_name',
                "A module AI configuration for [{$module}.{$agentName}] already exists for this scope.",
            );
        }
    }

    /**
     * The user the configuration belongs to (resolved via config('ai-agents.user_model')).
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = (string) config('ai-agents.user_model');

        return $this->belongsTo($userModel, 'user_id');
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function aiProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class);
    }
}
