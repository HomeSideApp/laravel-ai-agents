<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Console\Commands;

use HomeSide\AiAgents\ModelsDev\ModelsDevSynchronizer;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Synchronises the models.dev reference catalog into the local tables.
 *
 *   php artisan ai-agents:models-dev:sync
 *   php artisan ai-agents:models-dev:sync --force   # re-download logos
 *   php artisan ai-agents:models-dev:sync --skip-logos
 *
 * Designed to run manually or scheduled (the service provider registers a
 * daily 02:00 schedule by default); see config('ai-agents.models_dev').
 */
class ModelsDevSyncCommand extends Command
{
    protected $signature = 'ai-agents:models-dev:sync
        {--force : Re-download provider logos even if they already exist locally}
        {--skip-logos : Do not download provider logos}';

    protected $description = 'Sync the models.dev catalog (providers, models, pricing, logos) into the local reference tables';

    /**
     * Execute the command: fetch the catalog, upsert providers/models and
     * download logos, then print a summary table.
     *
     * @return int Exit code — SUCCESS when the sync completed (even with
     *             per-item warnings), FAILURE when the catalog could not
     *             be fetched at all.
     */
    public function handle(ModelsDevSynchronizer $synchronizer): int
    {
        $force = (bool) $this->option('force');
        $skipLogos = (bool) $this->option('skip-logos');

        $this->info('Syncing models.dev catalog...');

        try {
            $stats = $synchronizer->sync($force, $skipLogos);
        } catch (RuntimeException $exception) {
            $this->error('Sync failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Providers created', $stats['providers_created']],
            ['Providers updated', $stats['providers_updated']],
            ['Models created', $stats['models_created']],
            ['Models updated', $stats['models_updated']],
            ['Logos downloaded', $stats['logos_downloaded']],
            ['Warnings', count($stats['errors'])],
        ]);

        foreach ($stats['errors'] as $error) {
            $this->warn('  - '.$error);
        }

        if (($stats['providers_created'] + $stats['providers_updated']) === 0) {
            $this->warn('No providers were synced.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Sync complete.');

        return self::SUCCESS;
    }
}
