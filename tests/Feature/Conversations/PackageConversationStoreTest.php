<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Conversations;

use HomeSide\AiAgents\AgentRegistry;
use HomeSide\AiAgents\Conversations\PackageConversationStore;
use HomeSide\AiAgents\Models\AiConversation;
use HomeSide\AiAgents\Models\AiConversationMessage;
use HomeSide\AiAgents\Privacy\UserContentKeyManager;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use HomeSide\AiAgents\Tests\Unit\Fixtures\DummyAgent;
use HomeSide\AiAgents\Tests\Unit\Fixtures\FakeTextProvider;
use HomeSide\AiAgents\Tests\Unit\Fixtures\MemoryAgent;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * PackageConversationStore: durable transcript semantics, per-user payload
 * encryption, tool/approval replay and isolation from AiRun telemetry.
 */
final class PackageConversationStoreTest extends TestCase
{
    private PackageConversationStore $store;

    private TestUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai-agents.conversations.enabled' => true,
            'ai-agents.conversations.storage.mode' => 'encrypted',
        ]);

        $this->user = TestUser::create(['name' => 'Store', 'email' => 'store@example.com']);
        $this->store = $this->app->make(PackageConversationStore::class);
    }

    private function makeConversation(string $agent = 'recipes.generator'): AiConversation
    {
        return AiConversation::create([
            'user_id' => $this->user->id,
            'agent' => $agent,
            'title' => 'Chat',
        ]);
    }

    private function prompt(string $text, ?Decisions $decisions = null): AgentPrompt
    {
        return new AgentPrompt(
            agent: new MemoryAgent,
            prompt: $text,
            attachments: [],
            provider: new FakeTextProvider,
            model: 'fake-model',
            approvalDecisions: $decisions,
        );
    }

    private function response(string $text, array $steps = [], array $pending = []): AgentResponse
    {
        $response = new AgentResponse('invocation', $text, new TextUsage(10, 5), new Meta(provider: 'fake'));
        $response->withSteps(collect($steps));

        if ($pending !== []) {
            $response->withPendingApprovals(collect($pending));
        }

        return $response;
    }

    public function test_store_user_message_persists_encrypted_payload(): void
    {
        $conversation = $this->makeConversation();

        $messageId = $this->store->storeUserMessage(
            $conversation->id,
            null,
            $this->user->id,
            DummyAgent::class,
            new UserMessage('Mi cuenta bancaria es ES12 3456 7890'),
        );

        $raw = (string) DB::table('ai_conversation_messages')->where('id', $messageId)->value('payload');

        $this->assertStringStartsWith('enc:v1:', $raw);
        $this->assertStringNotContainsString('cuenta bancaria', $raw);

        // Transparent read.
        $message = AiConversationMessage::query()->findOrFail($messageId);
        $this->assertSame('Mi cuenta bancaria es ES12 3456 7890', $message->payload['content']);
    }

    public function test_store_assistant_message_encrypts_tool_arguments_and_results(): void
    {
        $conversation = $this->makeConversation();

        $call = new ToolCall('call_1', 'read_balance', ['account' => 'ES99 9999 9999']);
        $result = new ToolResult('call_1', 'read_balance', ['account' => 'ES99 9999 9999'], ['balance' => 1234.56]);

        $step = new Step(
            text: 'Consulting the account',
            toolCalls: [$call],
            toolResults: [$result],
            finishReason: FinishReason::Stop,
            usage: new TextUsage(1, 1),
            meta: new Meta,
            reasoning: '',
            replayBlocks: [],
        );

        $messageId = $this->store->storeAssistantMessage(
            $conversation->id,
            null,
            $this->user->id,
            $this->prompt('balance?'),
            $this->response('Balance is 1234.56', [$step]),
        );

        $this->assertNotNull($messageId);

        $raw = (string) DB::table('ai_conversation_messages')->where('id', $messageId)->value('payload');
        $this->assertStringStartsWith('enc:v1:', $raw);
        $this->assertStringNotContainsString('ES99 9999 9999', $raw);
        $this->assertStringNotContainsString('1234.56', $raw);
    }

    public function test_history_is_replayed_to_agent_and_paused_turn_reconstructed(): void
    {
        $conversation = $this->makeConversation();

        $this->store->storeUserMessage($conversation->id, null, $this->user->id, DummyAgent::class, new UserMessage('primera'));
        $this->store->storeAssistantMessage($conversation->id, null, $this->user->id, $this->prompt('primera'), $this->response('respuesta'));

        $messages = $this->store->getLatestConversationMessages($conversation->id, 10);

        $this->assertCount(2, $messages);
        // Without attachments the SDK rebuilds a plain Message (role user),
        // mirroring DatabaseConversationStore::userMessageFrom().
        $this->assertSame('user', $messages[0]->role->value);
        $this->assertInstanceOf(AssistantMessage::class, $messages[1]);
        $this->assertSame('primera', $messages[0]->content);
        $this->assertSame('respuesta', $messages[1]->content);
    }

    public function test_paused_turn_can_be_reconstructed_and_resumed(): void
    {
        $conversation = $this->makeConversation();

        $pending = new PendingApproval('call_gate', 'delete_file', ['path' => '/etc/passwd'], 'Dangerous');

        $call = new ToolCall('call_gate', 'delete_file', ['path' => '/etc/passwd']);

        // No tool result yet: the step stays pending.
        $step = new Step(
            text: '',
            toolCalls: [$call],
            toolResults: [],
            finishReason: FinishReason::ToolCalls,
            usage: new TextUsage(1, 1),
            meta: new Meta,
            reasoning: '',
            replayBlocks: [],
        );

        $this->store->storeAssistantMessage(
            $conversation->id,
            null,
            $this->user->id,
            $this->prompt('delete it'),
            $this->response('', [$step], [$pending]),
        );

        $approvals = $this->store->pendingApprovalsFor($conversation->id);

        $this->assertCount(1, $approvals);
        $this->assertSame('call_gate', $approvals[0]->id);
        $this->assertSame('delete_file', $approvals[0]->tool);
        $this->assertSame('/etc/passwd', $approvals[0]->arguments['path']);
    }

    public function test_approval_results_are_written_back_and_mismatch_throws(): void
    {
        $conversation = $this->makeConversation();

        $pending = new PendingApproval('call_1', 'write', ['path' => '/tmp/a']);
        $call = new ToolCall('call_1', 'write', ['path' => '/tmp/a']);
        $step = new Step(
            text: '',
            toolCalls: [$call],
            toolResults: [],
            finishReason: FinishReason::ToolCalls,
            usage: new TextUsage(1, 1),
            meta: new Meta,
            reasoning: '',
            replayBlocks: [],
        );

        $this->store->storeAssistantMessage(
            $conversation->id,
            null,
            $this->user->id,
            $this->prompt('write'),
            $this->response('', [$step], [$pending]),
        );

        $this->store->storeApprovalResults($conversation->id, [
            new ToolResult('call_1', 'write', ['path' => '/tmp/a'], 'ok'),
        ]);

        $this->assertSame([], $this->store->pendingApprovalsFor($conversation->id));

        // A mismatch is rejected loudly.
        $this->expectException(ApprovalMismatchException::class);
        $this->store->storeApprovalResults($conversation->id, [
            new ToolResult('call_unknown', 'write', [], 'ok'),
        ]);
    }

    public function test_crypto_shred_makes_messages_unrecoverable(): void
    {
        $conversation = $this->makeConversation();
        $messageId = $this->store->storeUserMessage($conversation->id, null, $this->user->id, DummyAgent::class, new UserMessage('secreto'));

        $this->assertSame('secreto', AiConversationMessage::query()->findOrFail($messageId)->payload['content']);

        app(UserContentKeyManager::class)->shred($this->user->id);

        $this->assertNull(AiConversationMessage::query()->findOrFail($messageId)->payload);
    }

    public function test_deleting_conversation_cascades_to_messages(): void
    {
        $conversation = $this->makeConversation();
        $this->store->storeUserMessage($conversation->id, null, $this->user->id, DummyAgent::class, new UserMessage('hola'));

        $this->assertSame(1, AiConversationMessage::query()->where('conversation_id', $conversation->id)->count());

        $conversation->delete();

        $this->assertSame(0, AiConversationMessage::query()->where('conversation_id', $conversation->id)->count());
    }

    public function test_max_messages_window_caps_history(): void
    {
        config(['ai-agents.conversations.context.max_messages' => 2]);

        $conversation = $this->makeConversation();

        for ($i = 0; $i < 4; $i++) {
            $this->store->storeUserMessage($conversation->id, null, $this->user->id, DummyAgent::class, new UserMessage("m{$i}"));
        }

        $messages = $this->store->getLatestConversationMessages($conversation->id, 100);

        $this->assertCount(2, $messages);
        $this->assertSame('m2', $messages[0]->content);
        $this->assertSame('m3', $messages[1]->content);
    }

    public function test_latest_conversation_id_translates_agent_class_to_key(): void
    {
        // Register the canonical key so the reverse lookup resolves.
        $this->app->make(AgentRegistry::class)->register('recipes.generator', DummyAgent::class);

        $conversation = $this->makeConversation();
        $this->store->storeUserMessage($conversation->id, null, $this->user->id, DummyAgent::class, new UserMessage('hola'));

        $latest = $this->store->latestConversationId('user', $this->user->id, DummyAgent::class);

        $this->assertSame($conversation->id, $latest);
    }

    public function test_conversation_belongs_to_never_trusts_the_uuid_alone(): void
    {
        $other = TestUser::create(['name' => 'Other', 'email' => 'other-store@example.com']);
        $conversation = $this->makeConversation();
        $this->store->storeUserMessage($conversation->id, null, $this->user->id, DummyAgent::class, new UserMessage('hola'));

        $this->assertTrue($this->store->conversationBelongsTo($conversation->id, 'user', $this->user->id));
        $this->assertFalse($this->store->conversationBelongsTo($conversation->id, 'user', $other->id));
    }

    public function test_failed_steps_are_replayed_with_an_interrupted_result(): void
    {
        $conversation = $this->makeConversation();

        $call = new ToolCall('call_fail', 'send_money', ['amount' => 999]);
        $step = new Step(
            text: '',
            toolCalls: [$call],
            toolResults: [],
            finishReason: FinishReason::ToolCalls,
            usage: new TextUsage(1, 1),
            meta: new Meta,
            reasoning: '',
            replayBlocks: [],
        );

        $this->store->storeAssistantMessage(
            $conversation->id,
            null,
            $this->user->id,
            $this->prompt('send'),
            $this->response('', [$step]),
            new \RuntimeException('provider died'),
        );

        $messages = $this->store->getLatestConversationMessages($conversation->id, 10);

        // The step replays as [AssistantMessage, ToolResultMessage].
        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[1]);
        $this->assertStringContainsString('interrupted', (string) $messages[1]->toolResults[0]->result);
    }
}
