<?php

/*
|--------------------------------------------------------------------------
| Dataset adapter: jackhhao/jailbreak-classification
|--------------------------------------------------------------------------
|
| Jailbreak classification dataset (Hugging Face). Verify the actual
| column names against the downloaded file before training and record the
| result in DATASETS.md.
|
| Download (outside runtime):
|   huggingface-cli download jackhhao/jailbreak-classification \
|       --repo-type dataset --local-dir storage/ai-firewall/datasets/jackhhao
|
*/

return [
    'text' => 'prompt',
    'label' => 'type',
    'positive' => ['jailbreak'],
    'negative' => ['regular'],
    'max_length' => 4000,
];
