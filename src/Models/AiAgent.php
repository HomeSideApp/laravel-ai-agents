<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models;

use HomeSide\AiAgents\Database\Factories\AiAgentFactory;
use HomeSide\AiAgents\Models\Concerns\ValidatesOnWrite;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for AI agent configuration managed by platform admins.
 *
 * Each row corresponds to a registered DomainAgent class. The platform_prompt
 * is the authoritative functional prompt that flows into the prompt compositor.
 *
 * @property string $id UUID primary key.
 * @property string $key Unique canonical key (e.g. 'recipes.recipe_generator').
 * @property string $module Module the agent belongs to (e.g. 'recipes').
 * @property string $label Human-readable label.
 * @property string|null $description Optional description.
 * @property string $platform_prompt The platform-managed functional prompt.
 * @property string|null $seeded_prompt_hash SHA-256 of the last prompt seeded from code.
 * @property int $prompt_version Version number incremented on each prompt edit.
 * @property bool $enabled Whether the agent is enabled for execution.
 * @property array<string, mixed>|null $parameters Optional parameter overrides.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiAgent extends Model
{
    /** @use HasFactory<AiAgentFactory> */
    use HasFactory;

    use HasUuids, ValidatesOnWrite;

    protected $fillable = [
        'key',
        'module',
        'label',
        'description',
        'platform_prompt',
        'seeded_prompt_hash',
        'prompt_version',
        'enabled',
        'parameters',
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
            'prompt_version' => 'integer',
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
            'key' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_.]+$/'],
            'module' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/'],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'platform_prompt' => ['required', 'string'],
            'seeded_prompt_hash' => ['nullable', 'string', 'size:64'],
            'prompt_version' => ['integer', 'min:1'],
            'enabled' => ['boolean'],
            'parameters' => ['nullable', 'array'],
        ];
    }

    /**
     * Ensure the agent key stays unique (case-insensitive on collations
     * that fold case) and prompt versions only move forward.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected static function validateSecurity(array $attributes, ValidatorContract $validator, ?self $model = null): void
    {
        $key = $attributes['key'] ?? null;

        if (is_string($key) && $key !== '') {
            $modelKey = $model?->getKey();

            $exists = static::query()->where('key', $key)
                ->when(
                    is_string($modelKey) || is_int($modelKey),
                    fn (Builder $q): Builder => $q->whereKeyNot($modelKey),
                )
                ->exists();

            if ($exists) {
                $validator->errors()->add('key', "An AI agent with key [{$key}] already exists.");
            }
        }

        $version = $attributes['prompt_version'] ?? null;
        $current = $model?->prompt_version;

        if (is_int($version) && is_int($current) && $version < $current) {
            $validator->errors()->add('prompt_version', "Prompt version cannot decrease (current: {$current}).");
        }
    }

    /**
     * Find an agent by its canonical key.
     *
     * @param  string  $key  The canonical agent key.
     * @return static|null The matching row, or null when the key is unknown.
     */
    public static function findByKey(string $key): ?static
    {
        $agent = static::where('key', $key)->first();

        return $agent instanceof static ? $agent : null;
    }

    /**
     * Query scope: only agents currently enabled for execution.
     *
     * Disabled agents are admin-deactivated; the manager refuses to run them
     * (checked against the row, not this scope), but listings should hide
     * them.
     *
     * @param  Builder<static>  $query  The builder being scoped.
     * @return Builder<static> Rows where enabled is true.
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Query scope: agents belonging to one module identifier.
     *
     * @param  Builder<static>  $query  The builder being scoped.
     * @param  string  $module  The module identifier (e.g. 'recipes').
     * @return Builder<static> Rows where module matches exactly.
     */
    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }
}
