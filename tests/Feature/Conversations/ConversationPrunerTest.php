<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Conversations;

use HomeSide\AiAgents\Conversations\ConversationPruner;
use HomeSide\AiAgents\Models\AiConversation;
use HomeSide\AiAgents\Models\AiConversationMessage;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use Illuminate\Support\Carbon;

/**
 * The pruner re-reads each candidate under a lock before deleting, so a
 * conversation refreshed after the candidate scan is not removed.
 */
final class ConversationPrunerTest extends TestCase
{
    private TestUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = TestUser::create(['name' => 'Pruner', 'email' => 'pruner@example.com']);
    }

    private function makeConversation(int $ageDays): AiConversation
    {
        $conversation = AiConversation::create([
            'user_id' => $this->user->id,
            'agent' => 'recipes.generator',
            'title' => 'Chat',
        ]);

        $conversation->forceFill([
            'created_at' => Carbon::now()->subDays($ageDays),
            'updated_at' => Carbon::now()->subDays($ageDays),
        ])->save();

        return $conversation;
    }

    public function test_prune_if_expired_deletes_a_still_old_conversation(): void
    {
        $conversation = $this->makeConversation(31);
        $threshold = Carbon::now()->subDays(30);

        $deleted = $this->app->make(ConversationPruner::class)
            ->pruneIfExpired($conversation->id, $threshold);

        $this->assertTrue($deleted);
        $this->assertNull(AiConversation::query()->find($conversation->id));
    }

    public function test_prune_if_expired_skips_a_refreshed_conversation(): void
    {
        $conversation = $this->makeConversation(31);
        $threshold = Carbon::now()->subDays(30);

        // Simulate a continue that won the race after the candidate scan.
        $conversation->touch();

        $deleted = $this->app->make(ConversationPruner::class)
            ->pruneIfExpired($conversation->id, $threshold);

        $this->assertFalse($deleted);
        $this->assertNotNull(AiConversation::query()->find($conversation->id));
    }

    public function test_prune_if_expired_returns_false_for_a_missing_row(): void
    {
        $deleted = $this->app->make(ConversationPruner::class)
            ->pruneIfExpired('00000000-0000-7000-8000-000000000000', Carbon::now()->subDays(30));

        $this->assertFalse($deleted);
    }

    public function test_prune_if_expired_cascades_messages(): void
    {
        $conversation = $this->makeConversation(31);

        AiConversationMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->user->id,
            'agent' => 'recipes.generator',
            'role' => AiConversationMessage::ROLE_USER,
            'payload' => ['content' => 'hola', 'attachments' => [], 'steps' => [], 'meta' => []],
            'status' => AiConversationMessage::STATUS_COMPLETED,
        ]);

        $this->app->make(ConversationPruner::class)
            ->pruneIfExpired($conversation->id, Carbon::now()->subDays(30));

        $this->assertSame(0, AiConversationMessage::query()->where('conversation_id', $conversation->id)->count());
    }
}
