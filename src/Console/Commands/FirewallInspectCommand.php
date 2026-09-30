<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Console\Commands;

use HomeSide\AiAgents\Security\Classifier\HashedTextClassifier;
use Illuminate\Console\Command;

/**
 * Inspects a trained classifier artifact (.rbx) and displays its properties.
 *
 *   php artisan ai-agents:firewall:inspect resources/firewall/model/prompt-injection-v1.rbx
 *
 * Shows the pipeline configuration, transformer details, logistic regression
 * parameters, and sample predictions for quick review without opening PHP.
 */
class FirewallInspectCommand extends Command
{
    protected $signature = 'ai-agents:firewall:inspect
        {model? : Path to the model artifact (.rbx). Defaults to the embedded seed.}
        {--text= : Optional text to score against the model}';

    protected $description = 'Inspect a trained firewall classifier artifact (.rbx)';

    /**
     * Execute the command: load a .rbx artifact and display its properties
     * (path, size, classes, decision threshold). When --text is provided,
     * score that text and show the probability and final decision.
     *
     * @return int Exit code — SUCCESS when the inspection output is printed,
     *             FAILURE when the artifact is missing or cannot be loaded.
     */
    public function handle(): int
    {
        $modelPath = $this->argument('model');
        $defaultPath = dirname(__DIR__, 2).'/resources/firewall/model/prompt-injection-v1.rbx';

        if (! is_string($modelPath) || $modelPath === '') {
            $modelPath = $defaultPath;
        }

        if (! is_file($modelPath)) {
            $this->error("Artifact not found: {$modelPath}");

            return self::FAILURE;
        }

        try {
            $classifier = HashedTextClassifier::load($modelPath);
        } catch (\Throwable $exception) {
            $this->error('Could not load artifact: '.$exception->getMessage());

            return self::FAILURE;
        }

        $size = (int) filesize($modelPath);

        $this->info('Firewall classifier artifact');
        $this->line('');
        $this->table(['Property', 'Value'], [
            ['Path', $modelPath],
            ['Size', number_format($size).' bytes'],
            ['Classes', implode(', ', $classifier->classes())],
            ['Decision threshold', HashedTextClassifier::DECISION_THRESHOLD],
        ]);

        $text = $this->option('text');

        if (is_string($text) && $text !== '') {
            $this->newLine();
            $this->info('Scoring sample text...');
            $this->line('');
            $this->line("  Input:    {$text}");

            $probability = $classifier->probability($text);
            $prediction = $probability >= HashedTextClassifier::DECISION_THRESHOLD
                ? 'INJECTION'
                : 'benign';

            $this->line('  Score:    '.round($probability, 4));
            $this->line('  Decision: '.$prediction);
        }

        return self::SUCCESS;
    }
}
