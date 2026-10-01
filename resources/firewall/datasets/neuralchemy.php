<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Dataset adapter: neuralchemy/Prompt-injection-dataset
|--------------------------------------------------------------------------
|
| Host-proposed primary dataset (Hugging Face). Column names and label
| vocabulary below are the EXPECTED schema and MUST be verified against
| the actual download before training (task Fase 2.1 of the firewall
| plan). Adjust the mappings after inspection; record the result in
| resources/firewall/model/DATASETS.md.
|
| Download (outside runtime):
|   huggingface-cli download neuralchemy/Prompt-injection-dataset \
|       --repo-type dataset --local-dir storage/ai-firewall/datasets/neuralchemy
|
*/

return [
    'text' => 'text',
    'label' => 'label',
    'positive' => ['1', 'true', 'injection', 'malicious', 'attack'],
    'negative' => ['0', 'false', 'benign', 'legitimate', 'safe'],
    'max_length' => 4000,
];
