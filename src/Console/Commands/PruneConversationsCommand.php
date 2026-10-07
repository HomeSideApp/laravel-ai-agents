<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Console\Commands;

use HomeSide\AiAgents\Contracts\RunsForEachTenant;
use HomeSide\AiAgents\Models\AiConversation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Prunes conversations older than the configured retention window.
 *
 *   php artisan ai-agents:conversations:prune
 *   php artisan ai-agents:conversations:prune --days=90
 *
 * Only conversation memory is affected: AiRun telemetry has its own
 * retention (usage.retention_days) and is never touched here. When
 * conversations.retention.days is null (the default) the command is a no-op,
 * so installations that keep chats until the user deletes them are not
 * surprised. Messages cascade with their conversation.
 *
 * In database isolation mode it runs once per tenant via RunsForEachTenant.
 */
class PruneConversationsCommand extends Command
{
    protected $signature = 'ai-agents:conversations:prune
        {--days= : Prune conversations older than this many days (default: config or none)}';

    protected $description = 'Prune AI conversations older than the retention window (telemetry is unaffected)';

    /**
     * Execute the pruning and print a summary.
     *
     * @return int Exit code — SUCCESS when pruning completes.
     */
    public function handle(RunsForEachTenant $runner): int
    {
        /** @var string|null $days */
        $days = $this->option('days');

        $configured = config('ai-agents.conversations.retention.days');

        if ($days === null && ($configured === null || $configured === '')) {
            $this->line('Conversation retention is disabled (conversations.retention.days is null); nothing pruned.');

            return self::SUCCESS;
        }

        $retention = max(1, (int) ($days ?? $configured));
        $threshold = Carbon::now()->subDays($retention);

        $this->info("Pruning AI conversations not updated since {$threshold->toDateTimeString()}...");

        $pruned = 0;

        $runner->each(function () use ($threshold, &$pruned): void {
            // Delete model instances (not a mass query delete) so the
            // conversation's deleting hook cascades its messages even on
            // connections where database-level foreign keys are not enforced.
            AiConversation::query()
                ->where('updated_at', '<', $threshold)
                ->chunkById(100, function ($conversations) use (&$pruned): void {
                    foreach ($conversations as $conversation) {
                        $conversation->delete();
                        $pruned++;
                    }
                });
        });

        if ($pruned === 0) {
            $this->line('Nothing to prune.');

            return self::SUCCESS;
        }

        $this->info("Pruned {$pruned} conversation(s).");

        return self::SUCCESS;
    }
}
