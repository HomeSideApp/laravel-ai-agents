<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models;

use HomeSide\AiAgents\Models\Casts\ConversationPayloadCast;
use HomeSide\AiAgents\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single durable message of a conversation transcript.
 *
 * Unlike {@see AiRun} — which records execution, cost, latency and audit —
 * this row is the memory: the exact turn Laravel AI needs to rebuild a
 * conversation (content, attachments, steps with tool calls/results,
 * approval state). The two lifecycles are independent: consolidating or
 * deleting runs never touches messages.
 *
 * The payload column is encrypted as a whole (see
 * {@see ConversationPayloadCast}), so tool arguments and results are
 * protected alongside the user's text.
 *
 * @property string $id The message UUID.
 * @property string $conversation_id The conversation this message belongs to.
 * @property int|string|null $user_id The owner id, mirrored for encryption/crypto-shredding.
 * @property int|string|null $household_id The tenant id (column name configurable), if any.
 * @property string $agent The agent class or key this turn belongs to.
 * @property string $role The message role (user|assistant).
 * @property array<string, mixed>|null $payload The encrypted turn payload.
 * @property int $payload_version The payload schema version.
 * @property array<string, mixed>|null $usage Token usage metadata.
 * @property string $status completed|failed|paused.
 * @property Carbon|null $created_at The timestamp when the message was created.
 * @property Carbon|null $updated_at The timestamp when the message was last updated.
 *
 * Relationships:
 * @property AiConversation|null $conversation The owning conversation.
 */
class AiConversationMessage extends Model
{
    use BelongsToTenant, HasUuids;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PAUSED = 'paused';

    protected $table = 'ai_conversation_messages';

    protected $fillable = [
        'id',
        'conversation_id',
        'user_id',
        'agent',
        'role',
        'payload',
        'payload_version',
        'usage',
        'status',
    ];

    /**
     * Get the attribute casts for the model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => ConversationPayloadCast::class,
            'payload_version' => 'integer',
            'usage' => 'array',
        ];
    }

    /**
     * The conversation that owns the message.
     *
     * @return BelongsTo<AiConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    /**
     * Whether the message is a user turn.
     */
    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    /**
     * Whether the message is an assistant turn.
     */
    public function isAssistant(): bool
    {
        return $this->role === self::ROLE_ASSISTANT;
    }
}
