<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Conversations;

use HomeSide\AiAgents\Models\AiConversation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deletes conversations whose activity predates a retention threshold.
 *
 * The candidate scan cannot be trusted by itself: between the scan and the
 * delete a request may have continued the conversation (touching updated_at),
 * so every row is RE-READ under a row lock just before deletion and skipped
 * if it is no longer expired. This closes the race between "continue a
 * conversation" and "prune abandoned ones" without holding a transaction
 * during the model call.
 *
 * The deletion goes through the model instance so the conversation's deleting
 * hook cascades its messages even on connections where database-level foreign
 * keys are not enforced.
 */
final class ConversationPruner
{
    /**
     * Prune one candidate if it is still expired, locking the row.
     *
     * @param  string  $conversationId  The candidate conversation id.
     * @param  Carbon  $threshold  Rows updated at or after this are kept.
     * @return bool True when the conversation was actually deleted.
     */
    public function pruneIfExpired(string $conversationId, Carbon $threshold): bool
    {
        return (bool) DB::transaction(function () use ($conversationId, $threshold): bool {
            /** @var AiConversation|null $conversation */
            $conversation = AiConversation::query()
                ->whereKey($conversationId)
                ->lockForUpdate()
                ->first();

            if ($conversation === null) {
                return false;
            }

            // Re-check under the lock: a concurrent continue may have
            // refreshed updated_at after the candidate scan read it.
            if ($conversation->updated_at === null || $conversation->updated_at->greaterThanOrEqualTo($threshold)) {
                return false;
            }

            $conversation->delete();

            return true;
        });
    }

    /**
     * Prune every conversation older than the threshold.
     *
     * @param  Carbon  $threshold  Rows updated at or after this are kept.
     * @return int The number of conversations actually deleted.
     */
    public function pruneExpired(Carbon $threshold): int
    {
        $pruned = 0;

        AiConversation::query()
            ->where('updated_at', '<', $threshold)
            ->chunkById(100, function ($conversations) use ($threshold, &$pruned): void {
                foreach ($conversations as $conversation) {
                    if ($this->pruneIfExpired((string) $conversation->id, $threshold)) {
                        $pruned++;
                    }
                }
            });

        return $pruned;
    }
}
