<?php

/**
 * Seed sweep for the firewall classifier (development only).
 *
 * Training shuffles batches with PHP's global RNG. This reports how much the
 * artifact quality depends on that draw so a seed can be chosen deliberately
 * and hardcoded in generate-seed.php.
 */
declare(strict_types=1);

require __DIR__.'/../../../vendor/autoload.php';

use HomeSide\AiAgents\Security\Classifier\HashedTextClassifier;

$corpus = require __DIR__.'/corpus.php';
$cases = require __DIR__.'/eval-cases.php';

$rows = [];

foreach ($corpus['injections'] as $t) {
    $rows[] = ['text' => $t, 'label' => 1.0];
}

foreach ($corpus['benign'] as $t) {
    $rows[] = ['text' => $t, 'label' => 0.0];
}

printf("%5s %7s %6s %8s\n", 'seed', 'bMax', 'FP@.5', 'TPR@.5');

foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10] as $seed) {
    $tmp = sys_get_temp_dir()."/seed-{$seed}.rbx";
    HashedTextClassifier::train($rows, $tmp, 1024, 6000, 1.0, 32, $seed);
    $clf = HashedTextClassifier::load($tmp);

    $b = array_map(static fn (string $t): float => $clf->probability($t), $cases['benign']);
    $a = array_map(static fn (string $t): float => $clf->probability($t), $cases['attacks']);

    printf(
        "%5d %7.3f %6d %7.1f%%\n",
        $seed,
        max($b),
        count(array_filter($b, static fn (float $p): bool => $p >= 0.5)),
        count(array_filter($a, static fn (float $p): bool => $p >= 0.5)) / count($a) * 100,
    );

    @unlink($tmp);
}
