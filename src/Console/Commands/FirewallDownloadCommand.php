<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Console\Commands;

use HomeSide\AiAgents\Security\HuggingFaceDownloader;
use Illuminate\Console\Command;

/**
 * Downloads a HuggingFace dataset as JSONL for firewall training.
 *
 *   php artisan ai-agents:firewall:download neuralchemy/Prompt-injection-dataset
 *   php artisan ai-agents:firewall:download deepset/prompt-injections --config=full --split=train
 *
 * The downloaded file is written to storage/app/private/firewall-datasets/
 * and can be used directly with firewall:train --source=<adapter>.
 */
class FirewallDownloadCommand extends Command
{
    protected $signature = 'ai-agents:firewall:download
        {dataset : HuggingFace dataset ID (e.g. neuralchemy/Prompt-injection-dataset)}
        {--config= : Dataset config name (default: first available config)}
        {--split=train : Split to download (train/test/validation)}
        {--out= : Output path (default: storage/app/private/firewall-datasets/{slug}.jsonl)}
        {--info : Show dataset metadata without downloading}';

    protected $description = 'Download a HuggingFace dataset as JSONL for firewall training';

    /**
     * Execute the command: download a HuggingFace dataset as JSONL or,
     * when --info is passed, display its metadata without downloading.
     *
     * @return int Exit code — SUCCESS when the download completes or info
     *             is displayed, FAILURE on validation or network errors.
     */
    public function handle(): int
    {
        $dataset = $this->argument('dataset');

        if (! is_string($dataset) || $dataset === '') {
            $this->error('The dataset argument must be a HuggingFace dataset ID.');

            return self::FAILURE;
        }

        $downloader = new HuggingFaceDownloader;

        // Show info only
        if ((bool) $this->option('info')) {
            return $this->showInfo($downloader, $dataset);
        }

        /** @var string $config */
        $config = $this->option('config');
        /** @var string $split */
        $split = $this->option('split') ?? 'train';
        /** @var string $out */
        $out = $this->option('out');

        $this->info("Downloading {$dataset}...");

        try {
            $result = $downloader->download(
                $dataset,
                is_string($config) && $config !== '' ? $config : 'default',
                $split,
                is_string($out) && $out !== '' ? $out : null,
            );
        } catch (\Throwable $exception) {
            $this->error('Download failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Download complete.');
        $this->table(['Property', 'Value'], [
            ['File', $result['path']],
            ['Rows', $result['rows']],
            ['Features', implode(', ', $result['features'])],
        ]);

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Verify the adapter: resources/firewall/datasets/'.basename($dataset).'.php');
        $this->line('  2. Train: php artisan ai-agents:firewall:train '.$result['path'].' --source='.basename($dataset));
        $this->line('  3. Evaluate: php artisan ai-agents:firewall:evaluate <model.rbx> '.$result['path']);

        return self::SUCCESS;
    }

    /**
     * Fetch and display dataset metadata (licence, configs, features, tags)
     * from the HuggingFace datasets-server API without downloading rows.
     *
     * @param  HuggingFaceDownloader  $downloader  Service that wraps the
     *                                             HuggingFace REST API.
     * @param  string  $dataset  HuggingFace dataset ID,
     *                           e.g. "neuralchemy/Prompt-injection-dataset".
     * @return int Exit code — SUCCESS when info is printed, FAILURE when
     *             the API request fails.
     */
    private function showInfo(HuggingFaceDownloader $downloader, string $dataset): int
    {
        try {
            $info = $downloader->info($dataset);
        } catch (\Throwable $exception) {
            $this->error('Could not fetch info: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Dataset: {$info['id']}");
        $this->table(['Property', 'Value'], [
            ['License', $info['license']],
            ['Configs', implode(', ', $info['configs']) ?: 'none found'],
            ['Features', implode(', ', $info['features']) ?: 'unknown'],
            ['Tags', implode(', ', array_slice($info['tags'], 0, 10)).(count($info['tags']) > 10 ? '...' : '')],
        ]);

        $this->newLine();
        $this->line('Download with:');
        $this->line("  php artisan ai-agents:firewall:download {$dataset}");

        return self::SUCCESS;
    }
}
