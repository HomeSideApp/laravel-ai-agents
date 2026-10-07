<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Conversations;

use HomeSide\AiAgents\Contracts\ResolvesTenant;
use HomeSide\AiAgents\Enums\TenantIsolation;
use HomeSide\AiAgents\Exceptions\ConversationNotFoundException;
use HomeSide\AiAgents\Models\AiConversation;
use HomeSide\AiAgents\Models\AiConversationMessage;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\PaginatesConversations;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Files\File;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\StoredMessage;
use RuntimeException;
use Throwable;

/**
 * Laravel AI's ConversationStore backed by the package's canonical tables.
 *
 * Reproduces the semantics of Laravel\Ai\Storage\DatabaseConversationStore
 * (tool replay, paused turns, approval resume, per-step reconstruction) but
 * persists into {@see AiConversation} / {@see AiConversationMessage}, so
 * tenancy, per-user encryption, crypto-shredding and retention stay under
 * the package's control and no parallel `agent_conversations` identity is
 * created.
 *
 * Every operation that accepts an existing id goes through
 * {@see ConversationAccessGuard}: the tenant/owner scopes are applied in the
 * same query that resolves the conversation.
 */
class PackageConversationStore implements ConversationStore, PaginatesConversations, ResolvesPendingApprovals, VerifiesConversationOwnership
{
    /**
     * The canonical agent key for the current execution.
     *
     * The SDK's storeConversation() carries no agent, while AiConversation
     * requires one. AiAgentManager sets this before prompt() so any
     * middleware-created conversation is attributed correctly.
     */
    private ?string $agentKey = null;

    public function __construct(
        private readonly ConversationAccessGuard $guard,
        private readonly ConversationManager $manager,
        private readonly ResolvesTenant $tenantResolver,
    ) {}

    /**
     * Attach the execution's agent so id-less conversation creation (the
     * SDK's storeConversation) can attribute the row correctly. The owner is
     * already carried by the participant id the SDK passes alongside.
     */
    public function forExecution(string $agentKey): static
    {
        $this->agentKey = $agentKey;

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * The SDK passes the agent CLASS; it is translated to the canonical key
     * so it matches the single identity stored on ai_conversations.
     */
    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        $agentKey = $this->manager->agentKeyForClass($agent);

        return $this->messagesQuery()
            ->where('user_id', $participantId)
            ->whereHas('conversation', function (Builder $query) use ($agentKey, $participantId): void {
                $query->forUser($participantId)->forAgent($agentKey);
                $this->applyTenantScope($query, $participantId);
            })
            ->orderByDesc('id')
            ->value('conversation_id');
    }

    /**
     * {@inheritDoc}
     */
    public function conversationBelongsTo(string $conversationId, ?string $participantType, string|int|null $participantId): bool
    {
        $agentKey = $this->agentKey;

        if ($agentKey === null) {
            $agentKey = AiConversation::query()->whereKey($conversationId)->value('agent');

            if (! is_string($agentKey) || $agentKey === '') {
                return false;
            }
        }

        try {
            $this->guard->authorizeOwnedBy($conversationId, $agentKey, $participantId);

            return true;
        } catch (ConversationNotFoundException) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        $conversationId = $id ?? (string) Str::uuid7();

        if (AiConversation::query()->whereKey($conversationId)->exists()) {
            return $conversationId;
        }

        $attributes = [
            'id' => $conversationId,
            'user_id' => $participantId,
            'agent' => $this->agentKey ?? 'unknown',
            'title' => $title,
        ];

        if ($this->tenantResolver->isolation() === TenantIsolation::Column) {
            $tenant = $this->tenantResolver->resolveAccessible($participantId, null);

            if ($tenant !== null) {
                $attributes[$this->tenantResolver->foreignKey()] = $tenant;
            }
        }

        AiConversation::create($attributes);

        return $conversationId;
    }

    /**
     * {@inheritDoc}
     */
    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
    {
        $messageId = (string) Str::uuid7();

        AiConversationMessage::create([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $participantId,
            'agent' => $this->manager->agentKeyForClass($agent),
            'role' => AiConversationMessage::ROLE_USER,
            'payload' => [
                'content' => $message->content,
                'attachments' => $this->encodeAttachments($message),
                'steps' => [],
                'meta' => [],
            ],
            'payload_version' => 1,
            'usage' => [],
            'status' => MessageStatus::Completed->value,
        ]);

        $this->touchConversation($conversationId);

        return $messageId;
    }

    /**
     * {@inheritDoc}
     */
    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
    {
        if ($prompt->hasApprovalDecisions() && ($paused = $this->pausedRowFor($conversationId, $prompt)) !== null) {
            return $this->resumePausedRow($conversationId, $paused, $prompt, $response, $exception);
        }

        $messageId = (string) Str::uuid7();

        $steps = $this->stepsFor($prompt, $response);

        if ($prompt->hasApprovalDecisions() && blank($response->text) && $steps->every(fn (array $step): bool => $step['tool_calls'] === [])) {
            return null;
        }

        AiConversationMessage::create([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $participantId,
            'agent' => $this->manager->agentKeyForClass($prompt->agent::class),
            'role' => AiConversationMessage::ROLE_ASSISTANT,
            'payload' => [
                'content' => $response->text,
                'attachments' => [],
                'steps' => $steps->all(),
                'meta' => $this->metaFor($response, $exception),
            ],
            'payload_version' => 1,
            'usage' => $response->usage->toArray(),
            'status' => $this->statusFor($response, $exception)->value,
        ]);

        if (! $response->hasPendingApprovals()) {
            $this->forgetReplayBlocks($conversationId);
        }

        $this->touchConversation($conversationId);

        return $messageId;
    }

    /**
     * {@inheritDoc}
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $configured = (int) config('ai-agents.conversations.context.max_messages', 30);
        $effective = $configured > 0 ? min($limit, $configured) : $limit;

        $records = AiConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit($effective)
            ->get()
            ->reverse()
            ->values();

        return $records->flatMap(fn (AiConversationMessage $record): array => $record->isUser()
            ? [$this->userMessageFrom($record)]
            : $this->assistantTurnFrom($record));
    }

    /**
     * {@inheritDoc}
     */
    public function storeApprovalResults(string $conversationId, array $toolResults): void
    {
        if ($toolResults === []) {
            return;
        }

        $resultIds = array_map(fn (ToolResult $result): string => $result->id, $toolResults);

        DB::transaction(function () use ($conversationId, $toolResults, $resultIds): void {
            $paused = $this->assistantRows($conversationId)
                ->where('status', MessageStatus::Paused->value)
                ->lockForUpdate()
                ->get();

            $row = $paused->first(fn (AiConversationMessage $record): bool => array_intersect($this->pausedCallIds($record), $resultIds) !== []);

            if ($row === null) {
                throw new ApprovalMismatchException(
                    'The approval results do not match a paused conversation turn.',
                    $paused->first() === null ? collect() : $this->pendingApprovalsIn($paused->first()),
                );
            }

            $resolved = collect($toolResults)->keyBy(fn (ToolResult $result): string => $result->id);

            $steps = $this->decodedSteps($row)->map(function (array $step) use ($resolved): array {
                $step['tool_calls'] = array_map(function (array $toolCall) use ($resolved): array {
                    $result = $resolved->get($toolCall['id'] ?? '');

                    return $result === null || PendingApproval::isAnswered($toolCall)
                        ? $toolCall
                        : [...$toolCall, ...Arr::only($result->toArray(), ['arguments', 'result', 'denied', 'failed'])];
                }, $step['tool_calls']);

                return $step;
            });

            $payload = $row->payload ?? [];
            $payload['steps'] = $steps->all();
            $row->forceFill(['payload' => $payload])->save();
        });
    }

    /**
     * {@inheritDoc}
     *
     * @return CursorPaginator<int, StoredMessage>
     */
    public function paginateConversationMessages(string $conversationId, int $perPage = 15, string $cursorName = 'cursor', Cursor|string|null $cursor = null): CursorPaginator
    {
        return AiConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], $cursorName, $cursor)
            ->through(fn (AiConversationMessage $record): StoredMessage => StoredMessage::fromArray([
                'id' => $record->id,
                'role' => $record->role,
                'content' => (string) ($record->payload['content'] ?? ''),
                'created_at' => $record->created_at,
                'usage' => $record->usage ?? [],
                'meta' => $record->payload['meta'] ?? [],
                'steps' => $record->payload['steps'] ?? [],
                'status' => $record->status,
                'attachments' => $record->payload['attachments'] ?? [],
            ]));
    }

    /**
     * {@inheritDoc}
     *
     * @return list<PendingApproval>
     */
    public function pendingApprovalsFor(string $conversationId): array
    {
        $newest = $this->assistantRows($conversationId)->first();

        return $newest === null || $newest->status !== MessageStatus::Paused->value
            ? []
            : array_values($this->pendingApprovalsIn($newest)->all());
    }

    // -----------------------------------------------------------------
    // Assistant turn reconstruction (mirrors the SDK's store semantics)
    // -----------------------------------------------------------------

    /**
     * The status the turn is stored under, given how it ended.
     */
    private function statusFor(AgentResponse $response, ?Throwable $exception): MessageStatus
    {
        return match (true) {
            $exception !== null => MessageStatus::Failed,
            $response->hasPendingApprovals() => MessageStatus::Paused,
            default => MessageStatus::Completed,
        };
    }

    /**
     * The meta the turn is stored under, carrying the error it died with.
     *
     * @return array<string, mixed>
     */
    private function metaFor(AgentResponse $response, ?Throwable $exception): array
    {
        return $exception === null
            ? $response->meta->toArray()
            : [...$response->meta->toArray(), 'error' => $exception->getMessage()];
    }

    /**
     * Find the row the given resume paused on, matching the turn its decisions name.
     */
    private function pausedRowFor(string $conversationId, AgentPrompt $prompt): ?AiConversationMessage
    {
        $decided = array_keys($prompt->approvalDecisions?->all() ?? []);

        $named = $this->assistantRows($conversationId)
            ->where('status', MessageStatus::Paused->value)
            ->get()
            ->first(fn (AiConversationMessage $record): bool => array_intersect($this->gatedCallIds($record), $decided) !== []);

        if ($named !== null) {
            return $named;
        }

        $newest = $this->assistantRows($conversationId)->first();

        return $newest === null || $newest->status !== MessageStatus::Paused->value ? null : $newest;
    }

    /**
     * Append the steps a resumed run made to the row its turn paused on.
     */
    private function resumePausedRow(string $conversationId, AiConversationMessage $paused, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): string
    {
        $steps = $this->decodedSteps($paused);
        $payload = $paused->payload ?? [];

        if (($payload['meta']['provider'] ?? null) !== $response->meta->provider) {
            $steps = $this->withoutReplayBlocks($steps);
        }

        if ($response->steps->isNotEmpty()) {
            $steps = $steps->concat($this->stepsFor($prompt, $response));
        }

        if (! $response->hasPendingApprovals()) {
            $steps = $this->withoutReplayBlocks($steps);
        }

        $payload['content'] = blank($response->text) ? (string) ($payload['content'] ?? '') : $response->text;
        $payload['steps'] = $steps->all();
        $payload['meta'] = $this->mergedMeta($paused, $response, $exception);

        $paused->forceFill([
            'payload' => $payload,
            'usage' => TextUsage::fromArray($paused->usage ?? [])->add($response->usage)->toArray(),
            'status' => $this->statusFor($response, $exception)->value,
        ])->save();

        if (! $response->hasPendingApprovals()) {
            $this->forgetReplayBlocks($conversationId);
        }

        $this->touchConversation($conversationId);

        return $paused->id;
    }

    /**
     * Keep the citations the paused half of the turn collected.
     *
     * @return array<string, mixed>
     */
    private function mergedMeta(AiConversationMessage $paused, AgentResponse $response, ?Throwable $exception = null): array
    {
        $pausedMeta = $paused->payload['meta'] ?? [];

        return [
            ...$this->metaFor($response, $exception),
            'citations' => [...$pausedMeta['citations'] ?? [], ...$response->meta->citations->all()],
        ];
    }

    /**
     * Serialize the turn's steps, one entry per model round-trip.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function stepsFor(AgentPrompt $prompt, AgentResponse $response): Collection
    {
        $reasons = $this->pendingReasonsFor($response);

        /** @var Collection<int, array<string, mixed>> $steps */
        $steps = $response->steps->isNotEmpty()
            ? $response->steps->values()->map(function (Step $step) use ($response, $reasons): array {
                /** @var array<string, mixed> $encoded */
                $encoded = [
                    'content' => $step->text,
                    'tool_calls' => $this->toolCallsFor($step->toolCalls, $step->toolResults, $reasons),
                    'reasoning' => $step->reasoning,
                    'replay_blocks' => $response->hasPendingApprovals() ? $step->replayBlocks : [],
                    'provider_tool_calls' => array_map(fn (ProviderToolCall $call): array => $call->toArray(), $step->providerToolCalls),
                ];

                return $encoded;
            })
            : collect([[
                'content' => $response->text,
                'tool_calls' => $this->toolCallsFor(
                    $response->toolCalls->all(),
                    $prompt->hasApprovalDecisions() ? [] : $response->toolResults->all(),
                    $reasons,
                ),
                'reasoning' => $response->reasoning,
                'replay_blocks' => [],
                'provider_tool_calls' => [],
            ]]);

        return $steps;
    }

    /**
     * Pair a step's tool calls with the results they were answered by.
     *
     * @param  iterable<int, ToolCall>  $toolCalls
     * @param  iterable<int, ToolResult>  $toolResults
     * @param  Collection<string, string|null>  $reasons
     * @return array<int, array<string, mixed>>
     */
    private function toolCallsFor(iterable $toolCalls, iterable $toolResults, Collection $reasons): array
    {
        $results = collect($toolResults)->keyBy(fn (ToolResult $result): string => $result->id);

        return collect($toolCalls)->map(function (ToolCall $toolCall) use ($results, $reasons): array {
            $result = $results->get($toolCall->id);

            $stored = Arr::except($toolCall->toArray(), ['reasoning_id', 'reasoning_summary', 'reasoning_encrypted_content']);

            if ($toolCall->thoughtSignature === null) {
                unset($stored['thought_signature']);
            }

            /** @var array<string, mixed> $encoded */
            $encoded = [
                ...$stored,
                ...$reasons->has($toolCall->id) ? ['approval_reason' => $reasons[$toolCall->id]] : [],
                ...$result === null ? [] : Arr::only($result->toArray(), ['result', 'denied', 'failed']),
            ];

            return $encoded;
        })->values()->all();
    }

    /**
     * Drop the raw provider blocks of the paused rows a now-completed turn resumed from.
     */
    private function forgetReplayBlocks(string $conversationId): void
    {
        $this->assistantRows($conversationId)
            ->where('status', MessageStatus::Paused->value)
            ->get()
            ->each(function (AiConversationMessage $record): void {
                $steps = $this->decodedSteps($record);

                if ($steps->every(fn (array $step): bool => $step['replay_blocks'] === [])) {
                    return;
                }

                $payload = $record->payload ?? [];
                $payload['steps'] = $this->withoutReplayBlocks($steps)->all();
                $record->forceFill(['payload' => $payload])->save();
            });
    }

    /**
     * Drop the raw provider blocks from the given steps.
     *
     * @param  Collection<int, array<string, mixed>>  $steps
     * @return Collection<int, array<string, mixed>>
     */
    private function withoutReplayBlocks(Collection $steps): Collection
    {
        /** @var Collection<int, array<string, mixed>> $stripped */
        $stripped = $steps->map(function (array $step): array {
            /** @var array<string, mixed> $encoded */
            $encoded = [...$step, 'replay_blocks' => []];

            return $encoded;
        });

        return $stripped;
    }

    /**
     * The reasons a response paused on, keyed by tool call ID.
     *
     * @return Collection<string, string|null>
     */
    private function pendingReasonsFor(AgentResponse $response): Collection
    {
        return $response->pendingApprovals->mapWithKeys(fn (PendingApproval $approval): array => [$approval->id => $approval->reason]);
    }

    /**
     * Get the tool-call IDs a stored row is still awaiting a decision on.
     *
     * @return array<int, string>
     */
    private function pausedCallIds(AiConversationMessage $record): array
    {
        return $this->pendingApprovalsIn($record)->map(fn (PendingApproval $approval): string => $approval->id)->all();
    }

    /**
     * Get the IDs of a stored row's tool calls that were gated behind an approval.
     *
     * @return array<int, string>
     */
    private function gatedCallIds(AiConversationMessage $record): array
    {
        return $this->decodedSteps($record)->flatMap(fn (array $step): array => $step['tool_calls'])
            ->filter(fn (array $toolCall): bool => array_key_exists('approval_reason', $toolCall))
            ->pluck('id')
            ->all();
    }

    /**
     * Rebuild the approvals a stored row is still awaiting a decision on.
     *
     * @return Collection<int, PendingApproval>
     */
    private function pendingApprovalsIn(AiConversationMessage $record): Collection
    {
        return $this->decodedSteps($record)->flatMap(fn (array $step): array => $step['tool_calls'])
            ->filter(PendingApproval::isPending(...))
            ->map(fn (array $toolCall): PendingApproval => new PendingApproval(
                $toolCall['id'],
                $toolCall['name'],
                $toolCall['arguments'],
                $toolCall['approval_reason'],
            ))->values();
    }

    /**
     * Decode a stored row's steps.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function decodedSteps(AiConversationMessage $record): Collection
    {
        /** @var array<int, array<string, mixed>> $raw */
        $raw = is_array($record->payload['steps'] ?? null) ? $record->payload['steps'] : [];

        /** @var Collection<int, array<string, mixed>> $steps */
        $steps = collect($raw)->map(function (array $step): array {
            /** @var array<string, mixed> $decoded */
            $decoded = [
                'content' => (string) ($step['content'] ?? ''),
                'tool_calls' => array_values(is_array($step['tool_calls'] ?? null) ? $step['tool_calls'] : []),
                'reasoning' => (string) ($step['reasoning'] ?? ''),
                'replay_blocks' => $step['replay_blocks'] ?? [],
                'provider_tool_calls' => array_values(is_array($step['provider_tool_calls'] ?? null) ? $step['provider_tool_calls'] : []),
            ];

            return $decoded;
        })->values();

        return $steps;
    }

    /**
     * Rebuild a stored user turn.
     */
    private function userMessageFrom(AiConversationMessage $record): Message
    {
        $content = (string) ($record->payload['content'] ?? '');
        $attachments = $this->rehydrateAttachments($record->payload['attachments'] ?? []);

        return $attachments->isNotEmpty()
            ? new UserMessage($content, $attachments)
            : new Message('user', $content);
    }

    /**
     * Rebuild a stored assistant turn step by step, so every tool result
     * answers the message that made its call.
     *
     * @return array<int, Message>
     */
    private function assistantTurnFrom(AiConversationMessage $record): array
    {
        $pending = $this->pausedCallIds($record);
        $provider = $record->payload['meta']['provider'] ?? null;
        $failed = $record->status === MessageStatus::Failed->value;

        return $this->decodedSteps($record)->flatMap(function (array $step) use ($pending, $provider, $failed): array {
            $content = (string) ($step['content'] ?? '');

            /** @var array<int, array<string, mixed>> $storedCalls */
            $storedCalls = is_array($step['tool_calls'] ?? null) ? $step['tool_calls'] : [];

            $replayed = collect($storedCalls)
                ->filter(fn (array $toolCall): bool => $failed || PendingApproval::isAnswered($toolCall) || in_array($toolCall['id'] ?? null, $pending, true))
                ->values();

            $toolCalls = $replayed->map(ToolCall::fromArray(...));

            $toolResults = $replayed
                ->filter(fn (array $toolCall): bool => PendingApproval::isAnswered($toolCall) || $failed)
                ->map(fn (array $toolCall): ToolResult => PendingApproval::isAnswered($toolCall) ? ToolResult::fromArray($toolCall) : $this->interruptedResultFor($toolCall))
                ->values();

            $replayBlocks = $replayed->count() === count($storedCalls) ? $step['replay_blocks'] : [];

            $isBlank = $content === '' && $toolCalls->isEmpty() && $replayBlocks === [];

            $messages = $isBlank ? [] : [new AssistantMessage($content, $toolCalls, $replayBlocks, $provider)];

            if ($toolResults->isNotEmpty()) {
                $messages[] = new ToolResultMessage($toolResults);
            }

            return $messages;
        })->all();
    }

    /**
     * The result a failed turn's unanswered call replays with.
     *
     * @param  array<string, mixed>  $toolCall
     */
    private function interruptedResultFor(array $toolCall): ToolResult
    {
        return new ToolResult(
            $toolCall['id'],
            $toolCall['name'],
            $toolCall['arguments'] ?? [],
            'This tool call was interrupted before a result was recorded, so it may or may not have run.',
        );
    }

    /**
     * Rehydrate attachments from their stored array representation.
     *
     * @return Collection<int, File>
     */
    private function rehydrateAttachments(mixed $attachments): Collection
    {
        if (! is_array($attachments) || $attachments === []) {
            return collect();
        }

        return collect($attachments)
            ->map(function (mixed $attachment): ?File {
                if (! is_array($attachment)) {
                    throw new RuntimeException('Stored conversation attachment entries must be objects.');
                }

                return File::fromArray($attachment);
            })
            ->filter()
            ->values();
    }

    /**
     * Serialize a user message's attachments to a plain array.
     *
     * @return list<array<string, mixed>>
     */
    private function encodeAttachments(UserMessage $message): array
    {
        if ($message->attachments->isEmpty()) {
            return [];
        }

        $decoded = json_decode((string) $message->attachments->toJson(), true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Query the conversation's assistant rows, newest first.
     *
     * @return Builder<AiConversationMessage>
     */
    private function assistantRows(string $conversationId): Builder
    {
        return AiConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', AiConversationMessage::ROLE_ASSISTANT)
            ->orderByDesc('id');
    }

    /**
     * Base query for conversation messages.
     *
     * @return Builder<AiConversationMessage>
     */
    private function messagesQuery(): Builder
    {
        return AiConversationMessage::query();
    }

    /**
     * Apply the column-mode tenant scope to a conversation query.
     *
     * @param  Builder<AiConversation>  $query
     */
    private function applyTenantScope(Builder $query, int|string|null $userId): void
    {
        if ($this->tenantResolver->isolation() !== TenantIsolation::Column) {
            return;
        }

        $query->forTenant($this->tenantResolver->resolveAccessible($userId, null));
    }

    /**
     * Update the conversation's activity timestamp.
     */
    private function touchConversation(string $conversationId): void
    {
        AiConversation::query()->whereKey($conversationId)->update(['updated_at' => now()]);
    }
}
