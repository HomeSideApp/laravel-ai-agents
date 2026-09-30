<?php

/*
|--------------------------------------------------------------------------
| Dataset adapter: deepset/prompt-injections
|--------------------------------------------------------------------------
|
| Widely cited prompt-injection dataset (Hugging Face). Verify the actual
| column names against the downloaded file before training and record the
| result in DATASETS.md.
|
| Download (outside runtime):
|   huggingface-cli download deepset/prompt-injections \
|       --repo-type dataset --local-dir storage/ai-firewall/datasets/deepset
|
*/

return [
    'text' => 'text',
    'label' => 'label',
    'positive' => ['1', 'true', 'injection', 'malicious', 'attack'],
    'negative' => ['0', 'false', 'benign', 'legitimate', 'safe'],
    'max_length' => 4000,
];
