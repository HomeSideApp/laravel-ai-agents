<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AiProviderModule extends Model
{
    protected $fillable = ['ai_provider_id', 'module', 'default_scope_key'];

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }
}
