<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI Agents Package Configuration
|--------------------------------------------------------------------------
|
| This file configures the homeside/laravel-ai-agents package. Publish it
| with `php artisan vendor:publish --tag=ai-agents-config` and adjust the
| values to match the host application.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Host Models
    |--------------------------------------------------------------------------
    |
    | The package references the host application's user model through this
    | class-string instead of hardcoding it. Set it to the model available
    | in this project.
    |
    */

    'user_model' => env('AI_AGENTS_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Fallback Module
    |--------------------------------------------------------------------------
    |
    | When an agent requests a module that has no dedicated provider, the
    | resolver falls back to a provider assigned to this module. Hosts may
    | use any module string; 'general' is the conventional default.
    |
    */

    'fallback_module' => env('AI_AGENTS_FALLBACK_MODULE', 'general'),

    /*
    |--------------------------------------------------------------------------
    | Automatic Agent Synchronisation
    |--------------------------------------------------------------------------
    |
    | When enabled (default), the service provider runs the AgentSynchronizer
    | on every boot: newly registered agent classes get their ai_agents row
    | immediately, so deploying an agent never requires running the sync
    | command by hand. The synchroniser is idempotent and never deletes rows;
    | boot failures (migrations pending) are swallowed and self-heal on the
    | next agent run. Disable only if you prefer explicit deployment steps
    | (e.g. running php artisan ai:sync-agents from a release pipeline).
    |
    */

    'auto_sync' => env('AI_AGENTS_AUTO_SYNC', true),

    /*
    |--------------------------------------------------------------------------
    | Optional Tenant Support
    |--------------------------------------------------------------------------
    |
    | Tenant support (household, team, workspace — whatever the host calls
    | it) is disabled by default: no column, no foreign key, no scoping.
    |
    | Enable it with AI_AGENTS_TENANT_ENABLED=true. The migrations in
    | database/migrations/tenant/ add the configured foreign key column to
    | ai_providers, ai_runs, ai_conversations and module_ai_configurations,
    | and ProviderResolver gains a tenant scope (tenant → user → system).
    |
    | A host with custom membership rules may implement
    | HomeSide\AiAgents\Contracts\ResolvesTenant and register the class-string
    | in 'resolver'; otherwise GenericTenantResolver handles everything from
    | the values below.
    |
    */

    'tenant' => [
        'enabled' => env('AI_AGENTS_TENANT_ENABLED', false),
        'resolver' => env('AI_AGENTS_TENANT_RESOLVER'),
        'model' => env('AI_AGENTS_TENANT_MODEL', 'App\\Models\\Team'),
        'table' => env('AI_AGENTS_TENANT_TABLE', 'teams'),
        'foreign_key' => env('AI_AGENTS_TENANT_FOREIGN_KEY', 'tenant_id'),
        'members_table' => env('AI_AGENTS_TENANT_MEMBERS_TABLE'),
        'members_user_key' => env('AI_AGENTS_TENANT_MEMBERS_USER_KEY', 'user_id'),
        'members_tenant_key' => env('AI_AGENTS_TENANT_MEMBERS_TENANT_KEY', 'tenant_id'),
        'user_column' => env('AI_AGENTS_TENANT_USER_COLUMN', 'active_tenant_id'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Users Table
    |--------------------------------------------------------------------------
    |
    | Table name used by the package migrations for user foreign keys.
    |
    */

    'users_table' => env('AI_AGENTS_USERS_TABLE', 'users'),

    /*
    |--------------------------------------------------------------------------
    | Run Content Retention
    |--------------------------------------------------------------------------
    |
    | Maps each provider privacy level to how the recorder stores free-text
    | run content (user_message, reply) in ai_runs:
    |
    | - full:     store the text as-is.
    | - redacted: store a sha256 digest + length (runs stay correlatable,
    |             the conversation never reaches the database).
    | - none:     store null.
    |
    | The provider resolved for the run decides which row applies. Defaults
    | keep every level at full for backwards compatibility; hosts handling
    | personal data should restrict cloud/unknown to redacted or none.
    |
    */

    'logging' => [
        'retention' => [
            'local' => env('AI_AGENTS_RETENTION_LOCAL', 'full'),
            'self_hosted' => env('AI_AGENTS_RETENTION_SELF_HOSTED', 'full'),
            'cloud' => env('AI_AGENTS_RETENTION_CLOUD', 'full'),
            'unknown' => env('AI_AGENTS_RETENTION_UNKNOWN', 'full'),
        ],
        'redacted_digest_length' => (int) env('AI_AGENTS_REDACTED_DIGEST_LENGTH', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registered Agents
    |--------------------------------------------------------------------------
    |
    | The service provider iterates this list and registers each class in the
    | AgentRegistry, keyed by the agent's `key()` method. The agent keys are
    | namespaced by module: '<module>.<agent_name>'.
    |
    | Example:
    |
    | 'agents' => [
    |     App\Ai\Agents\RecipeGeneratorAgent::class,
    |     App\Ai\Agents\TicketAnalyzerAgent::class,
    | ],
    |
    */

    'agents' => [
        // App\Ai\Agents\ExampleAgent::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Registered Modules
    |--------------------------------------------------------------------------
    |
    | The service provider iterates this list and registers each
    | ModuleAiProvider implementation with the AgentRegistry.
    |
    | Example:
    |
    | 'modules' => [
    |     App\Ai\Modules\RecipesAiModule::class,
    | ],
    |
    */

    'modules' => [
        // App\Ai\Modules\ExampleAiModule::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Registered Context Providers
    |--------------------------------------------------------------------------
    |
    | Instances are keyed by their `key()` method and consumed by agents
    | through `DomainAgent::contextProviders()`.
    |
    */

    'context_providers' => [
        // App\Ai\Context\Providers\ProfileContextProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Endpoint Policy (SSRF protection)
    |--------------------------------------------------------------------------
    |
    | Controls how AiProviderEndpointPolicy validates provider base URLs
    | before testing connections:
    |
    | - saas: blocks loopback, RFC1918, link-local and cloud metadata
    |   endpoints; HTTPS only. Use when your app runs on shared/cloud
    |   infrastructure and users can register providers.
    |
    | - self-hosted: allows private ranges (Ollama/vLLM on localhost or LAN)
    |   while still blocking cloud metadata endpoints and dangerous ports.
    |
    | The mode can still be overridden per call via validate($url, $mode).
    |
    */

    'endpoint_policy' => [
        'mode' => env('AI_AGENTS_ENDPOINT_POLICY_MODE', 'saas'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt Firewall
    |--------------------------------------------------------------------------
    |
    | Controls how inspection findings are handled before a prompt reaches
    | the model. The default inspector (RegexPromptInspector) matches the
    | 'injection_patterns' below against NFKC-normalised content.
    |
    | action:
    | - flag: record the finding in run metadata and logs (default; false
    |   positives never break users).
    | - block: throw PromptInjectionBlockedException and abort the run.
    | - log: record only, no metadata changes.
    |
    | inspector: class-string implementing InspectsPrompt to replace the
    | default (e.g. an adapter for an external firewall service). Null keeps
    | the bundled RegexPromptInspector.
    |
    */

    'firewall' => [
        'enabled' => env('AI_AGENTS_FIREWALL_ENABLED', true),
        'inspector' => env('AI_AGENTS_FIREWALL_INSPECTOR'),
        'action' => env('AI_AGENTS_FIREWALL_ACTION', 'flag'),

        // Layer 1: multilingual lexicon. The structural file is always
        // active; 'languages' selects the shipped language files to load.
        //
        // This layer is a fast-path refinement, NOT a requirement per
        // language: attacks in languages without a lexicon file are still
        // caught by the always-on structural file (role delimiters are
        // language-independent) and by layer 2's language-agnostic
        // statistical signals. To add coverage for a new language, create
        // resources/firewall/lexicon/{code}.php ('label' => 'regex' pairs)
        // and add the code here.
        'lexicon' => [
            'enabled' => env('AI_AGENTS_FIREWALL_LEXICON', true),
            'weight' => 0.5,
            'languages' => ['en', 'es'],
            'block_structural' => true,
        ],

        // Layer 2: language-agnostic statistical scorer. Per-signal weights
        // are configurable; set one to 0 to disable that signal.
        'scorer' => [
            'enabled' => env('AI_AGENTS_FIREWALL_SCORER', true),
            'weight' => 0.3,
        ],

        // Layer 3 (Phase 2 of the plan): trained classifier. Off until a
        // model artifact is present and the host opts in.
        'classifier' => [
            'enabled' => env('AI_AGENTS_FIREWALL_CLASSIFIER', false),
            'weight' => 0.5,
            'path' => null,
        ],

        // Decision thresholds over the aggregated 0..1 score.
        'thresholds' => [
            'flag' => (float) env('AI_AGENTS_FIREWALL_FLAG_AT', 0.35),
            'block' => (float) env('AI_AGENTS_FIREWALL_BLOCK_AT', 0.80),
        ],

        // Allow the aggregated score to escalate the decision to BLOCK
        // (without this, only the configured action or the structural
        // override can block).
        'allow_block_from_score' => env('AI_AGENTS_FIREWALL_BLOCK_FROM_SCORE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | models.dev Reference Catalog Sync
    |--------------------------------------------------------------------------
    |
    | The ai-agents:models-dev:sync command mirrors the public models.dev
    | catalog (https://models.dev/api.json) into the models_dev_providers /
    | models_dev_models tables: provider metadata, model specifications,
    | pricing and SVG logos.
    |
    | schedule_enabled registers the command on the Laravel scheduler at
    | schedule_at (default 02:00) with overlap protection. Set
    | AI_AGENTS_MODELS_DEV_SCHEDULE_ENABLED=false to run it manually only.
    |
    */

    'models_dev' => [
        'api_url' => env('AI_AGENTS_MODELS_DEV_API_URL', 'https://models.dev/api.json'),
        'logo_disk_path' => env('AI_AGENTS_MODELS_DEV_LOGO_PATH', 'models-dev/logos'),
        'logos_enabled' => env('AI_AGENTS_MODELS_DEV_LOGOS_ENABLED', true),
        'schedule_enabled' => env('AI_AGENTS_MODELS_DEV_SCHEDULE_ENABLED', true),
        'schedule_at' => env('AI_AGENTS_MODELS_DEV_SCHEDULE_AT', '02:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage Consolidation & Cost Tracking
    |--------------------------------------------------------------------------
    |
    | Every finished run stores an estimated_cost snapshot (USD, computed
    | with the provider's pricing at run time). The ai-agents:usage:consolidate
    | command rolls finished runs older than retention_days into the
    | ai_usage_daily table and deletes them, bounding the hot ai_runs table
    | while keeping the economical history.
    |
    | schedule_enabled registers the command on the scheduler at schedule_at
    | (default 03:00, after the models.dev sync) with overlap protection.
    |
    */

    'usage' => [
        'retention_days' => env('AI_AGENTS_USAGE_RETENTION_DAYS', 30),
        'schedule_enabled' => env('AI_AGENTS_USAGE_SCHEDULE_ENABLED', true),
        'schedule_at' => env('AI_AGENTS_USAGE_SCHEDULE_AT', '03:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Privacy (per-user encryption & support grants)
    |--------------------------------------------------------------------------
    |
    | Without consent, run content (user_message / reply) is stored encrypted
    | with a per-user data key (envelope pattern): reversible for the user's
    | own history, unreadable from a leaked database dump, and
    | crypto-shreddable by destroying the key.
    |
    | Support grants (ContentSharing) authorise on-the-fly decryption for a
    | ticket; the plaintext never reaches the database. Grants are revocable
    | and time-boxed (max_grant_hours caps any requested lifetime).
    |
    | shred_policy = honour_grants: crypto-shredding a user's key waits while
    | support grants are active; force it explicitly if you must.
    |
    */

    'privacy' => [
        'content' => [
            'default_grant_hours' => env('AI_AGENTS_CONTENT_GRANT_HOURS', 168),
            'max_grant_hours' => env('AI_AGENTS_CONTENT_MAX_GRANT_HOURS', 720),
            'shred_policy' => env('AI_AGENTS_CONTENT_SHRED_POLICY', 'honour_grants'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Host Injection Patterns
    |--------------------------------------------------------------------------
    |
    | Empty by default: the firewall's lexicon layer already ships curated
    | pattern files per language (resources/firewall/lexicon/{code}.php)
    | plus the always-on structural file, so the generic cases are covered
    | without configuration.
    |
    | Use this array ONLY for host-specific patterns the shipped lexicon
    | does not cover (domain jargon, product names abused as role markers,
    | ...). Entries are merged into the lexicon layer as host extras; they
    | must be valid preg_* regexes including delimiters and flags, either
    | plain regex strings or 'label' => 'regex' pairs.
    |
    */

    'injection_patterns' => [
        // 'my_custom_pattern' => '/host-specific\s+phrase/i',
    ],

];
