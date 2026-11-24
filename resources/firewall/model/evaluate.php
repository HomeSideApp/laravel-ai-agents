<?php

/**
 * Evaluation harness for the firewall classifier (development only).
 *
 *   php resources/firewall/model/evaluate.php [path/to/model.rbx]
 *
 * Scores a held-out corpus that is deliberately NOT the training corpus, and
 * reports the distributions instead of a single accuracy number: what matters
 * here is the worst benign score (false positives cost users) and how much of
 * the attack set stays above it.
 */

declare(strict_types=1);

require __DIR__.'/../../../vendor/autoload.php';

use HomeSide\AiAgents\Security\Classifier\HashedTextClassifier;

$model = $argv[1] ?? __DIR__.'/prompt-injection-v1.rbx';

if (! is_file($model)) {
    fwrite(STDERR, "Model not found: {$model}\n");
    exit(1);
}

// Held-out benign prompts, drawn from what this product actually receives:
// shopping lists, recipes, expenses, household chores, in rioplatense Spanish,
// neutral Spanish and English, including sloppy typing, emoji and numbers.
$cases = require __DIR__.'/eval-cases.php';

$benign = $cases['benign'];
$attacks = $cases['attacks'];

$classifier = HashedTextClassifier::load($model);

$score = static fn (string $text): float => $classifier->probability($text);

$benignScores = [];
$attackScores = [];

echo "modelo: {$model}\n";
echo str_repeat('─', 72)."\n";

foreach ($benign as $text) {
    $p = $score($text);
    $benignScores[$text] = $p;
    printf("  benigno  %.3f  %s\n", $p, mb_substr($text, 0, 58));
}

echo str_repeat('─', 72)."\n";

foreach ($attacks as $text) {
    $p = $score($text);
    $attackScores[$text] = $p;
    printf("  ataque   %.3f  %s\n", $p, mb_substr($text, 0, 58));
}

ksort($benignScores);
$topBenign = array_slice($benignScores, -6, 6, true);
$b = array_values($benignScores);
$a = array_values($attackScores);
sort($b);
sort($a);

$pct = static function (array $sorted, float $q): float {
    $i = (int) floor($q * (count($sorted) - 1));

    return $sorted[$i];
};

$maxBenign = max($b);
$minAttack = min($a);

printf("\n%-28s %8s %8s %8s\n", 'conjunto', 'n', 'media', 'p95/max');
printf("%-28s %8d %8.3f %8.3f\n", 'benignos', count($b), array_sum($b) / count($b), $pct($b, 0.95));
printf("%-28s %8d %8.3f %8.3f\n", 'ataques', count($a), array_sum($a) / count($a), $pct($a, 0.95));

printf("\npeor benigno:  %.3f\n", $maxBenign);
printf("mejor ataque:  %.3f\n", $minAttack);
printf("separación:    %s\n", $minAttack > $maxBenign ? 'SÍ (margen '.number_format($minAttack - $maxBenign, 3).')' : 'SE SOLAPAN');

echo "\numbral  FP   TPR\n";

$best = null;

foreach ([0.30, 0.40, 0.50, 0.60, 0.65, 0.70, 0.75, 0.80, 0.85, 0.90] as $t) {
    $fp = count(array_filter($b, static fn (float $p): bool => $p >= $t));
    $tp = count(array_filter($a, static fn (float $p): bool => $p >= $t));
    $tpr = $tp / count($a);
    printf("%.3f  %2d  %5.1f%%\n", $t, $fp, $tpr * 100);

    if ($fp === 0 && ($best === null || $tpr > $best[1])) {
        $best = [$t, $tpr];
    }
}

echo "\n";

if ($best !== null) {
    printf("sin falsos positivos: umbral %.2f detecta %.1f%% de los ataques\n", $best[0], $best[1] * 100);
} else {
    echo "ningún umbral elimina los falsos positivos\n";
}

echo "\nbenignos más altos (candidatos a falso positivo):\n";

foreach (array_reverse($topBenign, true) as $text => $p) {
    printf("  %.3f  %s\n", $p, $text);
}
