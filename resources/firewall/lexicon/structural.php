<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Structural (language-independent) lexicon
|--------------------------------------------------------------------------
|
| Detections that do not depend on the user's language: role delimiters,
| special token sequences and escape payloads. These patterns flag text
| that should never legitimately appear in user input regardless of what
| language it is written in.
|
| Entries are 'label' => 'regex' pairs; one label may carry several regexes
| by suffixing the key ('label#2').
|
| This file is always active: PromptFirewallPipeline loads it regardless of
| the configured 'languages' list, because role delimiters are the strongest
| structural injection evidence available.
|
*/

return [
    // ChatML / Llama / Mistral / Alpaca style role delimiters.
    'role_delimiter' => '/<\|im_start\|>/i',
    'role_delimiter#2' => '/<\|im_end\|>/i',
    'role_delimiter#3' => '/<\|(system|user|assistant|endoftext)\|>/i',
    'role_delimiter#4' => '/<<\s*SYS\s*>>/i',
    'role_delimiter#5' => '/<<\s*\/SYS\s*>>/i',
    'role_delimiter#6' => '/\[\/?INST\]/i',
    'role_delimiter#7' => '/\[\/?system\]/i',

    // Markdown prompt scaffolding that mirrors provider templates.
    'scaffolding' => '/^#{1,3}\s*(system\s+prompt|instructions)\s*$/im',
    'scaffolding#2' => '/^BEGIN\s+SYSTEM\s+PROMPT/im',
    'scaffolding#3' => '/^END\s+SYSTEM\s+PROMPT/im',
    'scaffolding#4' => '/^###\s*Instruction\s*:/im',
    'scaffolding#5' => '/^<\s*instruction\s*>/im',

    // Template / code injection payloads.
    'template_injection' => '/\{\{.*?\}\}/s',
    'template_injection#2' => '/\$\{.*?\}/s',
    'template_injection#3' => '/<script\b/i',

    // Escape / control payloads (null bytes, long control runs).
    'control_payload' => '/[\x00-\x08\x0B\x0C\x0E-\x1F]{2,}/',
];
