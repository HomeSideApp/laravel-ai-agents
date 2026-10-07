<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Fixtures;

use HomeSide\AiAgents\Contracts\AcceptsRuntimeConfiguration;
use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Stringable;

/**
 * Conversational DomainAgent for memory tests.
 *
 * Implements the SDK's RemembersConversations contract AND uses its trait,
 * exercising the SDK-native declaration path. prompt() records the messages
 * the SDK would have sent so tests can assert that history was replayed,
 * then returns a real AgentResponse.
 */
class MemoryAgent extends DummyAgent implements AcceptsRuntimeConfiguration, Agent, RemembersConversations
{
    use RemembersConversationsTrait;

    /**
     * The reply text prompt() returns; tests set it before binding.
     */
    public string $reply = 'memory reply';

    /**
     * The messages (history + current turn) seen by the last prompt().
     *
     * @var list<mixed>
     */
    public array $receivedMessages = [];

    /**
     * The prompt string of the last prompt() call.
     */
    public string $lastPrompt = '';

    /** @var array<string, int|float> */
    public array $runtimeConfiguration = [];

    /** @param array<string, int|float> $parameters */
    public function setRuntimeConfiguration(array $parameters): void
    {
        $this->runtimeConfiguration = $parameters;
    }

    public function instructions(): Stringable|string
    {
        return 'Conversational agent for tests.';
    }

    public function prompt(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        $this->lastPrompt = is_string($prompt) ? $prompt : '';
        $this->receivedMessages = is_iterable($this->messages()) ? iterator_to_array($this->messages()) : [];

        $response = (new AgentResponse('test-invocation', $this->reply, new TextUsage, new Meta))
            ->withinConversation((string) $this->currentConversation());

        // Emulate Laravel AI's RememberConversation middleware, which the real
        // SDK pipeline runs: persist the user turn and the assistant turn so
        // the fixture exercises the package conversation store end to end.
        $conversationId = $this->currentConversation();

        if ($conversationId !== null) {
            $participant = $this->conversationParticipant();
            [$participantType, $participantId] = $participant === null
                ? [null, null]
                : [Conversation::participantType($participant), Conversation::participantKey($participant)];

            $store = resolve(ConversationStore::class);

            $userMessageId = $store->storeUserMessage(
                $conversationId,
                $participantType,
                $participantId,
                static::class,
                new UserMessage(is_string($prompt) ? $prompt : '', $attachments),
            );

            $assistantMessageId = $store->storeAssistantMessage(
                $conversationId,
                $participantType,
                $participantId,
                new AgentPrompt(
                    agent: $this,
                    prompt: is_string($prompt) ? $prompt : '',
                    attachments: $attachments,
                    provider: new FakeTextProvider,
                    model: $model ?? 'fake-model',
                ),
                $response,
            );

            $response->withinConversation($conversationId, $participant)->withStoredMessages($userMessageId, $assistantMessageId);
        }

        return $response;
    }

    public function stream(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StreamableAgentResponse {
        throw new \BadMethodCallException('stream() is not used in tests.');
    }

    public function queue(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new \BadMethodCallException('queue() is not used in tests.');
    }

    public function broadcast(
        AgentInput|UserMessage|Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        bool $now = false,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new \BadMethodCallException('broadcast() is not used in tests.');
    }

    public function broadcastNow(
        AgentInput|UserMessage|Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new \BadMethodCallException('broadcastNow() is not used in tests.');
    }

    public function broadcastOnQueue(
        AgentInput|UserMessage|Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new \BadMethodCallException('broadcastOnQueue() is not used in tests.');
    }
}
