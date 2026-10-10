<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Embeddings\EmbeddingOptionsValidator;
use HomeSide\AiAgents\Enums\EmbeddingPurpose;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A model exposed by an AI provider, with probed capability information.
 *
 * @property string $id The unique identifier for the model record (UUID).
 * @property string $ai_provider_id The id of the AI provider the model belongs to.
 * @property string $model The model identifier exposed by the provider.
 * @property string|null $display_name The display name of the model, if any.
 * @property bool $enabled Whether the model is enabled.
 * @property bool $is_default Whether the model is the default for its provider.
 * @property array<int, string>|null $capabilities_detected The capabilities detected by probing the provider.
 * @property array<int, string>|null $capabilities_override The capabilities manually overridden for the model.
 * @property int|null $context_window The context window size of the model, if known.
 * @property int|null $max_output_tokens The maximum number of output tokens of the model, if known.
 * @property int|null $embedding_dimensions The embedding vector length, when the model produces embeddings.
 * @property int $embedding_profile_version The explicit embedding space version (escape hatch).
 * @property array<string, mixed>|null $embedding_options Per-purpose embedding options (generic/document/query).
 * @property array<string, mixed>|null $metadata Additional metadata of the model.
 * @property Carbon|null $last_probed_at The timestamp when the model was last probed.
 * @property string|null $last_probe_status The status of the last probe of the model.
 * @property Carbon|null $created_at The timestamp when the model was created.
 * @property Carbon|null $updated_at The timestamp when the model was last updated.
 *
 * Relationships:
 * @property AiProvider|null $provider The AI provider the model belongs to.
 */
class AiProviderModel extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_provider_id',
        'model',
        'display_name',
        'enabled',
        'is_default',
        'capabilities_detected',
        'capabilities_override',
        'context_window',
        'max_output_tokens',
        'embedding_dimensions',
        'embedding_profile_version',
        'embedding_options',
        'metadata',
        'last_probed_at',
        'last_probe_status',
    ];

    /**
     * Get the attribute casts for the model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'is_default' => 'boolean',
            'capabilities_detected' => 'array',
            'capabilities_override' => 'array',
            'context_window' => 'integer',
            'max_output_tokens' => 'integer',
            'embedding_dimensions' => 'integer',
            'embedding_profile_version' => 'integer',
            'embedding_options' => 'array',
            'metadata' => 'array',
            'last_probed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /**
     * Validate the embedding options contract before anything is persisted,
     * so secrets or unknown purposes can never reach the database (and from
     * there the profile DTO, logs or the fingerprint).
     */
    protected static function booted(): void
    {
        static::saving(function (AiProviderModel $model): void {
            if (array_key_exists('embedding_options', $model->getAttributes())
                || $model->isDirty('embedding_options')) {
                app(EmbeddingOptionsValidator::class)->validate($model->embedding_options);
            }
        });
    }

    /**
     * The per-purpose embedding options declared for this model.
     *
     * Only the keys generic/document/query are meaningful; anything else is
     * ignored. Credentials never belong here (they live on AiProvider).
     *
     * @return array{generic: array<string, mixed>, document: array<string, mixed>, query: array<string, mixed>}
     */
    public function embeddingOptions(): array
    {
        $stored = $this->embedding_options ?? [];

        return [
            'generic' => $this->optionBucket($stored, EmbeddingPurpose::Generic),
            'document' => $this->optionBucket($stored, EmbeddingPurpose::Document),
            'query' => $this->optionBucket($stored, EmbeddingPurpose::Query),
        ];
    }

    /**
     * The effective provider options for one purpose: the generic options
     * overlaid with the purpose-specific ones (deterministic nested merge).
     *
     * @return array<string, mixed>
     */
    public function embeddingOptionsFor(EmbeddingPurpose $purpose): array
    {
        $options = $this->embeddingOptions();

        return array_replace_recursive(
            $options['generic'],
            $options[$purpose->value],
        );
    }

    /**
     * Extract a single, well-formed options bucket from the stored array.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function optionBucket(array $stored, EmbeddingPurpose $purpose): array
    {
        $bucket = $stored[$purpose->value] ?? null;

        /** @var array<string, mixed> */
        return is_array($bucket) ? $bucket : [];
    }

    /**
     * Get the effective capabilities of this model for compatibility checks.
     *
     * Precedence: manual override (admin-curated) beats probe detection,
     * which in turn leaves the CapabilityResolver to fall back to the
     * driver baseline when this returns an empty set.
     *
     * Driver-baseline-only capabilities that require explicit per-model
     * support (Embeddings, Reranking) are NEVER inferred here: they only
     * appear when the model declares them through capabilities_detected or
     * capabilities_override. This is what makes model selection for those
     * capabilities safe.
     *
     * @param  string|null  $driver  The provider driver, used to resolve the
     *                               baseline when no explicit data exists.
     * @return Capability[] The enum cases the model supports; empty when
     *                      neither source has data.
     */
    public function effectiveCapabilities(?string $driver = null): array
    {
        $override = $this->capabilities_override;
        $detected = $this->capabilities_detected;

        if ($override !== null && $override !== []) {
            return array_map(fn (string $c) => Capability::from($c), $override);
        }

        if ($detected !== null && $detected !== []) {
            return array_map(fn (string $c) => Capability::from($c), $detected);
        }

        if ($driver === null) {
            return [];
        }

        // Fall back to the driver baseline for its text features only;
        // capabilities that require explicit model support stay out.
        return array_values(array_filter(
            Capability::driverBaseline($driver),
            static fn (Capability $capability): bool => ! $capability->requiresExplicitModelSupport(),
        ));
    }

    /**
     * Whether this model supports the given capability (effective set).
     *
     * @param  string|null  $driver  The provider driver for baseline fallback.
     */
    public function supportsCapability(Capability $capability, ?string $driver = null): bool
    {
        return in_array($capability, $this->effectiveCapabilities($driver), true);
    }

    /**
     * Promote this model to the default of its provider.
     *
     * @deprecated Use {@see ProviderModelDefaults::set()} with
     *             Capability::Text instead: is_default is kept only for
     *             backwards compatibility and is no longer the source of
     *             truth for model resolution.
     */
    public function markAsDefault(): void
    {
        static::where('ai_provider_id', $this->ai_provider_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }
}
