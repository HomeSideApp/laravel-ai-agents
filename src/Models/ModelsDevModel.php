<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A model entry from the models.dev reference catalog.
 *
 * One row per model offered by a provider: capabilities, context/output
 * limits, per-million-token pricing and modalities. Pure reference data,
 * refreshed by the ai-agents:models-dev:sync command.
 *
 * @property string $id The unique identifier for the model record (UUID).
 * @property string $models_dev_provider_id The id of the provider this model belongs to.
 * @property string $model_id The model identifier on models.dev (e.g. "gpt-4o").
 * @property string $name The display name (e.g. "GPT-4o").
 * @property string|null $description The model description, if any.
 * @property string|null $family The model family (e.g. "gpt-4o").
 * @property bool $attachment Whether the model supports file attachments.
 * @property bool $reasoning Whether the model supports reasoning.
 * @property array<int, array<string, mixed>>|null $reasoning_options Reasoning configuration options.
 * @property bool $tool_call Whether the model supports tool/function calling.
 * @property bool $structured_output Whether the model supports structured output.
 * @property bool $temperature Whether the model supports temperature control.
 * @property bool $open_weights Whether the model has open weights.
 * @property Carbon|null $release_date The model release date.
 * @property Carbon|null $last_updated When the model was last updated upstream.
 * @property list<string>|null $modalities_input Input modalities (text, image, audio, video).
 * @property list<string>|null $modalities_output Output modalities.
 * @property int|null $context_window The context window size in tokens.
 * @property int|null $max_input_tokens The maximum input tokens, if declared separately.
 * @property int|null $max_output_tokens The maximum output tokens.
 * @property string|null $cost_input USD cost per million input tokens.
 * @property string|null $cost_output USD cost per million output tokens.
 * @property string|null $cost_cache_read USD cost per million cached input tokens read.
 * @property string|null $cost_cache_write USD cost per million cached input tokens written.
 * @property Carbon|null $last_synced_at When this row was last refreshed from the API.
 * @property Carbon|null $created_at The timestamp when the row was created.
 * @property Carbon|null $updated_at The timestamp when the row was last updated.
 *
 * Relationships:
 * @property ModelsDevProvider|null $provider The provider this model belongs to.
 */
class ModelsDevModel extends Model
{
    use HasUuids;

    protected $fillable = [
        'models_dev_provider_id',
        'model_id',
        'name',
        'description',
        'family',
        'attachment',
        'reasoning',
        'reasoning_options',
        'tool_call',
        'structured_output',
        'temperature',
        'open_weights',
        'release_date',
        'last_updated',
        'modalities_input',
        'modalities_output',
        'context_window',
        'max_input_tokens',
        'max_output_tokens',
        'cost_input',
        'cost_output',
        'cost_cache_read',
        'cost_cache_write',
        'last_synced_at',
    ];

    /**
     * Accessor attributes to append to the model's serialized array representation.
     */
    protected $appends = [
        'provider_slug',
    ];

    /**
     * Get the attribute casts for the model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachment' => 'boolean',
            'reasoning' => 'boolean',
            'reasoning_options' => 'array',
            'tool_call' => 'boolean',
            'structured_output' => 'boolean',
            'temperature' => 'boolean',
            'open_weights' => 'boolean',
            'release_date' => 'date',
            'last_updated' => 'date',
            'modalities_input' => 'array',
            'modalities_output' => 'array',
            'context_window' => 'integer',
            'max_input_tokens' => 'integer',
            'max_output_tokens' => 'integer',
            'cost_input' => 'decimal:6',
            'cost_output' => 'decimal:6',
            'cost_cache_read' => 'decimal:6',
            'cost_cache_write' => 'decimal:6',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ModelsDevProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(ModelsDevProvider::class, 'models_dev_provider_id');
    }

    /**
     * The provider's models.dev slug, exposed as a top-level attribute
     * so Inertia serializes it alongside the model (matching the
     * CatalogModel TypeScript interface which expects `provider_slug`).
     */
    public function getProviderSlugAttribute(): ?string
    {
        return $this->provider?->slug;
    }

    /**
     * Whether the model handles the given modality.
     *
     * @param  'text'|'image'|'audio'|'video'  $modality
     * @param  'input'|'output'  $direction
     */
    public function hasModality(string $modality, string $direction = 'input'): bool
    {
        $modalities = $direction === 'output'
            ? $this->modalities_output
            : $this->modalities_input;

        return $modalities !== null && in_array($modality, $modalities, true);
    }

    /**
     * Use the central catalog connection so the reference catalog lives on
     * a dedicated database (configured via `ai-agents.catalog.connection`).
     */
    public function getConnectionName(): ?string
    {
        $connection = config('ai-agents.catalog.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
}
