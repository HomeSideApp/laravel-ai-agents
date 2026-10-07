<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Conversations;

use HomeSide\AiAgents\AgentRegistry;
use HomeSide\AiAgents\AiAgentManager;
use HomeSide\AiAgents\Exceptions\ConversationNotFoundException;
use HomeSide\AiAgents\Exceptions\ConversationNotSupportedException;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Models\AiAgent;
use HomeSide\AiAgents\Models\AiConversation;
use HomeSide\AiAgents\Models\AiConversationMessage;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiRun;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use HomeSide\AiAgents\Tests\Unit\Fixtures\DummyAgent;
use HomeSide\AiAgents\Tests\Unit\Fixtures\MemoryAgent;
use HomeSide\AiAgents\Tests\Unit\Fixtures\SdkBoundaryAgent;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;

/**
 * End-to-end conversation memory through AiAgentManager.
 *
 * Proves that memory is created and continued for conversational agents,
 * rejected for stateless agents, never leaks across users/agents, and that
 * the conversation lifecycle is independent from the AiRun telemetry
 * lifecycle.
 */
final class ConversationMemoryIntegrationTest extends TestCase
{
    private TestUser $user;

    private DummyAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai-agents.conversations.enabled' => true,
            'ai-agents.conversations.storage.mode' => 'encrypted',
        ]);

        $this->user = TestUser::create(['name' => 'Mem', 'email' => 'mem@example.com']);
        $this->agent = new DummyAgent;

        $this->app->instance(DummyAgent::class, $this->agent);
        $this->app->make(AgentRegistry::class)->register($this->agent->key(), $this->agent::class);

        AiAgent::createValidated([
            'key' => $this->agent->key(),
            'module' => $this->agent->module(),
            'label' => 'Generator',
            'platform_prompt' => 'You generate things.',
        ]);
    }

    private function makeProvider(): AiProvider
    {
        return AiProvider::createValidated([
            'name' => 'P'.uniqid(),
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-test-1234',
            'module' => $this->agent->module(),
            'privacy_level' => 'local',
            'fallback_policy' => 'allow_cloud',
            'scope' => 'global',
        ]);
    }

    private function registerSdkAgent(string $class, string $reply = 'reply'): void
    {
        $instance = new $class;
        $instance->reply = $reply;
        $this->app->instance($class, $instance);
        $this->app->make(AgentRegistry::class)->register($this->agent->key(), $class);
    }

    private function context(?string $conversationId = null): AiExecutionContextData
    {
        return new AiExecutionContextData(
            userId: $this->user->id,
            conversationId: $conversationId,
        );
    }

    private function manager(): AiAgentManager
    {
        return $this->app->make(AiAgentManager::class);
    }

    public function test_new_conversation_is_created_with_messages(): void
    {
        $this->registerSdkAgent(MemoryAgent::class);
        $this->makeProvider();

        $result = $this->manager()->run($this->agent->key(), $this->context(), 'Mi color favorito es verde.');

        $this->assertNotNull($result->conversationId);
        $this->assertSame(1, AiConversation::query()->count());
        $this->assertSame($result->conversationId, AiConversation::query()->value('id'));
    }

    public function test_second_prompt_continues_same_conversation(): void
    {
        $this->registerSdkAgent(MemoryAgent::class);
        $this->makeProvider();

        $first = $this->manager()->run($this->agent->key(), $this->context(), 'Hola');
        $this->app->forgetInstance(MemoryAgent::class);
        $this->registerSdkAgent(MemoryAgent::class);

        $second = $this->manager()->run(
            $this->agent->key(),
            $this->context($first->conversationId),
            '¿Seguimos?',
        );

        $this->assertSame($first->conversationId, $second->conversationId);
        $this->assertSame(1, AiConversation::query()->count());
    }

    public function test_stateless_agent_with_conversation_id_is_rejected(): void
    {
        $sdkAgent = new SdkBoundaryAgent;
        $sdkAgent->reply = 'x';
        $this->app->instance(SdkBoundaryAgent::class, $sdkAgent);
        $this->app->make(AgentRegistry::class)->register($this->agent->key(), SdkBoundaryAgent::class);
        $this->makeProvider();

        $this->expectException(ConversationNotSupportedException::class);

        $this->manager()->run(
            $this->agent->key(),
            $this->context('00000000-0000-7000-8000-000000000000'),
            'Hola',
        );
    }

    public function test_another_users_conversation_is_not_accessible(): void
    {
        $this->registerSdkAgent(MemoryAgent::class);
        $this->makeProvider();

        $first = $this->manager()->run($this->agent->key(), $this->context(), 'Hola');

        $other = TestUser::create(['name' => 'Other', 'email' => 'other-mem@example.com']);

        $this->expectException(ConversationNotFoundException::class);

        $this->manager()->run(
            $this->agent->key(),
            new AiExecutionContextData(userId: $other->id, conversationId: $first->conversationId),
            'Dame la conversación ajena',
        );
    }

    public function test_nonexistent_conversation_throws_like_forbidden(): void
    {
        $this->registerSdkAgent(MemoryAgent::class);
        $this->makeProvider();

        $this->expectException(ConversationNotFoundException::class);

        $this->manager()->run(
            $this->agent->key(),
            $this->context('00000000-0000-7000-8000-000000000000'),
            'Hola',
        );
    }

    public function test_telemetry_lifecycle_is_independent_from_conversation_lifecycle(): void
    {
        $this->registerSdkAgent(MemoryAgent::class);
        $this->makeProvider();

        $first = $this->manager()->run($this->agent->key(), $this->context(), 'Mi color favorito es verde.');

        $this->assertSame(1, AiRun::query()->count());
        $this->assertNotNull($first->conversationId);

        // Consolidate telemetry: delete the run.
        AiRun::query()->delete();
        $this->assertSame(0, AiRun::query()->count());

        // The conversation survives intact.
        $this->assertSame(1, AiConversation::query()->count());
        $this->assertGreaterThan(0, AiConversationMessage::query()->count());
    }

    public function test_failed_empty_first_turn_leaves_no_ghost_conversation(): void
    {
        // A conversational agent whose prompt() always throws.
        $this->app->bind(FailingMemoryAgent::class, fn (): FailingMemoryAgent => new FailingMemoryAgent);
        $this->app->make(AgentRegistry::class)->register($this->agent->key(), FailingMemoryAgent::class);
        $this->makeProvider();

        try {
            $this->manager()->run($this->agent->key(), $this->context(), 'Hola');
            $this->fail('The failing agent should have thrown.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, AiConversation::query()->count());
    }
}

/**
 * Conversational agent whose prompt() fails before storing any message.
 */
final class FailingMemoryAgent extends MemoryAgent
{
    public function prompt(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        throw new \RuntimeException('provider exploded');
    }
}
