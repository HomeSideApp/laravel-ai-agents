<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models;

use HomeSide\AiAgents\Database\Factories\AiGlobalSettingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Global AI settings, such as the extra system prompt applied to all modules.
 *
 * @property string $id The unique identifier for the global setting (UUID).
 * @property string|null $module The module the setting applies to, or null for the global setting.
 * @property string|null $extra_prompt The extra system prompt applied to the module.
 * @property Carbon|null $created_at The timestamp when the setting was created.
 * @property Carbon|null $updated_at The timestamp when the setting was last updated.
 */
class AiGlobalSetting extends Model
{
    /** @use HasFactory<AiGlobalSettingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'module',
        'extra_prompt',
    ];

    /**
     * Query scope: the installation-wide setting (module column null).
     *
     * PromptCompositor reads this row's extra_prompt as the global policy
     * layer applied to every agent run.
     *
     * @param  Builder<AiGlobalSetting>  $query  The builder being scoped.
     * @return Builder<AiGlobalSetting> The single module-less row (at most).
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('module');
    }

    /**
     * Query scope: the setting of one specific module.
     *
     * @param  Builder<AiGlobalSetting>  $query  The builder being scoped.
     * @param  string  $module  The module identifier (e.g. 'recipes').
     * @return Builder<AiGlobalSetting> Rows where module matches exactly.
     */
    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }
}
