<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Classifier;

use Rubix\ML\Datasets\Dataset;
use Rubix\ML\DataType;
use Rubix\ML\Estimator;
use Rubix\ML\EstimatorType;
use Rubix\ML\Learner;
use Rubix\ML\Persistable;
use Rubix\ML\PersistentModel;
use Rubix\ML\Probabilistic;
use Rubix\ML\Transformers\L1Normalizer;
use Rubix\ML\Transformers\MultibyteTextNormalizer;
use Rubix\ML\Transformers\TokenHashingVectorizer;

/**
 * Estimator that hashes text before handing it to the learner.
 *
 * rubix/ml 2.x let a `Pipeline` wrap transformers **and** an estimator; in 3.x
 * `Pipeline` only decorates transformers, so the transformation has to happen
 * on the dataset. Doing it here, inside the estimator, keeps the two things that
 * must never drift apart together inside the persisted artifact: the feature
 * space (the hashing dimensions) and the trained learner. Predicting without the
 * same transformation would still return probabilities — quietly wrong ones.
 *
 * The transformers involved are stateless, so applying them needs no fitting and
 * nothing extra has to be persisted beyond the dimensions and the learner.
 */
final class HashedTextEstimator implements Learner, Persistable, Probabilistic
{
    public function __construct(
        private readonly int $dimensions,
        private readonly CharNGramTokenizer $tokenizer,
        private readonly Learner&Estimator&Probabilistic $estimator,
    ) {}

    public function train(Dataset $dataset): void
    {
        RubixWarnings::guard(fn () => $this->estimator->train($this->transform($dataset)));
    }

    public function trained(): bool
    {
        return $this->estimator->trained();
    }

    /** @return list<string|int|float> */
    public function predict(Dataset $dataset): array
    {
        /** @var list<string|int|float> $predictions */
        $predictions = RubixWarnings::guard(fn (): array => $this->estimator->predict($this->transform($dataset)));

        return $predictions;
    }

    /**
     * @return list<array<string, float>>
     */
    public function proba(Dataset $dataset): array
    {
        /** @var list<array<string, float>> $probabilities */
        $probabilities = RubixWarnings::guard(fn (): array => $this->estimator->proba($this->transform($dataset)));

        return $probabilities;
    }

    /**
     * The artifact's revision: rubix refuses to load a model saved by another
     * version, and this wrapper has to pass the learner's own revision through.
     */
    public function revision(): string
    {
        return $this->estimator instanceof Persistable
            ? $this->estimator->revision()
            : PersistentModel::class;
    }

    public function type(): EstimatorType
    {
        return $this->estimator->type();
    }

    /** @return list<DataType> */
    public function compatibility(): array
    {
        return $this->estimator->compatibility();
    }

    /** @return array<string, mixed> */
    public function params(): array
    {
        /** @var array<string, mixed> $params */
        $params = $this->estimator->params();

        return ['dimensions' => $this->dimensions] + $params;
    }

    public function __toString(): string
    {
        return 'hashed_text('.$this->dimensions.')';
    }

    /**
     * Lowercase, hash the character n-grams into a fixed space, normalise.
     *
     * The three transformers are stateless, so they are built per call instead
     * of being fitted and persisted: the only thing the artifact has to carry is
     * the hashing size, which lives in this estimator.
     */
    private function transform(Dataset $dataset): Dataset
    {
        return $dataset
            ->apply(new MultibyteTextNormalizer)
            ->apply(new TokenHashingVectorizer($this->dimensions, $this->tokenizer))
            ->apply(new L1Normalizer)
            ->apply(new CastToFloat);
    }
}
