<?php

/** Hyperparameter sweep for the firewall classifier (development only). */
declare(strict_types=1);

require __DIR__.'/../../../vendor/autoload.php';

use HomeSide\AiAgents\Security\Classifier\HashedTextClassifier;

$cases = require __DIR__.'/eval-cases.php';
$benign = $cases['benign'];
$attacks = $cases['attacks'];

$corpus = require __DIR__.'/corpus.php';

$rows = [];

foreach ($corpus['injections'] as $t) {
    $rows[] = ['text' => $t, 'label' => 1.0];
}

foreach ($corpus['benign'] as $t) {
    $rows[] = ['text' => $t, 'label' => 0.0];
}

// dims,epochs,lr,batch — the axis under test comes from argv.
$grid = $argv[1] ?? 'dims';

$combos = match ($grid) {
    'dims' => [[128, 1500, 0.1, 1], [256, 1500, 0.1, 1], [512, 1500, 0.1, 1], [1024, 1500, 0.1, 1]],
    'lr' => [[512, 1500, 0.05, 1], [512, 1500, 0.3, 1], [512, 1500, 0.6, 1], [512, 3000, 0.3, 8]],
    'hot' => [[1024, 3000, 0.8, 16], [1024, 3000, 1.5, 16], [2048, 3000, 1.5, 16], [1024, 6000, 1.0, 32]],
    'stable' => [[512, 3000, 0.3, 16], [1024, 3000, 0.3, 16], [1024, 4000, 0.5, 16], [2048, 4000, 0.5, 16]],
    default => [[512, 1500, 0.1, 1]],
};

printf("%5s %6s %5s %6s %7s %6s %8s\n", 'dims', 'epochs', 'lr', 'batch', 'bMax', 'FP@.5', 'TPR@.5');

foreach ($combos as [$dims, $epochs, $lr, $batch]) {
    $tmp = sys_get_temp_dir()."/sw-{$dims}-{$epochs}-{$lr}-{$batch}.rbx";
    HashedTextClassifier::train($rows, $tmp, $dims, $epochs, $lr, $batch);
    $clf = HashedTextClassifier::load($tmp);

    $b = array_map(static fn (string $t): float => $clf->probability($t), $benign);
    $a = array_map(static fn (string $t): float => $clf->probability($t), $attacks);

    printf(
        "%5d %6d %5.2f %6d %7.3f %6d %7.1f%%\n",
        $dims, $epochs, $lr, $batch,
        max($b),
        count(array_filter($b, static fn (float $p): bool => $p >= 0.5)),
        count(array_filter($a, static fn (float $p): bool => $p >= 0.5)) / count($a) * 100,
    );

    @unlink($tmp);
}
