<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The wrapped data-encryption key of one user (envelope pattern).
 *
 * @property string $id The unique identifier for the key record (UUID).
 * @property int|string $user_id The user the key belongs to.
 * @property string $wrapped_dek The user's DEK encrypted with the app key.
 * @property int $key_version Key version for rotation.
 * @property Carbon|null $shredded_at When the DEK was destroyed (crypto-shredding).
 * @property Carbon|null $created_at The timestamp when the row was created.
 * @property Carbon|null $updated_at The timestamp when the row was last updated.
 *
 * Relationships:
 * @property Model|null $user The user the key belongs to (resolved via config).
 */
class AiUserContentKey extends Model
{
    use HasUuids;

    protected $table = 'ai_user_content_keys';

    protected $fillable = [
        'user_id',
        'wrapped_dek',
        'key_version',
        'shredded_at',
    ];

    /**
     * Get the attribute casts for the model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key_version' => 'integer',
            'shredded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = (string) config('ai-agents.user_model');

        return $this->belongsTo($userModel, 'user_id');
    }
}
