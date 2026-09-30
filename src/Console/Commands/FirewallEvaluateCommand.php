<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Console\Commands;

use HomeSide\AiAgents\Security\Classifier\HashedTextClassifier;
use HomeSide\AiAgents\Security\CorpusLoader;
use Illuminate\Console\Command;

/**
 * Evaluates a trained classifier artifact against a labelled corpus.
 *
 *   php artisan ai-agents:firewall:evaluate model.rbx corpus.jsonl
 *
 * Reports precision / recall / F1 plus a per-class confusion matrix so a
 * host can gate model upgrades on honest held-out numbers.
 */
class FirewallEvaluateCommand extends Command
{
    protected $signature = 'ai-agents:firewall:evaluate
        {model : Path to the model artifact (.rbx, produced by firewall:train)}
        {corpus : Path to the labelled evaluation corpus (.jsonl or .csv)}';

    protected $description = 'Evaluate the prompt firewall classifier against a labelled corpus';

    /**
     * Execute the command: load a trained .rbx artifact and evaluate it
     * against a labelled corpus, reporting precision, recall, F1, and the
     * confusion matrix.
     *
     * @return int Exit code — SUCCESS when the evaluation table is printed,
     *             FAILURE when the model or corpus cannot be loaded.
     */
    public function handle(): int
    {
        $modelPath = $this->argument('model');
        $corpusPath = $this->argument('corpus');

        if (! is_string($modelPath) || ! is_string($corpusPath)) {
            $this->error('The model and corpus arguments must be path strings.');

            return self::FAILURE;
        }

        try {
            $classifier = HashedTextClassifier::load($modelPath);
        } catch (\Throwable $exception) {
            $this->error('The model artifact could not be loaded: '.$exception->getMessage());

            return self::FAILURE;
        }

        try {
            $corpus = (new CorpusLoader)->load($corpusPath);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $truePositive = 0;
        $falsePositive = 0;
        $trueNegative = 0;
        $falseNegative = 0;

        foreach ($corpus['rows'] as $row) {
            $prediction = $classifier->probability($row['text']) >= HashedTextClassifier::DECISION_THRESHOLD;
            $actual = (float) $row['label'] === 1.0;

            if ($prediction && $actual) {
                $truePositive++;
            } elseif ($prediction) {
                $falsePositive++;
            } elseif ($actual) {
                $falseNegative++;
            } else {
                $trueNegative++;
            }
        }

        $precision = ($truePositive + $falsePositive) > 0 ? $truePositive / ($truePositive + $falsePositive) : 0.0;
        $recall = ($truePositive + $falseNegative) > 0 ? $truePositive / ($truePositive + $falseNegative) : 0.0;
        $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;

        $this->line('Model: rubix/ml pipeline, classes ['.implode(', ', $classifier->classes()).']');

        $this->table(
            ['precision', 'recall', 'f1'],
            [[round($precision, 4), round($recall, 4), round($f1, 4)]],
        );

        $this->table(
            ['', 'pred=injection', 'pred=benign'],
            [
                ['actual=injection', $truePositive, $falseNegative],
                ['actual=benign', $falsePositive, $trueNegative],
            ],
        );

        $this->line('Rows evaluated: '.count($corpus['rows']).' ('.$corpus['skipped'].' skipped).');

        return self::SUCCESS;
    }
}
