<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Console\Commands;

use Illuminate\Console\Command;

/**
 * Lists available dataset adapters and their verification status.
 *
 *   php artisan ai-agents:firewall:datasets
 *
 * Shows all adapter files in resources/firewall/datasets/, their configured
 * column mappings, and whether a downloaded dataset file exists locally.
 */
class FirewallDatasetsCommand extends Command
{
    protected $signature = 'ai-agents:firewall:datasets';

    protected $description = 'List available firewall dataset adapters and their status';

    /**
     * Execute the command: list every adapter file, its column mapping, and
     * whether a corresponding downloaded dataset exists on disk.
     *
     * @return int Exit code — SUCCESS when the table is printed, FAILURE
     *             when the adapter directory is missing.
     */
    public function handle(): int
    {
        $adapterDir = dirname(__DIR__, 2).'/resources/firewall/datasets';

        if (! is_dir($adapterDir)) {
            $this->error("Adapter directory not found: {$adapterDir}");

            return self::FAILURE;
        }

        $files = glob($adapterDir.'/*.php');

        if ($files === false || $files === []) {
            $this->warn('No dataset adapters found in resources/firewall/datasets/.');

            return self::SUCCESS;
        }

        $this->info('Firewall dataset adapters');
        $this->line('');

        $rows = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');

            /** @var mixed $config */
            $config = require $file;

            if (! is_array($config)) {
                $rows[] = [$name, 'INVALID', '-', '-', 'config did not return array'];

                continue;
            }

            $textCol = $config['text'] ?? '?';
            $labelCol = $config['label'] ?? '?';
            $positive = implode('/', array_slice($config['positive'] ?? [], 0, 3));
            $negative = implode('/', array_slice($config['negative'] ?? [], 0, 3));

            // Check if a downloaded file exists
            $localDir = storage_path('app/private/firewall-datasets');
            $localFiles = glob($localDir.'/'.$name.'-*.jsonl');
            $status = ($localFiles !== false && $localFiles !== []) ? count($localFiles).' file(s) downloaded' : 'not downloaded';

            $rows[] = [$name, "text={$textCol}, label={$labelCol}", $positive, $negative, $status];
        }

        $this->table(['Adapter', 'Columns', 'Positive labels', 'Negative labels', 'Status'], $rows);

        $this->newLine();
        $this->line('Download a dataset:');
        $this->line('  php artisan ai-agents:firewall:download <dataset-id>');
        $this->line('');
        $this->line('Train with an adapter:');
        $this->line('  php artisan ai-agents:firewall:train <file.jsonl> --source=<adapter-name>');

        return self::SUCCESS;
    }
}
