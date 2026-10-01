<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Console\Commands;

use HomeSide\AiAgents\Contracts\RunsForEachTenant;
use HomeSide\AiAgents\Models\AiActionProposal;
use Illuminate\Console\Command;

/**
 * Expire stale pending proposals.
 *
 *   php artisan ai-agents:proposals:expire
 *
 * Iterates in batches (chunked by ID) and fires an event for each expired
 * proposal. Works across all tenant modes through the RunsForEachTenant runner.
 */
final class ExpireProposalsCommand extends Command
{
    protected $signature = 'ai-agents:proposals:expire';

    protected $description = 'Mark stale pending proposals as expired';

    /**
     * Execute the command.
     *
     * @param  RunsForEachTenant  $runner  The per-tenant command runner.
     * @return int Exit code — SUCCESS when completion completes.
     */
    public function handle(RunsForEachTenant $runner): int
    {
        $this->info('Expiring stale proposals...');

        $total = 0;

        $runner->each(function () use (&$total): void {
            $count = AiActionProposal::expireStale();

            if ($count > 0) {
                $this->line("Expired {$count} proposal(s).");
            }

            $total += $count;
        });

        if ($total === 0) {
            $this->line('No stale proposals to expire.');
        }

        $this->info('Expiry complete.');

        return self::SUCCESS;
    }
}
