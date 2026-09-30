<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tool call made during an AI run, including its duration and status.
 *
 * @property string $id The unique identifier for the tool call (UUID).
 * @property string $ai_run_id The id of the AI run the tool call belongs to.
 * @property string $tool The name of the tool that was called.
 * @property int|null $duration_ms The duration of the tool call in milliseconds.
 * @property string $status The status of the tool call.
 * @property string|null $error_code The error code returned by the tool, if any.
 * @property Carbon|null $created_at The timestamp when the tool call was created.
 * @property Carbon|null $updated_at The timestamp when the tool call was last updated.
 *
 * Relationships:
 * @property AiRun|null $run The AI run the tool call belongs to.
 */
class AiToolCall extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_run_id',
        'tool',
        'duration_ms',
        'status',
        'error_code',
    ];

    /**
     * Get the attribute casts for the model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<AiRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }
}
