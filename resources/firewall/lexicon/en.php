<?php

/*
|--------------------------------------------------------------------------
| English injection lexicon
|--------------------------------------------------------------------------
|
| Language-specific regex patterns matched against NFKC-normalised content.
| Entries are 'label' => 'regex' pairs (or plain regex strings); labels
| appear in findings metadata. One label may carry several regexes by
| suffixing the key ('label#2'); LexiconPromptInspector strips the suffix
| when reporting matches.
|
| Keep this file strictly linguistic: language-independent detections
| belong in structural.php.
|
*/

return [
    'override_instructions' => '/ignore\s+(all\s+)?(previous|prior|system|above)\s+(instructions|rules|prompts|directives)/i',
    'override_instructions#2' => '/disregard\s+(all\s+)?(previous|prior|system)\s+(instructions|rules|prompts)/i',
    'override_instructions#3' => '/forget\s+(all\s+)?(previous|prior|system)\s+(instructions|rules|prompts)/i',
    'role_hijack' => '/you\s+are\s+now\s+(a|an)\s+(system|developer|admin|root)/i',
    'role_hijack#2' => '/act\s+as\s+(a|an)?\s*(system|developer|admin|root)/i',
    'role_hijack#3' => '/pretend\s+(you\s+are|to\s+be)\s+(a|an)?\s*(system|developer|admin)/i',
    'prompt_leak' => '/reveal\s+(your|the)\s+(system\s+)?(prompt|instructions|rules)/i',
    'prompt_leak#2' => '/show\s+(me\s+)?(your|the)\s+(system\s+)?(prompt|instructions|rules)/i',
    'prompt_leak#3' => '/print\s+(your|the)\s+(system\s+)?(prompt|instructions)/i',
    'prompt_leak#4' => '/repeat\s+(your|the)\s+(system\s+)?(prompt|instructions)/i',
    'prompt_leak#5' => '/what\s+(is|are)\s+(your|the)\s+(system\s+)?(prompt|instructions|rules)/i',
    'privilege_escalation' => '/(enable|activate|grant)\s+(tools?|permissions?|admin\s+access|root\s+access)/i',
    'guardrail_removal' => '/(you\s+have|you\s+now\s+have)\s+(no|without)\s+(restrictions|filters|limits|guardrails)/i',
    'guardrail_removal#2' => '/developer\s+mode\s+(on|enabled|activated)/i',
    'schema_tampering' => '/(change|modify|replace)\s+(the\s+)?(schema|system\s+prompt)/i',
    'cross_tenant_access' => '/access\s+(another|other)\s+(user|users|customer|customers)(\'s|’s)?\s+(data|account|files)/i',
];
