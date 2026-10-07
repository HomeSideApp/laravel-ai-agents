<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models;

use HomeSide\AiAgents\Configuration\Capability;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The default model of a provider for one routable capability.
 *
 * Source of truth for "which model does provider X use for capability Y"
 * (text, embeddings, reranking). Ownership is derived transitively:
 *
 *   default → AiProviderModel → AiProvider → user / tenant / system
 *
 * which is why there is no tenant/user column here: any resolution must
 * start from an already authorised AiProvider, and a raw UUID from this
 * table must never be trusted from HTTP. The unique(ai_provider_id,
 * capability) constraint enforces one default per capability per provider.
 *
 * @property string $id The unique identifier (UUID).
 * @property string $ai_provider_id The owning provider id.
 * @property string $ai_provider_model_id The default model id.
 * @property string $capability The routable capability (text|embeddings|reranking).
 * @property Carbon|null $created_at The creation timestamp.
 * @property Carbon|null $updated_at The update timestamp.
 *
 * Relationships:
 * @property AiProvider|null $provider The owning provider.
 * @property AiProviderModel|null $model The default model.
 */
class AiProviderModelDefault extends Model
{
    use HasUuids;

    protected $table = 'ai_provider_model_defaults';

    protected $fillable = [
        'ai_provider_id',
        'ai_provider_model_id',
        'capability',
    ];

    /**
     * Get the attribute casts for the model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capability' => Capability::class,
        ];
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /** @return BelongsTo<AiProviderModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiProviderModel::class, 'ai_provider_model_id');
    }
}
