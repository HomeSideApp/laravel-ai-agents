<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A provider entry from the models.dev reference catalog.
 *
 * Public metadata about an AI provider (name, API base URL, docs, env vars,
 * logo). Kept separate from AiProvider, which stores host/user connection
 * settings with encrypted keys.
 *
 * @property string $id The unique identifier for the provider record (UUID).
 * @property string $slug The provider ID from models.dev (e.g. "openai").
 * @property string $name The display name (e.g. "OpenAI").
 * @property string|null $api_url The provider's API base URL, if any.
 * @property string|null $doc_url The provider's documentation URL, if any.
 * @property list<string>|null $env_vars Environment variables the provider expects (e.g. OPENAI_API_KEY).
 * @property string|null $logo_path Absolute path of the downloaded SVG logo, if any.
 * @property Carbon|null $last_synced_at When this row was last refreshed from the API.
 * @property Carbon|null $created_at The timestamp when the row was created.
 * @property Carbon|null $updated_at The timestamp when the row was last updated.
 *
 * Relationships:
 * @property HasMany<ModelsDevModel, $this> $models The catalog models of this provider.
 */
class ModelsDevProvider extends Model
{
    use HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'api_url',
        'doc_url',
        'env_vars',
        'logo_path',
        'last_synced_at',
    ];

    protected $appends = [
        'logo_url',
    ];

    /**
     * Get the attribute casts for the model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'env_vars' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return HasMany<ModelsDevModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(ModelsDevModel::class, 'models_dev_provider_id');
    }

    /**
     * The remote logo URL for this provider on models.dev.
     */
    public function remoteLogoUrl(): string
    {
        return 'https://models.dev/logos/'.$this->slug.'.svg';
    }

    /**
     * Logo URL exposed as a serialized attribute for Inertia/JSON.
     */
    public function getLogoUrlAttribute(): string
    {
        return $this->remoteLogoUrl();
    }

    /**
     * Find a provider by its models.dev slug.
     *
     * @param  Builder<ModelsDevProvider>  $query
     * @return Builder<ModelsDevProvider>
     */
    #[Scope]
    public function scopeSlug(Builder $query, string $slug): Builder
    {
        return $query->where('slug', $slug);
    }

    /**
     * Use the central catalog connection so the reference catalog lives on
     * a dedicated database (configured via `ai-agents.catalog.connection`).
     *
     * In `database` isolation mode the package auto-sets this to `'central'`,
     * keeping the catalog on the central BD while tenant queries run against
     * the tenant's own database.
     */
    public function getConnectionName(): ?string
    {
        $connection = config('ai-agents.catalog.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
}
