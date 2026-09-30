<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security;

use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Security\Classifier\HashedTextClassifier;
use Illuminate\Support\Facades\Log;

/**
 * Firewall layer 3: trained prompt-injection classifier.
 *
 * Scores content through a rubix/ml pipeline (word tokenisation → token
 * hashing → L1 normalisation → logistic regression). The model artifact
 * ships with the package (versioned) or can be supplied by the host via
 * config('ai-agents.firewall.classifier.path').
 *
 * Degrades safely: a missing, unreadable or corrupt artifact disables the
 * layer (it produces no evidence) and logs once per process — it must
 * never break a host request. The layer is opt-in through config because
 * it only makes sense once a model has been trained and reviewed.
 */
final class ClassifierPromptInspector implements PromptInspectorLayer
{
    /**
     * Resolution result of the artifact for this process: null = not yet
     * attempted, false = attempted and failed (degraded), model = active.
     */
    private false|HashedTextClassifier|null $classifier = null;

    public function __construct(
        private readonly string $defaultArtifactPath,
    ) {}

    public function key(): string
    {
        return 'classifier';
    }

    public function enabled(): bool
    {
        if (! (bool) config('ai-agents.firewall.classifier.enabled', false)) {
            return false;
        }

        return $this->resolveClassifier() !== false;
    }

    public function weight(): float
    {
        $weight = (float) config('ai-agents.firewall.classifier.weight', 0.5);

        return $weight > 0 ? $weight : 0.0;
    }

    /**
     * Score the content and emit evidence when the model flags it.
     */
    public function inspect(string $layer, string $content, AiExecutionContextData $context): Signal
    {
        $classifier = $this->resolveClassifier();

        if ($classifier === false || ! $this->enabled()) {
            return Signal::none($this->key());
        }

        $probability = $classifier->probability($content);

        if ($probability <= HashedTextClassifier::DECISION_THRESHOLD) {
            return Signal::none($this->key());
        }

        return new Signal(
            $this->key(),
            Signal::clamp(($probability - HashedTextClassifier::DECISION_THRESHOLD) * 2.0),
            ['classifier_score'],
            sprintf('probability=%.3f', $probability),
        );
    }

    /**
     * Resolve (and memoise) the classifier for this process.
     *
     * Returns false when the artifact is missing or invalid, so callers
     * treat the layer as degraded rather than fatal.
     */
    private function resolveClassifier(): false|HashedTextClassifier
    {
        if ($this->classifier !== null) {
            return $this->classifier;
        }

        $configured = config('ai-agents.firewall.classifier.path');
        $path = is_string($configured) && $configured !== '' ? $configured : $this->defaultArtifactPath;

        try {
            $this->classifier = HashedTextClassifier::load($path);
        } catch (\Throwable $exception) {
            $this->classifier = false;

            Log::warning('The prompt firewall classifier is disabled: '.$exception->getMessage());
        }

        return $this->classifier;
    }
}
