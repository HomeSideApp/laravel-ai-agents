<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Classifier;

use Rubix\ML\Classifiers\LogisticRegression;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\Exceptions\RuntimeException;
use Rubix\ML\NeuralNet\Optimizers\Adam;
use Rubix\ML\PersistentModel;
use Rubix\ML\Persisters\Filesystem;
use Rubix\ML\Pipeline;
use Rubix\ML\Transformers\L1Normalizer;
use Rubix\ML\Transformers\MultibyteTextNormalizer;
use Rubix\ML\Transformers\TokenHashingVectorizer;

/**
 * The firewall's trained text classifier, backed by rubix/ml.
 *
 * The learner is a rubix Pipeline: lowercase → token hashing (feature
 * hashing trick, sparse→dense) → L1 normalise → logistic regression.
 * rubix supplies the numerics, serialisation and evaluation; this class
 * only adapts them to the firewall's {text, label} row format and hides
 * rubix's Dataset/Estimator plumbing behind two operations:
 *
 *   - train(): fit on labelled rows, report in-sample F-beta, persist
 *   - load():  restore the artifact, throwing on missing/corrupt files
 *
 * The caller (ClassifierPromptInspector) wraps load() with safe
 * degradation — a broken model must never break a host request.
 */
final class HashedTextClassifier
{
    public const NEGATIVE = 'benign';

    public const POSITIVE = 'injection';

    public const DECISION_THRESHOLD = 0.5;

    private function __construct(
        /** @var PersistentModel Trained, persisted learner (Pipeline wrapped). */
        private readonly PersistentModel $model,
    ) {}

    /**
     * Build the pipeline: multibyte lowercase → token hashing → L1 → logistic regression.
     *
     * @param  int  $dimensions  Hashing vector size D.
     * @param  int  $epochs  Maximum gradient descent epochs.
     * @param  float  $learningRate  Optimizer learning rate.
     * @param  int  $batchSize  Mini-batch size (full-batch when equal to corpus).
     */
    public static function make(
        int $dimensions = 4096,
        int $epochs = 2000,
        float $learningRate = 0.05,
        int $batchSize = 1,
    ): Pipeline {
        return new Pipeline([
            new MultibyteTextNormalizer,
            new TokenHashingVectorizer($dimensions, new CharNGramTokenizer),
            new L1Normalizer,
        ], new LogisticRegression(
            batchSize: $batchSize,
            optimizer: new Adam($learningRate),
            l2Penalty: 1e-4,
            epochs: $epochs,
            minChange: 1e-7,
            window: $epochs,
        ));
    }

    /**
     * Train on {text, label} rows and persist the artifact.
     *
     * @param  list<array{text: string, label: float}>  $rows  Rows from CorpusLoader/DatasetAdapter.
     * @return array{f1: float, samples: int} Training metrics summary.
     */
    public static function train(
        array $rows,
        string $artifactPath,
        int $dimensions = 4096,
        int $epochs = 2000,
        float $learningRate = 0.05,
        int $batchSize = 1,
    ): array {
        $samples = [];
        $labels = [];

        foreach ($rows as $row) {
            $samples[] = [$row['text']];
            $labels[] = $row['label'] === 1.0 ? self::POSITIVE : self::NEGATIVE;
        }

        $dataset = Labeled::build($samples, $labels);

        $estimator = new PersistentModel(
            self::make($dimensions, $epochs, $learningRate, $batchSize),
            new Filesystem($artifactPath),
        );
        $estimator->train($dataset);
        $estimator->save();

        /** @var list<string> $predictions */
        $predictions = $estimator->predict($dataset);

        return [
            'f1' => self::f1Score($predictions, $labels),
            'samples' => count($rows),
        ];
    }

    /**
     * Load a trained artifact.
     *
     * @throws RuntimeException When the file is missing,
     *                          corrupt or incompatible.
     */
    public static function load(string $path): self
    {
        $model = PersistentModel::load(new Filesystem($path));

        return new self($model);
    }

    /**
     * Compute F1 from rubix predict() output and ground-truth labels.
     *
     * @param  list<string>  $predicted
     * @param  list<string>  $actual
     */
    private static function f1Score(array $predicted, array $actual): float
    {
        $tp = 0;
        $fp = 0;
        $fn = 0;

        foreach ($predicted as $i => $pred) {
            $hit = $pred === self::POSITIVE;
            $truth = ($actual[$i] ?? '') === self::POSITIVE;

            if ($hit && $truth) {
                $tp++;
            } elseif ($hit) {
                $fp++;
            } elseif ($truth) {
                $fn++;
            }
        }

        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;

        return ($precision + $recall) > 0 ? 2.0 * $precision * $recall / ($precision + $recall) : 0.0;
    }

    /**
     * Injection probability for one text: 0..1.
     */
    public function probability(string $text): float
    {
        $dataset = Unlabeled::build([[$text]]);

        /** @var list<array<string, float>> $probabilities */
        $probabilities = $this->model->proba($dataset);

        $first = $probabilities[0] ?? [];

        return (float) ($first[self::POSITIVE] ?? 0.0);
    }

    /**
     * The artifact's class labels (benign / injection).
     *
     * @return list<string>
     */
    public function classes(): array
    {
        return [self::NEGATIVE, self::POSITIVE];
    }
}
