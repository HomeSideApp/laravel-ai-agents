<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Dataset adapter: xTRam1/safe-guard-prompt-injection
|--------------------------------------------------------------------------
|
| Balanced prompt-injection dataset (Hugging Face). Verify the actual
| column names against the downloaded file before training and record the
| result in DATASETS.md.
|
| Download (outside runtime):
|   huggingface-cli download xTRam1/safe-guard-prompt-injection \
|       --repo-type dataset --local-dir storage/ai-firewall/datasets/xtram1
|
*/

return [
    'text' => 'text',
    'label' => 'label',
    'positive' => ['1', 'true', 'injection', 'malicious', 'attack'],
    'negative' => ['0', 'false', 'benign', 'legitimate', 'safe'],
    'max_length' => 4000,
];
