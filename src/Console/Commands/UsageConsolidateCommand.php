<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Console\Commands;

use HomeSide\AiAgents\Execution\CostEstimator;
use Illuminate\Console\Command;

/**
 * Consolidates old runs into ai_usage_daily and prunes them.
 *
 *   php artisan ai-agents:usage:consolidate
 *   php artisan ai-agents:usage:consolidate --days=90
 *
 * Designed for the daily scheduler (see config('ai-agents.usage')); safe to
 * run manually at any time — the rollup is recomputed from the source rows
 * so re-runs heal rather than double-count.
 */
class UsageConsolidateCommand extends Command
{
    protected $signature = 'ai-agents:usage:consolidate
        {--days= : Consolidate runs older than this many days (default: config or 30)}';

    protected $description = 'Consolidate finished AI runs into daily usage rows and prune them';

    /**
     * Execute the consolidation and print a summary.
     *
     * @return int Exit code — SUCCESS when consolidation completes.
     */
    public function handle(CostEstimator $estimator): int
    {
        /** @var string|null $days */
        $days = $this->option('days');
        $retention = max(1, (int) ($days ?? config('ai-agents.usage.retention_days', 30)));

        $this->info("Consolidating AI runs older than {$retention} day(s)...");

        $stats = $estimator->consolidate($retention);

        if ($stats['runs_consolidated'] === 0) {
            $this->line('Nothing to consolidate.');

            return self::SUCCESS;
        }

        $this->table(['Metric', 'Count'], [
            ['Periods consolidated', $stats['periods']],
            ['Runs consolidated', $stats['runs_consolidated']],
        ]);

        $this->info('Consolidation complete.');

        return self::SUCCESS;
    }
}
