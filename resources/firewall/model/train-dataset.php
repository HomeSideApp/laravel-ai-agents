<?php

/**
 * Trains the firewall classifier on a public dataset (development only).
 *
 *   php resources/firewall/model/train-dataset.php storage/app/private/firewall-datasets/<file>.jsonl
 *
 * The downloaded dataset supplies real attack diversity, which the hand-written
 * seed corpus cannot. It is not enough on its own: trained alone, its own benign
 * class is dominated by English wiki and jailbreak-game text, so ordinary
 * Spanish app messages — "agregá 2 kg de asado" — score like attacks and every
 * user gets flagged. The domain rows are therefore repeated as hard negatives,
 * which is what pulled the worst benign score from 0.734 back to 0.580 while
 * the held-out f1 rose from 0.866 to 0.908.
 *
 * Raw dataset files are never committed (see DATASETS.md); regenerate the
 * artifact by downloading the source again.
 */

declare(strict_types=1);

require __DIR__.'/../../../vendor/autoload.php';

use HomeSide\AiAgents\Security\Classifier\HashedTextClassifier;

$dataset = $argv[1] ?? null;

if ($dataset === null || ! is_file($dataset)) {
    fwrite(STDERR, "Usage: php train-dataset.php <dataset.jsonl>\n");
    exit(1);
}

const DOMAIN_REPEAT = 25;
const DIMENSIONS = 512;
const EPOCHS = 60;
const BATCH = 512;
const RATE = 0.1;

$rows = [];

foreach (file($dataset, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $row = json_decode($line, true);

    if (! is_array($row) || ! isset($row['text'], $row['label']) || ! is_string($row['text'])) {
        continue;
    }

    $rows[] = ['text' => $row['text'], 'label' => ((int) $row['label']) === 1 ? 1.0 : 0.0];
}

$fromDataset = count($rows);

$corpus = require __DIR__.'/corpus.php';

foreach (['benign' => 0.0, 'injections' => 1.0] as $key => $label) {
    foreach ($corpus[$key] as $text) {
        for ($i = 0; $i < DOMAIN_REPEAT; $i++) {
            $rows[] = ['text' => $text, 'label' => $label];
        }
    }
}

$out = __DIR__.'/prompt-injection-v1.rbx';

$metrics = HashedTextClassifier::train($rows, $out, DIMENSIONS, EPOCHS, RATE, BATCH);

echo "Wrote {$out}\n";
echo "rows: {$metrics['samples']} (dataset {$fromDataset} + domain)\n";
echo 'f1 (in-sample): '.$metrics['f1']."\n";
