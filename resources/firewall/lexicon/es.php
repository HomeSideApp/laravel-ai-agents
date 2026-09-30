<?php

/*
|--------------------------------------------------------------------------
| Spanish injection lexicon
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
    'override_instructions' => '/ignora\s+(las?\s+)?(instrucciones|reglas?|prompts?|directrices?)\s+(anteriores|previas|de\s+sistema|de\s+arriba)/i',
    'override_instructions#2' => '/desatiende\s+(las?\s+)?(instrucciones|reglas?)\s+(anteriores|previas)/i',
    'override_instructions#3' => '/olvida\s+(todas?\s+)?(las?\s+)?(instrucciones|reglas?)\s+(anteriores|previas)/i',
    'role_hijack' => '/(eres\s+ahora|ahora\s+eres)\s+(un|una)?\s*(system|developer|admin|administrador|root)/i',
    'role_hijack#2' => '/act[úu]a\s+como\s+(un|una)?\s*(system|developer|admin|administrador|root)/i',
    'role_hijack#3' => '/finge\s+(ser|que\s+eres)\s+(un|una)?\s*(system|developer|admin|administrador)/i',
    'prompt_leak' => '/(revela|muestra|imprime|repite)\s+(tu|el)\s+(prompt|instrucciones|reglas?)\s+(de\s+sistema|del\s+sistema)?/i',
    'prompt_leak#2' => '/(cu[áa]l\s+es|qu[ée]\s+son)\s+(tu|tus|el|los)\s+(prompt|instrucciones|reglas?)\s+(de\s+sistema|del\s+sistema|iniciales)?/i',
    'privilege_escalation' => '/(habilita|activa|concede)\s+(los?\s+)?(tools?|permisos?|acceso\s+(de\s+)?(admin|root))/i',
    'guardrail_removal' => '/(no\s+tienes|sin)\s+(restricciones|filtros|l[íi]mites|limitaciones)/i',
    'guardrail_removal#2' => '/modo\s+(desarrollador|developer)\s+(activado|on|habilitado)/i',
    'schema_tampering' => '/(cambia|modifica|reemplaza)\s+(el\s+)?(schema|esquema|prompt\s+de\s+sistema)/i',
    'cross_tenant_access' => '/accede\s+a\s+(los?\s+)?datos\s+de\s+(otro|otros|otras?)\s+(usuario[s]?|cliente[s]?)/i',
];
