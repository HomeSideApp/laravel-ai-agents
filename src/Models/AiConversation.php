<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models;

use HomeSide\AiAgents\AgentRegistry;
use HomeSide\AiAgents\Models\Concerns\ValidatesOnWrite;
use HomeSide\AiAgents\Tenancy\BelongsToTenant;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A conversation with an AI agent, grouping the runs it produced.
 *
 * @property string $id The unique identifier for the conversation (UUID).
 * @property string $user_id The id of the user who owns the conversation.
 * @property string|null $household_id The tenant id (column name configurable), if any.
 * @property string $agent The agent that the conversation belongs to.
 * @property string $title The title of the conversation.
 * @property Carbon|null $created_at The timestamp when the conversation was created.
 * @property Carbon|null $updated_at The timestamp when the conversation was last updated.
 *
 * Relationships:
 * @property Model|null $user The user who owns the conversation (resolved via config).
 * @property Collection<int, AiRun> $runs The AI runs produced by the conversation.
 */
class AiConversation extends Model
{
    use BelongsToTenant, HasUuids, ValidatesOnWrite;

    protected $fillable = [
        'user_id',
        'agent',
        'title',
    ];

    /**
     * Validation rules for validated writes.
     *
     * @return array<string, mixed>
     */
    protected static function rules(): array
    {
        return [
            'user_id' => ['required'],
            'agent' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_.]+$/'],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Verify the agent key is registered before opening a conversation.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected static function validateSecurity(array $attributes, ValidatorContract $validator, ?self $model = null): void
    {
        $agent = $attributes['agent'] ?? null;

        if (is_string($agent) && $agent !== '' && ! app(AgentRegistry::class)->has($agent)) {
            $validator->errors()->add('agent', "The AI agent [{$agent}] is not registered.");
        }
    }

    /**
     * The user who owns the conversation (resolved via config('ai-agents.user_model')).
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = (string) config('ai-agents.user_model');

        return $this->belongsTo($userModel, 'user_id');
    }

    /** @return HasMany<AiRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AiRun::class, 'conversation_id');
    }

    /**
     * Query scope: restrict conversations to one owner.
     *
     * @param  Builder<AiConversation>  $query  The builder being scoped.
     * @param  int|string  $userId  Host user identifier (integer or UUID).
     * @return Builder<AiConversation> Conversations where user_id matches exactly.
     */
    public function scopeForUser(Builder $query, int|string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Query scope: restrict conversations to a single agent key.
     *
     * @param  Builder<AiConversation>  $query  The builder being scoped.
     * @param  string  $agent  The canonical agent key.
     * @return Builder<AiConversation> Conversations where agent matches exactly.
     */
    public function scopeForAgent(Builder $query, string $agent): Builder
    {
        return $query->where('agent', $agent);
    }
}
