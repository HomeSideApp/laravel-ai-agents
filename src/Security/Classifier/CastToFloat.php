<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Classifier;

use Rubix\ML\DataType;
use Rubix\ML\Transformers\Transformer;

/**
 * Casts every feature to float.
 *
 * The hashing vectorizer leaves each cell as the integer zero or a float, so the
 * dataset reports a mix of categorical and continuous columns. rubix 3's logistic
 * regression accepts continuous samples only and refuses the dataset outright.
 * Making the whole row float is what the learner expects; the values do not
 * change, only how rubix reads their type.
 */
final class CastToFloat implements Transformer
{
    /** @return list<DataType> */
    public function compatibility(): array
    {
        return [DataType::continuous(), DataType::categorical()];
    }

    /**
     * @param  list<list<mixed>>  $samples
     */
    public function transform(array &$samples): void
    {
        foreach ($samples as &$sample) {
            foreach ($sample as $column => $value) {
                $sample[$column] = (float) $value;
            }
        }
    }

    public function __toString(): string
    {
        return 'cast_to_float';
    }
}
