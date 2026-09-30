<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Console\Commands;

use HomeSide\AiAgents\Security\Classifier\HashedTextClassifier;
use HomeSide\AiAgents\Security\ClassifierPromptInspector;
use HomeSide\AiAgents\Security\LexiconPromptInspector;
use HomeSide\AiAgents\Security\StatisticalPromptScorer;
use Illuminate\Console\Command;

class FirewallStatusCommand extends Command
{
    protected $signature = 'ai-agents:firewall:status';

    protected $description = 'Show the prompt firewall configuration and layer status';

    /**
     * Execute the command: display the master switch, action mode, score
     * thresholds, and per-layer status (enabled / weight / notes) for the
     * three firewall layers.
     *
     * @param  ClassifierPromptInspector  $classifier  Third layer — ML-based
     *                                                 prompt classifier backed by a rubix/ml pipeline.
     * @param  LexiconPromptInspector  $lexicon  First layer — lexicon
     *                                           and regex-based pattern matching.
     * @param  StatisticalPromptScorer  $scorer  Second layer —
     *                                           statistical anomaly scoring.
     * @return int Exit code — always SUCCESS.
     */
    public function handle(
        ClassifierPromptInspector $classifier,
        LexiconPromptInspector $lexicon,
        StatisticalPromptScorer $scorer,
    ): int {
        $this->info('Master switch: '.((bool) config('ai-agents.firewall.enabled', true) ? 'enabled' : 'DISABLED'));
        $this->line('Action: '.(string) config('ai-agents.firewall.action', 'flag'));
        $this->line('Thresholds: flag='.(float) config('ai-agents.firewall.thresholds.flag', 0.35)
            .', block='.(float) config('ai-agents.firewall.thresholds.block', 0.80));
        $this->line('Block from score: '.((bool) config('ai-agents.firewall.allow_block_from_score', false) ? 'yes' : 'no'));

        $this->newLine();

        $this->table(
            ['layer', 'enabled', 'weight', 'notes'],
            [
                [
                    $lexicon->key(),
                    $lexicon->enabled() ? 'yes' : 'no',
                    $lexicon->weight(),
                    'languages: '.implode(',', $this->configuredLanguages())
                    .' (+structural, always on)',
                ],
                [
                    $scorer->key(),
                    $scorer->enabled() ? 'yes' : 'no',
                    $scorer->weight(),
                    'statistical signals',
                ],
                [
                    $classifier->key(),
                    $classifier->enabled() ? 'yes' : 'no',
                    $classifier->weight(),
                    $this->classifierNotes(),
                ],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function configuredLanguages(): array
    {
        $languages = config('ai-agents.firewall.lexicon.languages', ['en', 'es']);

        return is_array($languages) ? array_values(array_filter($languages, 'is_string')) : [];
    }

    private function classifierNotes(): string
    {
        if (! (bool) config('ai-agents.firewall.classifier.enabled', false)) {
            return 'opt-in (config classifier.enabled)';
        }

        $configured = config('ai-agents.firewall.classifier.path');
        $path = is_string($configured) && $configured !== '' ? $configured : dirname(__DIR__, 2).'/resources/firewall/model/prompt-injection-v1.rbx';

        if (! is_file($path)) {
            return "DEGRADED: artifact [{$path}] does not exist";
        }

        try {
            $classifier = HashedTextClassifier::load($path);

            return 'rubix pipeline, classes ['.implode(', ', $classifier->classes()).']';
        } catch (\Throwable $exception) {
            return 'DEGRADED: '.$exception->getMessage();
        }
    }
}
