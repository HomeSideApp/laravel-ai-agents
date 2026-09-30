<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Classifier;

use Rubix\ML\Tokenizers\Tokenizer;

/**
 * Character n-gram tokenizer for the firewall classifier.
 *
 * Splits text into character-level n-grams (2–4 chars) which produce
 * denser feature vectors than word tokenisation, reducing hash collisions
 * on small training corpora.
 */
final class CharNGramTokenizer implements Tokenizer
{
    private const MIN_N = 2;

    private const MAX_N = 4;

    /**
     * Tokenize text into character n-grams.
     *
     * @return list<string>
     */
    public function tokenize(string $input): array
    {
        $tokens = [];
        $chars = preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($chars)) {
            return [];
        }

        $length = count($chars);

        for ($n = self::MIN_N; $n <= self::MAX_N; $n++) {
            for ($i = 0; $i + $n <= $length; $i++) {
                $tokens[] = implode('', array_slice($chars, $i, $n));
            }
        }

        return $tokens;
    }

    /**
     * @return list<string>
     */
    public function __serialize(): array
    {
        return [];
    }

    /** @param  array<int, mixed>  $data */
    public function __unserialize(array $data): void
    {
        // No state to restore.
    }

    public function __toString(): string
    {
        return 'CharNGram(2-4)';
    }
}
