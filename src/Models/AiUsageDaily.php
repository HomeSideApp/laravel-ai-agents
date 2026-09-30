<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One consolidated row per (UTC day, provider): token totals, run counts
 * and the summed estimated cost of the runs consolidated into it.
 *
 * The daily table is the retention target: once a run's tokens and cost
 * are rolled up here, the run row itself can be pruned without losing the
 * economical history.
 *
 * @property string $id The unique identifier for the row (UUID).
 * @property Carbon $period The UTC day this row consolidates.
 * @property string|null $ai_provider_id The provider the usage belongs to (null = unknown).
 * @property string|null $provider_name Display name snapshot of the provider.
 * @property int $runs Number of consolidated runs.
 * @property int $input_tokens Total input tokens.
 * @property int $output_tokens Total output tokens.
 * @property int $cached_tokens Total cached input tokens.
 * @property string $estimated_cost Summed estimated cost in USD.
 * @property int $unknown_token_runs Runs whose token usage was not reported.
 * @property int $unknown_cost_runs Runs whose cost could not be estimated.
 * @property Carbon|null $last_synced_at When the rollup last refreshed.
 * @property Carbon|null $created_at The timestamp when the row was created.
 * @property Carbon|null $updated_at The timestamp when the row was last updated.
 *
 * Relationships:
 * @property AiProvider|null $provider The provider the usage belongs to.
 */
class AiUsageDaily extends Model
{
    use HasUuids;

    /**
     * "ai_usage_daily" is already the intended table name; Laravel's
     * guesser would pluralise it to "ai_usage_dailies".
     */
    protected $table = 'ai_usage_daily';

    protected $fillable = [
        'period',
        'ai_provider_id',
        'provider_name',
        'runs',
        'input_tokens',
        'output_tokens',
        'cached_tokens',
        'estimated_cost',
        'unknown_token_runs',
        'unknown_cost_runs',
        'last_synced_at',
    ];

    /**
     * Get the attribute casts for the model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Y-m-d storage keeps the updateOrCreate matching exact: the
            // default 'date' cast would store a midnight timestamp string,
            // breaking equality lookups against plain date strings.
            'period' => 'date:Y-m-d',
            'runs' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cached_tokens' => 'integer',
            'estimated_cost' => 'decimal:6',
            'unknown_token_runs' => 'integer',
            'unknown_cost_runs' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /**
     * Restrict to a period range (inclusive, UTC dates).
     *
     * @param  Builder<AiUsageDaily>  $query
     * @return Builder<AiUsageDaily>
     */
    #[Scope]
    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from !== null, fn (Builder $q) => $q->where('period', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('period', '<=', $to));
    }
}
