<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models;

use HomeSide\AiAgents\Configuration\Capability;
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
     * Get the effective capabilities of this model for compatibility checks.
     *
     * Precedence: manual override (admin-curated) beats probe detection,
     * which in turn leaves the CapabilityResolver to fall back to the
     * driver baseline when this returns an empty set.
     *
     * @return Capability[] The enum cases derived from the winning list;
     *                      empty when neither source has data.
     */
    public function effectiveCapabilities(): array
    {
        $override = $this->capabilities_override;
        $detected = $this->capabilities_detected;

        if ($override !== null && $override !== []) {
            return array_map(fn (string $c) => Capability::from($c), $override);
        }

        if ($detected !== null && $detected !== []) {
            return array_map(fn (string $c) => Capability::from($c), $detected);
        }

        return [];
    }

    /**
     * Promote this model to the default of its provider.
     *
     * Clears is_default on every sibling model of the same provider first,
     * then flags this one — provider resolution's orderByDesc('is_default')
     * depends on that uniqueness to pick the preferred model.
     */
    public function markAsDefault(): void
    {
        static::where('ai_provider_id', $this->ai_provider_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }
}
