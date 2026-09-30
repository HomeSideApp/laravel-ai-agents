# homeside/laravel-ai-agents

Reusable Laravel AI infrastructure: agent registry, provider resolution,
hierarchical prompting with guardrails, execution recording and per-module
configuration — built on top of the official **Laravel AI SDK** (`laravel/ai`).

The package is domain-agnostic: every consuming application registers its own
Agents, Tools, Modules and Context Providers through the published
configuration.

## Requirements

- PHP `^8.2`
- Laravel `^13.0`
- `laravel/ai: ^1.0`

## Installation

```bash
composer require homeside/laravel-ai-agents
```

The package auto-registers its service provider via package discovery.
Migrations load automatically through `loadMigrationsFrom()`.

Publish the configuration (recommended) and, if you prefer owning the
migrations in your app, publish them too:

```bash
php artisan vendor:publish --tag=ai-agents-config
php artisan vendor:publish --tag=ai-agents-migrations
```

### Laravel Boost

During a new Boost installation, select skills and `homeside/laravel-ai-agents` when prompted:

```bash
php artisan boost:install
```

For an existing Boost installation, discover the newly installed package and select it:

```bash
php artisan boost:update --discover
```

Later `boost:update` runs refresh an already selected package skill.

### Development (this repository)

The package ships a minimal Docker environment (PHP 8.4 + Composer +
pdo_sqlite) so the QA suite runs identically on any host:

```bash
make build      # build the development image
make install    # composer install from composer.lock
make qa         # pint --test + phpstan + phpunit
make test       # phpunit only
make lint       # fix style issues with pint
make analyse    # phpstan only
make shell      # shell inside the container
```

Equivalent raw command: `docker compose run --rm qa <command>` from this
directory.

### Host configuration

`config/ai-agents.php` exposes:

| Key | Description |
|---|---|
| `user_model` | Class-string of the host's user model (used by relationships). |
| `users_table` | Users table name used by the package migrations. |
| `fallback_module` | Module identifier used when an agent's module has no dedicated provider. |
| `tenant` | Optional tenant support block — see [Optional tenant support](#optional-tenant-support). |
| `agents` | Array of `DomainAgent` class-strings to register. |
| `modules` | Array of `ModuleAiProvider` class-strings to register. |
| `context_providers` | Array of `ContextProvider` class-strings to register. |
| `injection_patterns` | Regex list used by `PromptCompositor` guardrails; extend or replace per host. |

## Registering Agents and Modules

The service provider walks `config('ai-agents.agents')`,
`config('ai-agents.modules')` and `config('ai-agents.context_providers')`
and registers each class in the `AgentRegistry` / `ContextBuilder`.

Example host configuration:

```php
// config/ai-agents.php
'agents' => [
    App\Ai\Agents\RecipeGeneratorAgent::class,
    App\Ai\Agents\TicketAnalyzerAgent::class,
],

'modules' => [
    App\Ai\Modules\RecipesAiModule::class,
],

'context_providers' => [
    App\Ai\Context\Providers\ProfileContextProvider::class,
],
```

Use the published stubs (`php artisan vendor:publish --tag=ai-agents-stubs`)
or the ones bundled in `stubs/Agent.php.stub`, `stubs/Module.php.stub`,
`stubs/Tool.php.stub` and `stubs/ActionProposalTool.php.stub` as templates
for the host classes.

Tools that touch host domain models (recipes, products, lists...) or that
need storage/routing — e.g. an image-generation tool backed by a host
service — live in the host application and are listed in the agent's
`tools()` method. The package ships no domain tools. When writing tools,
authorise inside `handle()` against your domain models and policies: the
model only provides inputs, never permissions.

### Agent contract

A domain agent implements the Laravel AI SDK native interfaces (`Agent`,
`HasTools`, `HasStructuredOutput`, ...) plus the package's
`HomeSide\AiAgents\Contracts\DomainAgent` interface, which declares the
metadata: `key()`, `module()`, `version()`, `requiredCapabilities()`,
`defaultConfiguration()`, `contextProviders()`, `maxContextTokens()` and
`requiredPrivacyLevel()`.

Optionally implement `HomeSide\AiAgents\Contracts\AgentMetadata` to give the
synchroniser a label, description and default parameters.

### Module contract

A module implements `HomeSide\AiAgents\Contracts\ModuleAiProvider`, declaring
its `AiProviderModule` case and the agents it needs (label, system prompt,
parameters). These defaults seed the `ai_agents` and
`module_ai_configurations` rows.

## Agent privacy gate

An agent may declare the minimum privacy level its data allows through
`DomainAgent::requiredPrivacyLevel()` (null = no restriction):

```php
public function requiredPrivacyLevel(): ?PrivacyLevel
{
    return PrivacyLevel::Local; // never send this data to cloud providers
}
```

After provider resolution and before any SDK call, `AiAgentManager` checks
the resolved provider against the requirement with
`PrivacyLevel::isAtLeast()` (local ⊇ self_hosted ⊇ cloud; `unknown`
satisfies only `unknown`). A violation aborts the run with
`HomeSide\AiAgents\Exceptions\PrivacyViolationException` before any run row
or outbound request exists.

## Run content retention

Free-text run content (`user_message`, `reply` on `ai_runs`) is filtered
through the provider's privacy level. Each level maps to a retention mode in
`config('ai-agents.logging.retention')`:

| Mode | Stored value |
|---|---|
| `full` (default) | the text as-is |
| `redacted` | `[redacted sha256:<digest> len=<n>]` — runs stay correlatable, the conversation never reaches the database |
| `none` | `NULL` |

```dotenv
AI_AGENTS_RETENTION_CLOUD=redacted
AI_AGENTS_RETENTION_UNKNOWN=none
AI_AGENTS_REDACTED_DIGEST_LENGTH=12
```

A host handling personal data typically keeps `local`/`self_hosted` at
`full` and restricts `cloud`/`unknown` to `redacted` or `none`. The
retention mode applies per run, based on the provider the resolver picked
for that execution.

## Modules are strings, not an enum

PHP enums cannot be extended, so the package treats modules as **plain
strings** everywhere: `ModuleAiProvider::module(): string`, and the
`module` columns on `ai_providers` / `ai_agents` are plain string columns.

The bundled `HomeSide\AiAgents\Enums\AiProviderModule` only enumerates the
generic cases (`General`, `Assistant`, `ImageGeneration`) as convenient
constants. Host applications declare their own domain-specific modules by
simply returning their identifier from `module()`:

```php
public function module(): string
{
    return 'recipes'; // any host-defined identifier
}
```

When an agent requests a module that has no dedicated provider, the resolver
falls back to a provider assigned to `config('ai-agents.fallback_module')`
(default `'general'`).

## Optional tenant support

Tenant support (household, team, workspace — whatever the host calls it) is
**disabled by default**: no column, no foreign key, no scoping, no cost.

### Enable via environment

```dotenv
AI_AGENTS_TENANT_ENABLED=true
AI_AGENTS_TENANT_MODEL=App\Models\Household
AI_AGENTS_TENANT_TABLE=households
AI_AGENTS_TENANT_FOREIGN_KEY=household_id
AI_AGENTS_TENANT_MEMBERS_TABLE=household_members
AI_AGENTS_TENANT_USER_COLUMN=active_household_id
```

With those six values, and without writing any host code:

- The tenant migrations add the configured column (e.g. `household_id`) to
  `ai_providers`, `ai_runs`, `ai_conversations` and
  `module_ai_configurations`.
- `ProviderResolver` gains a tenant scope: **tenant → user → system**.
- `AiExecutionContextData::fromRequest()` accepts the tenant id as second
  argument, and `ExecutionRecorder` stamps it on every run.
- `GenericTenantResolver` authorises the candidate tenant through the
  membership table and the user's active-tenant column.

### Custom resolver

Hosts with rules the generic resolver cannot express (role-based access,
nested tenants, invitations) implement the contract and point to it:

```dotenv
AI_AGENTS_TENANT_RESOLVER=App\Ai\Tenancy\MyTenantResolver
```

```php
class MyTenantResolver implements \HomeSide\AiAgents\Contracts\ResolvesTenant
{
    // enabled(), modelClass(), foreignKey(), table(),
    // resolveAccessible(), scopeQuery()
}
```

### Programmatic usage

```php
use HomeSide\AiAgents\Execution\AiExecutionContextData;

$context = new AiExecutionContextData(
    userId: auth()->id(),
    tenantId: $household->id, // optional; only meaningful when enabled
);
```

Publish the tenant migrations explicitly if you prefer owning them in the
application: `php artisan vendor:publish --tag=ai-agents-tenant-migrations`.
They load automatically from the package while
`config('ai-agents.tenant.enabled')` is true.

## Running an agent

```php
use HomeSide\AiAgents\AiAgentManager;
use HomeSide\AiAgents\Execution\AiExecutionContextData;

$context = AiExecutionContextData::fromRequest(
    userId: auth()->id(),
    conversationId: $conversation?->id,
);

$result = app(AiAgentManager::class)->run(
    agentKey: 'recipes.recipe_generator',
    context: $context,
    userMessage: 'Chicken with rice for 4 people',
);
```

The manager orchestrates: registry → configuration → provider resolution
(user scope first, then system scope, module before `General`) → dynamic
provider registration in the SDK → prompt composition → execution → run
recording (`ai_runs`, `ai_execution_attempts`, `ai_tool_calls`).

## Synchronising agents

Create/verify the `ai_agents` rows from the registered classes (idempotent):

```php
app(\HomeSide\AiAgents\Synchronizer\AgentSynchronizer::class)->sync();
```

`diagnose()` reports classes without rows and rows without classes.

## Prompt layers

`PromptCompositor` assembles the system prompt in fixed order:

1. Technical guardrail (not editable)
2. Global platform policy (`ai_global_settings.extra_prompt`)
3. Platform prompt per agent (`ai_agents.platform_prompt`, admin-edited, versioned)
4. User additional instructions (`module_ai_configurations.additional_instructions`)
5. Domain context (from `ContextBuilder` providers)
6. Execution context (locale, timezone)
7. Integrity reminder

Guardrails: layer length limits, control-character normalisation, prompt
firewall inspection, and per-layer SHA-256 hashes recorded in run metadata.

## Prompt firewall (3 layers)

Prompt content passes through a layered inspection pipeline before reaching
the model: **1. Lexicon** (multilingual regex, NFKC-normalised, plus the
always-on structural file of role delimiters and scaffolding) →
**2. Statistical scorer** (language-agnostic: entropy, script mixing,
special-token density, control chars, repetition) → **3. Trained
classifier** (opt-in logistic model over hashed n-grams). Layer signals
combine into a weighted mean; thresholds over the aggregate pick
`allow` / `flag` / `block`.

### Layer details

1. **Lexicon** (`lexicon`): loads `resources/firewall/lexicon/` —
   `en.php`, `es.php` (opt-in via `firewall.lexicon.languages`) and
   `structural.php` (role delimiters `<<SYS>>`, `[INST]`, `<|im_start|>`;
   scaffolding; template/control payloads — **always active**). The host's
   legacy `injection_patterns` config is still merged. The lexicon is a
   refinement, NOT a requirement per language: attacks in languages
   without a lexicon file are still caught by the structural file and
   layer 2.
2. **Scorer** (`scorer`): pure-PHP signals — character entropy,
   control-character ratio, script mixing (homoglyph evasion), special
   token density and n-gram repetition. Deterministic, no trained weights,
   no I/O.
3. **Classifier** (`classifier`, opt-in): logistic regression pipeline
    via [rubix/ml](https://github.com/RubixML/RubixML): multibyte
    lowercase → character n-gram token hashing → L1 normalisation →
    logistic regression (Adam optimiser, L2 penalty, early stopping).
    The trained model is serialised as a rubix `PersistentModel` artifact
    (`.rbx`). A missing or corrupt artifact degrades the layer to
    disabled — it never breaks a request.

### Configuration and toggles

```php
'firewall' => [
    'enabled'   => true,   // master switch (false binds the null inspector)
    'inspector' => null,   // class-string override implementing InspectsPrompt
    'action'    => 'flag', // flag | block | allow (flag default: no FP breaks users)

    'lexicon' => [
        'enabled' => true,
        'weight' => 0.5,
        'languages' => ['en', 'es'],
        'block_structural' => true,   // role delimiters force block
    ],
    'scorer' => [
        'enabled' => true,
        'weight' => 0.3,
    ],
    'classifier' => [
        'enabled' => false,           // opt-in until a model is reviewed
        'weight' => 0.5,
        'path' => null,               // null = embedded seed artifact
    ],

    'thresholds' => ['flag' => 0.35, 'block' => 0.80],
    'allow_block_from_score' => false, // score alone never escalates to block
],
```

Decision rules:

- score >= `thresholds.block` (or a structural match with
  `block_structural`) → `block`; score >= `thresholds.flag` → the
  configured action; otherwise allowed.
- `allow_block_from_score=false` keeps the safe stance: only the structural
  override or an explicit `action=block` can abort a run.
- `firewall.inspector` still replaces the whole pipeline for hosts with
  their own service.

Inspectors run at three points: the user message (in `AiAgentManager`),
user instructions and domain context (in `PromptCompositor`). Sanitisation
replaces matched text with `[SECURITY RESTRICTION]` using the same
patterns the detection reported (`ProvidesSanitisationPatterns`). Findings
carry the aggregated `score` and per-layer `signals` in run metadata.

### Operations

```bash
php artisan ai-agents:firewall:status     # live layer status + artifact info
```

## models.dev reference catalog

`ai-agents:models-dev:sync` mirrors the public [models.dev](https://models.dev)
catalog into the `models_dev_providers` / `models_dev_models` tables: provider
metadata (API URL, docs, env vars), model specifications (context/output
limits, modalities, capability flags), per-million-token pricing and the
providers' SVG logos (stored under
`storage/app/private/models-dev/logos/`).

```bash
php artisan ai-agents:models-dev:sync            # full sync (idempotent)
php artisan ai-agents:models-dev:sync --force    # re-download existing logos
php artisan ai-agents:models-dev:sync --skip-logos
```

The command is scheduled daily at 02:00 with overlap protection by default;
tune or disable it via the `models_dev` block of `config/ai-agents.php`
(`AI_AGENTS_MODELS_DEV_SCHEDULE_ENABLED`, `AI_AGENTS_MODELS_DEV_SCHEDULE_AT`,
...). Sync is additive: rows removed upstream are kept locally until a host
prunes them deliberately. This catalog is reference data — it is separate
from `ai_providers`, which holds host/user connection settings.

### Browsing the catalog (CatalogQuery)

Injectable read service for the host's pickers/forms — filtering and
pagination happen server-side, so the full catalog is never shipped to the
client:

```php
use HomeSide\AiAgents\ModelsDev\CatalogQuery;

$catalog = app(CatalogQuery::class);

$catalog->providers(search: 'oai', perPage: 20);          // LengthAwarePaginator

$catalog->models(
    providerSlug: 'openai',   // null = every provider
    search: 'gpt',            // LIKE over model_id/name/description/family
    toolCall: true,           // capability filters: null = don't filter
    reasoning: null,
    structuredOutput: null,
    attachments: null,
    openWeights: null,
    withModality: 'image',    // 'text'|'image'|'audio'|'video'
    maxInputCost: 5.0,        // USD / 1M input tokens; unpriced counts as free
    freeOnly: false,
    orderBy: 'cheapest_input', // 'name'|'cheapest_input'|'cheapest_output'|'newest'
    perPage: 25,
);
```

Models come with their provider eager-loaded (logo, name) and providers
include a `models_count`.

### Form prefill suggestions (CatalogPrefill)

The catalog is a **suggestion source**: given a `(provider slug, model_id)`
pair it returns the fields the host's provider form can autofill. Nothing
is persisted here — the host's controller submits the (user-edited) values
to `AiProvider::createValidated()`, which keeps every validation in one
place. Unknown pairs return `null` (empty form, not an error).

```php
use HomeSide\AiAgents\ModelsDev\CatalogPrefill;

$prefill = app(CatalogPrefill::class)->prefill('openai', 'gpt-4o');

// [
//   'name'          => 'OpenAI',                   // suggested values
//   'type'          => 'openai',                   // valid for createValidated
//   'base_url'      => 'https://api.openai.com/v1',
//   'model'         => 'gpt-4o',
//   'privacy_level' => 'cloud',
//   'specs'         => [/* context, costs, capabilities for the UI */],
// ]
```

Never includes `api_key` or scope — those always come from the user /
execution context at submit time.

The `ai_providers` table mirrors the same spec columns (family, description,
capability flags, modalities, token limits, costs), so the prefill payload
maps 1:1 onto `AiProvider::createValidated()` — validated writes enforce
modality values, non-negative costs and positive token limits.

## Estimated cost tracking & consolidation

Every finished run snapshots its estimated USD cost into `ai_runs.estimated_cost`,
computed by [`CostEstimator`](src/Execution/CostEstimator.php) from the
provider's catalog-aligned pricing at run time (later price changes never
rewrite history):

```
(input − cached)/1M × cost_input + cached/1M × cost_cache_read + output/1M × cost_output
```

Runs without pricing keep `estimated_cost = null` (never a misleading zero).

### Consolidation (retention)

`ai-agents:usage:consolidate` rolls finished runs older than
`retention_days` (config `ai-agents.usage`, default 30) into `ai_usage_daily`
— one row per UTC day + provider with summed tokens/costs — and deletes the
source runs, bounding the hot table while the economical history survives.
The rollup is recomputed from source rows, so re-runs heal partial failures
instead of double-counting. Scheduled daily at 03:00 (after the models.dev
sync) with overlap protection; disable via
`AI_AGENTS_USAGE_SCHEDULE_ENABLED=false`.

### Querying spend

```php
use HomeSide\AiAgents\Execution\CostEstimator;

$estimator = app(CostEstimator::class);

$estimator->totalSpend('2026-01-01', '2026-01-31'); // USD over the range
$estimator->spendByDay();                           // 'YYYY-MM-DD' => USD, chart-ready
```

Both aggregates span the live `ai_runs` and the consolidated `ai_usage_daily`
rows, so the numbers stay correct across the retention boundary.

## Content privacy (per-user encryption & support grants)

Run content (`user_message`, `reply`) is **encrypted by default** with a
per-user data key (envelope pattern): reversible for the user's own history,
unreadable from a leaked database dump, and crypto-shreddable.

### How storage is decided

Two policies compose in [`RunContentRedactor::resolveMode()`](src/Execution/RunContentRedactor.php),
and the **most restrictive always wins**:

1. **Host ceiling** — `config('ai-agents.logging.retention')` per privacy
   level (`full` / `redacted` / `none` / `encrypted`). A `none` ceiling
   stores nothing even with consent; `encrypted` never stores plaintext.
2. **User consent** — inside a permissive ceiling: consented users get
   `plain` storage, everyone else gets `encrypted`.

| Mode | Stored value |
|---|---|
| `plain` | text as-is (consented user) |
| `encrypted` (default) | `enc:v1:` + AES ciphertext with the user's DEK |
| `redacted` | `[redacted sha256:… len=…]` digest |
| `none` | NULL |

### Keys (envelope)

`ai_user_content_keys` holds one wrapped DEK per user (DEK encrypted with
the app key). The run content cast ([`RunContentCast`](src/Models/Casts/RunContentCast.php))
encrypts on write / decrypts on read driven by the row's `content_mode`,
and degrades gracefully on legacy or unreadable values — it never throws.

```php
$manager = app(UserContentKeyManager::class);
$manager->shred($userId);   // crypto-shredding: history becomes unrecoverable
```

### Support grants (B2)

The plaintext never reaches the database. A grant authorises on-the-fly
decryption for a support ticket, is owned by the run's user, time-boxed and
revocable — all through the injectable
[`ContentSharing`](src/Privacy/ContentSharing.php) service:

```php
$sharing = app(ContentSharing::class);

$sharing->grantToSupport($userId, [$runId, ...], 'SUP-42', expiresInHours: 24);
$sharing->grantConversationToSupport($userId, $conversationId, 'SUP-42');

$content = $sharing->readGranted($run, 'SUP-42');   // GrantedContent DTO
$sharing->revoke($run, $userId);
$sharing->revokeByReference('SUP-42');
$sharing->expireOverdue();                          // housekeeping

$sharing->shredUser($userId);   // honour_grants: waits while grants are active
```

Every grant and read is logged. Ownership is verified on every operation —
a user can never grant or read another user's runs.

### Configuration

```php
'privacy' => [
    'content' => [
        'default_grant_hours' => 168,   // 7 days when unspecified
        'max_grant_hours' => 720,       // cap: 30 days
        'shred_policy' => 'honour_grants',
    ],
],
```

### Training the classifier

Train on real data and promote artifacts deliberately — full provenance
rules live in [`resources/firewall/model/DATASETS.md`](resources/firewall/model/DATASETS.md).

```bash
# 1. List available dataset adapters and their status
php artisan ai-agents:firewall:datasets

# 2. (Optional) Show dataset metadata on HuggingFace without downloading
php artisan ai-agents:firewall:download neuralchemy/Prompt-injection-dataset --info

# 3. Download a dataset from HuggingFace (writes JSONL to storage/app/private/firewall-datasets/)
php artisan ai-agents:firewall:download neuralchemy/Prompt-injection-dataset

# 4. Verify/adjust the column mapping in resources/firewall/datasets/neuralchemy.php

# 5. Train (adapter maps the dataset schema) with a held-out test split
php artisan ai-agents:firewall:train \
    storage/app/private/firewall-datasets/neuralchemy.jsonl \
    --source=neuralchemy \
    --out=resources/firewall/model/prompt-injection-v2.rbx

# 6. Evaluate honestly before promoting
php artisan ai-agents:firewall:evaluate \
    resources/firewall/model/prompt-injection-v2.rbx \
    storage/app/private/firewall-datasets/neuralchemy.jsonl

# 7. Inspect the trained model artifact
php artisan ai-agents:firewall:inspect resources/firewall/model/prompt-injection-v2.rbx

# 8. Activate
AI_AGENTS_FIREWALL_CLASSIFIER=true
AI_AGENTS_FIREWALL_CLASSIFIER_PATH=resources/firewall/model/prompt-injection-v2.rbx
```

`--source` selects a column adapter (JSONL or CSV) so public datasets with
arbitrary schemas map onto canonical `{text, label}` rows. Training
parameters (`--dimensions`, `--epochs`, `--rate`, `--batch`) are exposed
for tuning on larger datasets. The regression gate:
`FalsePositiveRegressionTest` must stay green with the new artifact.
Regenerate the embedded seed model with `make train`.

### Stronger protection options

No input firewall is complete. For defence in depth:

- **PHP-native guardrails** — [`padosoft/laravel-ai-guardrails`](https://packagist.org/packages/padosoft/laravel-ai-guardrails)
  (deterministic, offline, built for `laravel/ai`) works alongside this
  package; use it directly as SDK middleware or wrap it in an
  `InspectsPrompt` adapter.
- **Network layer** — [Prompt-Injection-Firewall](https://github.com/ogulcanaydogan/Prompt-Injection-Firewall)
  (Go reverse proxy, 129 patterns + DistilBERT ML) inspects the full
  outgoing payload. Point your provider `base_url` at the proxy — no code
  changes; the dynamic providers created by `DynamicProviderRegistrar` can
  target it per provider.
- **Structural defences** — the prompt hierarchy (platform policy is
  authoritative) and server-side authorisation inside every tool remain the
  barriers that cannot be bypassed by input tricks.

## Testing providers

```php
// Stored provider
app(\HomeSide\AiAgents\Providers\AiProviderTester::class)->testProvider($provider);

// Unsaved configuration
app(\HomeSide\AiAgents\Providers\AiProviderTester::class)->testConfig([
    'type' => 'openai-compatible',
    'base_url' => 'https://api.example.com/v1',
    'api_key' => 'sk-...',
    'model' => 'gpt-4o',
]);
```

`AiProviderEndpointPolicy` validates base URLs against SSRF (blocks private
ranges and cloud metadata in `saas` mode; allows them in `self-hosted` mode).
`ImageGenerationProviderTester` probes the OpenAI-compatible
`/images/generations` endpoint for image models.

## Validated model writes

Package models ship validated write helpers so hosts do not have to
re-implement the integrity and security invariants of each table:

```php
use HomeSide\AiAgents\Models\AiProvider;

// Throws ValidationException on invalid input; SSRF-safe by construction.
// Ownership is declared via 'scope' — the package resolves it to the right
// physical columns (user_id, or the configured tenant column).
$provider = AiProvider::createValidated([
    'name' => 'OpenAI production',
    'type' => 'openai',
    'base_url' => 'https://api.openai.com/v1',
    'model' => 'gpt-4o',
    'api_key' => 'sk-...',                    // encrypted automatically
    'module' => 'assistant',
    'scope' => ['user' => $user->id],         // user-scoped
]);

AiProvider::createValidated([..., 'scope' => ['tenant' => $household->id]]); // tenant-scoped
AiProvider::createValidated([..., 'scope' => 'global']);                     // system-wide
```

What each model enforces on `createValidated()` / `updateValidated()`:

| Model | Rules + security checks |
|---|---|
| `AiProvider` | Supported driver, valid URL, **SSRF policy** (blocks cloud metadata always; private ranges per `endpoint_policy.mode`), tenant/user scope exclusivity, `module` slug format. `api_key` is encrypted into `api_key_encrypted`. |
| `AiAgent` | Unique `key` (never duplicated), `prompt_version` cannot decrease, key/module slug formats. |
| `ModuleAiConfiguration` | Unique `(tenant,) user, module, agent_name` tuple, slug formats, provider/model UUID shape. |
| `AiConversation` | `agent` must be a key registered in the `AgentRegistry`. |
| `AiActionProposal` | `type` slug format (host-defined), `payload` array, status within the lifecycle set, conversation ownership (the conversation, when supplied, must exist and belong to the proposal's user). |

Plain `create()` / `save()` are untouched: hosts that prefer to run their own
validation layer can keep using them. All validated writes collect every
error into a single `Illuminate\Validation\ValidationException` before
anything reaches the database.

## Action proposals (human-in-the-loop)

`AiActionProposal` implements the propose → review → execute pattern: agents
never mutate host data directly. A tool records a pending proposal; the user
accepts or rejects it through the host's own action, which dispatches to
domain handlers.

```php
use HomeSide\AiAgents\Models\AiActionProposal;

// Inside a tool built from stubs/ActionProposalTool.php.stub:
$proposal = AiActionProposal::createValidated([
    'user_id' => $context->userId,          // from the execution context, never the model
    'conversation_id' => $context->conversationId,
    'type' => 'add_shopping_items',          // host-defined, slug format
    'payload' => ['list_id' => '...', 'items' => [...]],
    'reason' => 'You asked to add milk.',
]);

// Host-side acceptance action (authorise, validate payload, dispatch):
$proposal->isPending() && ! $proposal->isExpired() or abort(409);
DB::transaction(function () use ($proposal) {
    // match ($proposal->type) { ... } → domain actions
    $proposal->accept();
});

// Housekeeping: mark stale pending proposals as expired (schedule daily).
AiActionProposal::expireStale();
```

Writing through proposals is a **convention, not an enforcement gate**: it is
one of three supported mutation modes, chosen per agent in the host.

| Mode | How it works | When to use |
|---|---|---|
| 1. Proposal (human-in-the-loop) | Agent creates an `AiActionProposal` via the proposal tool; the user's acceptance triggers the mutation. | Conversational agents exposed to users; anything destructive or ambiguous. |
| 2. Direct write tool | The agent's `tools()` include a write tool whose `handle()` authorises against the execution context and mutates immediately (traced in `ai_tool_calls`). | Low-risk side effects (save a generated image, a note) or deterministic background agents. |
| 3. Post-run structured output | The agent returns structured JSON; the host action validates and persists after the run (e.g. a recipe generator). | Generation pipelines where the host decides what to keep. |

Every mode requires the same discipline: authorise server-side inside the
tool/handler with the execution context (the model supplies inputs, never
permissions) and use validated writes for persistence. `expires_at` is
optional — hosts without a TTL simply omit it.

## Notes

- All timestamps are UTC; run identifiers are UUIDs.
- The package never references host domain models except through
  `config('ai-agents.user_model')`.
- Publish the migrations only if you want them inside your application;
  otherwise they load from the package.

  