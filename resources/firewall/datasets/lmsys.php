<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Dataset adapter: lmsys/toxic-chat
|--------------------------------------------------------------------------
|
| Real user chats annotated for toxicity/jailbreak (Hugging Face). Mostly
| benign traffic — valuable for cutting false positives. Verify columns
| against the download and record the result in DATASETS.md.
|
| Download (outside runtime):
|   huggingface-cli download lmsys/toxic-chat \
|       --repo-type dataset --local-dir storage/ai-firewall/datasets/lmsys
|
*/

return [
    'text' => 'user_input',
    'label' => 'jailbreak',
    'positive' => ['1', 'true'],
    'negative' => ['0', 'false'],
    'max_length' => 4000,
];
