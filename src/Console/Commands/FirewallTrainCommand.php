<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Console\Commands;

use HomeSide\AiAgents\Security\Classifier\HashedTextClassifier;
use HomeSide\AiAgents\Security\CorpusLoader;
use HomeSide\AiAgents\Security\DatasetAdapter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Trains the firewall classifier through rubix/ml and writes its model
 * artifact.
 *
 *   php artisan ai-agents:firewall:train corpus.jsonl --test=held-out.jsonl
 *
 * The corpus is a JSONL/CSV file (or a public dataset mapped through a
 * --source adapter) of {text, label} rows. The artifact is a rubix
 * PersistentModel: a serialised pipeline (lowercase → token hashing →
 * L1 → logistic regression) loadable without the training corpus.
 */
class FirewallTrainCommand extends Command
{
    protected $signature = 'ai-agents:firewall:train
        {corpus : Path to the labelled corpus (.jsonl or .csv)}
        {--test= : Optional held-out corpus for honest metrics (reported as a sanity check)}
        {--source= : Dataset adapter name (resources/firewall/datasets/{source}.php) for public datasets with custom schemas}
        {--out= : Output artifact path (default: package model)}
        {--dimensions=4096 : Token-hashing vector size}
        {--epochs=1000 : Maximum gradient descent epochs (rubix early-stops)}
        {--rate=0.01 : Optimizer learning rate}
        {--batch=128 : Mini-batch size (full-batch for deterministic seed training)}';

    protected $description = 'Train the prompt firewall classifier (rubix/ml) and write its model artifact';

    /**
     * Execute the command: load a labelled corpus (optionally through a
     * dataset adapter), train a rubix/ml logistic regression pipeline, and
     * write the resulting .rbx artifact.
     *
     * @return int Exit code — SUCCESS when training completes and the
     *             metrics table is printed, FAILURE on invalid arguments,
     *             missing adapter, or training errors.
     */
    public function handle(): int
    {
        $loader = new CorpusLoader;

        $corpus = $this->argument('corpus');

        if (! is_string($corpus)) {
            $this->error('The corpus argument must be a path string.');

            return self::FAILURE;
        }

        // Dataset adapter: public datasets ship arbitrary column schemas;
        // the adapter maps them onto canonical {text, label} rows.
        $source = $this->option('source');

        if (is_string($source) && $source !== '') {
            try {
                $train = $this->loadWithAdapter($corpus, $source);
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        } else {
            try {
                $train = $loader->load($corpus);
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        $testPath = $this->option('test');

        if (is_string($testPath) && $testPath !== '') {
            try {
                $test = is_string($source) && $source !== ''
                    ? $this->loadWithAdapter($testPath, $source)
                    : $loader->load($testPath);
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            if ($test['rows'] !== []) {
                $this->warn('The --test corpus is informational only: rubix reports in-sample F-beta. Evaluate with ai-agents:firewall:evaluate.');
            }
        }

        $out = $this->option('out');
        $path = is_string($out) && $out !== '' ? $out : dirname(__DIR__, 3).'/resources/firewall/model/prompt-injection-v1.rbx';

        $dimensions = (int) $this->option('dimensions');
        $epochs = (int) $this->option('epochs');
        $rate = (float) $this->option('rate');
        $batch = (int) $this->option('batch');

        if ($dimensions < 1 || $epochs < 1 || $rate <= 0.0 || $batch < 1) {
            $this->error('The --dimensions, --epochs, --rate and --batch options must be valid positive values.');

            return self::FAILURE;
        }

        try {
            $metrics = HashedTextClassifier::train(
                $train['rows'],
                $path,
                $dimensions,
                $epochs,
                $rate,
                $batch,
            );
        } catch (\Throwable $exception) {
            $this->error('Training failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Trained on '.count($train['rows']).' rows ('.$train['skipped'].' skipped).');

        if (isset($test) && $test['rows'] !== []) {
            $this->line('Held-out corpus supplied: '.count($test['rows']).' rows ('.$test['skipped'].' skipped) — evaluate it with ai-agents:firewall:evaluate.');
        }

        $this->table(
            ['f1 (in-sample)', 'rows', 'dimensions', 'artifact'],
            [[
                $metrics['f1'],
                $metrics['samples'],
                $dimensions,
                $path,
            ]],
        );

        return self::SUCCESS;
    }

    /**
     * Load a dataset through its named adapter configuration.
     *
     * @param  string  $path  Absolute or relative path to the JSONL/CSV
     *                        dataset file to be loaded.
     * @param  string  $source  Adapter name that resolves to a PHP config
     *                          file under resources/firewall/datasets/{source}.php.
     * @return array{rows: list<array{text: string, label: float}>, skipped: int}
     *                                                                            Normalised rows ready for training and the count of rows that
     *                                                                            were discarded by the adapter (malformed, missing columns, etc.).
     */
    private function loadWithAdapter(string $path, string $source): array
    {
        $adapterFile = dirname(__DIR__, 2).'/resources/firewall/datasets/'.basename($source).'.php';

        if (! is_file($adapterFile)) {
            throw new RuntimeException("No dataset adapter named [{$source}] at [{$adapterFile}].");
        }

        /** @var mixed $config */
        $config = require $adapterFile;

        if (! is_array($config)) {
            throw new RuntimeException("The dataset adapter [{$source}] did not return a configuration array.");
        }

        return (new DatasetAdapter)->map($path, $config);
    }
}
