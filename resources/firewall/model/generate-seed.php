<?php

/**
 * Seed model generator for the firewall classifier (development only).
 *
 * Run from the package root (or inside the qa container):
 *   php resources/firewall/model/generate-seed.php
 *
 * Trains on the repository's multilingual seed corpus through rubix/ml
 * and writes prompt-injection-v1.rbx (a rubix PersistentModel). The seed
 * corpus mirrors the rows used by the test suite; see DATASETS.md for
 * provenance rules.
 *
 * The corpus lives in corpus.php so the sweep and the evaluation share exactly
 * the rows this generator trains on.
 */

declare(strict_types=1);

require __DIR__.'/../../../vendor/autoload.php';

use HomeSide\AiAgents\Security\Classifier\HashedTextClassifier;

$corpus = require __DIR__.'/corpus.php';

$injections = $corpus['injections'];
$benign = $corpus['benign'];

$rows = [];

foreach ($injections as $text) {
    $rows[] = ['text' => $text, 'label' => 1.0];
}

foreach ($benign as $text) {
    $rows[] = ['text' => $text, 'label' => 0.0];
}

$out = __DIR__.'/prompt-injection-v1.rbx';

// Hyperparameters come from the sweep in sweep.php: 4096 dimensions over ~150
// rows leaves every feature too sparse to learn from, and a small learning rate
// cannot move weights that L1 normalization has already shrunk to ~1/200.
$metrics = HashedTextClassifier::train($rows, $out, 1024, 6000, 1.0, 32);

echo "Wrote {$out}\n";
echo 'f1 (in-sample): '.$metrics['f1']."\n";
echo 'rows: '.$metrics['samples']."\n";
echo 'ataques: '.count($injections).' · benignos: '.count($benign)."\n";
