<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Conversations;

use HomeSide\AiAgents\AgentRegistry;
use HomeSide\AiAgents\Conversations\ConversationManager;
use HomeSide\AiAgents\Exceptions\ConversationNotFoundException;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Models\AiAgent;
use HomeSide\AiAgents\Models\AiConversation;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use HomeSide\AiAgents\Tests\Unit\Fixtures\DummyAgent;
use Illuminate\Support\Carbon;

/**
 * Continuing a conversation refreshes its activity timestamp, so retention
 * pruning can never delete a conversation that was just used.
 */
final class ConversationRetentionRaceTest extends TestCase
{
    private TestUser $user;

    private DummyAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai-agents.conversations.enabled' => true,
            'ai-agents.conversations.storage.mode' => 'encrypted',
            'ai-agents.conversations.retention.days' => 30,
        ]);

        $this->user = TestUser::create(['name' => 'Race', 'email' => 'race@example.com']);
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

    private function oldConversation(int $ageDays = 31): AiConversation
    {
        $conversation = AiConversation::create([
            'user_id' => $this->user->id,
            'agent' => $this->agent->key(),
            'title' => 'Chat',
        ]);

        $conversation->forceFill([
            'created_at' => Carbon::now()->subDays($ageDays),
            'updated_at' => Carbon::now()->subDays($ageDays),
        ])->save();

        return $conversation;
    }

    public function test_continuing_an_old_conversation_refreshes_its_activity(): void
    {
        $conversation = $this->oldConversation(31);

        $this->app->make(ConversationManager::class)->resolveOrCreate(
            $this->agent->key(),
            new AiExecutionContextData(userId: $this->user->id),
            $conversation->id,
        );

        $fresh = AiConversation::query()->findOrFail($conversation->id);
        $this->assertTrue($fresh->updated_at->greaterThan(Carbon::now()->subDay()));
    }

    public function test_reused_conversation_survives_pruning_while_an_idle_one_is_deleted(): void
    {
        $used = $this->oldConversation(31);
        $idle = $this->oldConversation(31);

        $this->app->make(ConversationManager::class)->resolveOrCreate(
            $this->agent->key(),
            new AiExecutionContextData(userId: $this->user->id),
            $used->id,
        );

        $this->artisan('ai-agents:conversations:prune')->assertSuccessful();

        $this->assertNotNull(AiConversation::query()->find($used->id));
        $this->assertNull(AiConversation::query()->find($idle->id));
    }

    public function test_foreign_conversation_is_not_touched_on_failed_continue(): void
    {
        $other = TestUser::create(['name' => 'Other', 'email' => 'other-race@example.com']);

        $conversation = AiConversation::create([
            'user_id' => $other->id,
            'agent' => $this->agent->key(),
            'title' => 'Chat',
        ]);
        $conversation->forceFill([
            'created_at' => Carbon::now()->subDays(31),
            'updated_at' => Carbon::now()->subDays(31),
        ])->save();

        try {
            $this->app->make(ConversationManager::class)->resolveOrCreate(
                $this->agent->key(),
                new AiExecutionContextData(userId: $this->user->id),
                $conversation->id,
            );
            $this->fail('A foreign conversation should not be accessible.');
        } catch (ConversationNotFoundException) {
            // expected
        }

        // The foreign row must be untouched (no write before authorisation).
        $untouched = AiConversation::query()->findOrFail($conversation->id);
        $this->assertTrue($untouched->updated_at->lessThan(Carbon::now()->subDay()));
    }
}
