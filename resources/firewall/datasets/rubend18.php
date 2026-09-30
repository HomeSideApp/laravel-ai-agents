<?php

/*
|--------------------------------------------------------------------------
| Dataset adapter: rubend18/ChatGPT-Jailbreak-Prompts
|--------------------------------------------------------------------------
|
| Historical jailbreak prompt collection (Hugging Face). Positive-only
| source: use it to reinforce the injection class, never as the sole
| benign source. Verify columns against the download and record the
| result in DATASETS.md.
|
| Download (outside runtime):
|   huggingface-cli download rubend18/ChatGPT-Jailbreak-Prompts \
|       --repo-type dataset --local-dir storage/ai-firewall/datasets/rubend18
|
*/

return [
    'text' => 'Prompt',
    'label' => 'label',
    'positive' => ['1', 'true', 'jailbreak'],
    'negative' => ['0', 'false'],
    'max_length' => 6000,
];
