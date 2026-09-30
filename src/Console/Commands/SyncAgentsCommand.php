<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Console\Commands;

use HomeSide\AiAgents\Contracts\RunsForEachTenant;
use HomeSide\AiAgents\Synchronizer\AgentSynchronizer;
use Illuminate\Console\Command;

/**
 * Synchronise registered agents into the ai_agents table.
 *
 *   php artisan ai-agents:sync-agents
 *   php artisan ai-agents:sync-agents --prune-report
 *
 * In `database` isolation mode the command iterates over every tenant and
 * runs the synchroniser inside each tenant's database so agents are
 * registered per-tenant.  In `none` / `column` modes it runs once.
 *
 * Use the `--prune-report` flag to also see a diagnosis of orphaned rows
 * (ai_agents rows whose registered class disappeared).
 */
class SyncAgentsCommand extends Command
{
    protected $signature = 'ai-agents:sync-agents
        {--prune-report : Show a diagnosis of orphaned / missing rows}';

    protected $description = 'Synchronise registered agents with the ai_agents table (per-tenant in database mode)';

    /**
     * Execute the synchronisation.
     *
     * @return int Exit code — SUCCESS when sync completes.
     */
    public function handle(AgentSynchronizer $synchronizer, RunsForEachTenant $runner): int
    {
        $this->info('Synchronising AI agents...');

        if ((bool) $this->option('prune-report')) {
            $this->showPruneReport($synchronizer);
        }

        $stats = ['created' => 0, 'unchanged' => 0, 'orphaned' => 0];

        $runner->each(function () use ($synchronizer, &$stats): void {
            $result = $synchronizer->sync();
            $stats['created'] += $result->created;
            $stats['unchanged'] += $result->unchanged;
            $stats['orphaned'] += count($result->orphanedKeys);
        });

        $this->table(['Metric', 'Count'], [
            ['Created', $stats['created']],
            ['Unchanged', $stats['unchanged']],
            ['Orphaned rows (no registered class)', $stats['orphaned']],
        ]);

        $this->info('Sync complete.');

        return self::SUCCESS;
    }

    /**
     * Show a diagnosis of orphaned rows and registered agents without rows.
     */
    private function showPruneReport(AgentSynchronizer $synchronizer): void
    {
        // Re-sync just to collect the result without changing anything
        // (sync is idempotent).
        $result = $synchronizer->sync();

        if (count($result->orphanedKeys) > 0 || count($result->missingRows) > 0) {
            $this->warn('Diagnosis report:');

            if (count($result->orphanedKeys) > 0) {
                $this->comment('  - '.count($result->orphanedKeys).' row(s) in ai_agents have no matching registered class. Consider pruning them.');
            }

            if (count($result->missingRows) > 0) {
                $this->comment('  - '.count($result->missingRows).' registered class(es) have no ai_agents row. These were created during sync.');
            }

            $this->newLine();
        }
    }
}
