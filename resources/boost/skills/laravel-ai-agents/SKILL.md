---
name: laravel-ai-agents
description: Develop and integrate homeside/laravel-ai-agents features — domain agents, modules, Agent Skills (HasSkills/LoadSkill), context providers, provider resolution, per-capability model defaults (ai_provider_model_defaults, ProviderModelResolver, ProviderModelDefaults), generic embeddings infrastructure (EmbeddingManager, capability-gated model selection, privacy-preserving resolution), provider (built-in) tools and model capabilities, the full SDK driver set (Cohere, TypeSafe, Azure, Groq...), persistent conversation memory (RemembersConversations, ConversationAccessGuard, PackageConversationStore, encrypted transcripts), privacy gates (requiredPrivacyLevel, PrivacyLevel), encrypted per-user run content (content modes, crypto-shredding, support grants), validated model writes (createValidated/updateValidated), SSRF endpoint policy, provider model capabilities and privacy fallback policy, the layered prompt firewall with classifier training commands, models.dev catalog sync, daily usage and cost consolidation, human-in-the-loop action proposals and their bridge with SDK tool approvals, optional tenancy, and execution recording. Use when a Laravel application contains homeside/laravel-ai-agents, code in the HomeSide\AiAgents namespace, or config/ai-agents.php, or when creating or modifying AI agents, tools, skills, modules, conversation memory, per-capability model defaults, embeddings, or provider setup built on it.
license: MIT
metadata:
  author: HomeSide
---

# Laravel AI Agents Development

This package adds application-level registration, configuration, provider selection, privacy gates, encrypted run content, prompt guardrails, usage accounting, and execution recording on top of Laravel's official AI SDK.

## When NOT to use

Do not use this skill for agents, tools, or prompting built directly on `laravel/ai` without this package — use the Laravel AI SDK documentation instead. Do not edit files under `vendor/`; publish configuration or stubs, or create host application classes.

## Before changing code

- Confirm the installed versions with `composer show homeside/laravel-ai-agents` and `composer show laravel/ai`.
- Inspect the published `config/ai-agents.php`, existing classes under `app/Ai`, and the package contracts for the installed version.
- Follow the host application's existing structure and conventions. The paths below are conventional, not mandatory.
- Use the Laravel AI SDK documentation for SDK-native agent, tool, structured-output, attachment, and fake APIs. Do not guess those APIs from this package.

## Installation and Boost discovery

The package service provider is auto-discovered and its migrations are loaded automatically. Publish the configuration when the host needs to customize it, optionally publish the stubs, and then migrate:

```bash
php artisan vendor:publish --tag=ai-agents-config
php artisan vendor:publish --tag=ai-agents-stubs
php artisan migrate
```

Publish `ai-agents-migrations` only when the host deliberately wants to own the package migrations. Do not keep both published and package-loaded copies of the same migration. Tenant migrations have their own tag (`ai-agents-tenant-migrations`) and only load while `config('ai-agents.tenant.enabled')` is true. The package migrations include the per-user content-key table (`ai_user_content_keys`), the models.dev reference tables, and `ai_usage_daily`.

## Application structure

A typical host application keeps package integrations in these locations:

```text
app/Ai/
├── Agents/
├── Context/Providers/
├── Modules/
└── Tools/
```

Register host classes in `config/ai-agents.php`:

```php
'agents' => [
    App\Ai\Agents\RecipeGeneratorAgent::class,
],

'modules' => [
    App\Ai\Modules\RecipesAiModule::class,
],

'context_providers' => [
    App\Ai\Context\Providers\RecipeContextProvider::class,
],
```

The service provider validates these contracts during boot. Keep each agent key unique and module-qualified, such as `recipes.recipe_generator`, and make its `module()` value match the key prefix. Module identifiers are host-defined strings; do not add package enum cases for application modules.

## Agent Skills compatibility

The package requires `laravel/ai: ^1.1` (Agent Skills, provider-tool capabilities, the expanded driver set and native tool approvals). When combining approvals with the package's Action Proposals, the SDK is only the pause/resume transport: authorisation, auditing and expiry always run through `AuthorizesProposalDecisions` via `ProposalDecisions`, and `HomeSide\AiAgents\Proposals\SdkApprovalBridge` converts pending approvals into durable proposals and the decided proposals back into SDK decisions. Enable the integration under `proposals.sdk_approvals`; MCP-based tools additionally need `laravel/mcp: ^1.0`.

## Creating a domain agent

A runnable class implements both the package's `DomainAgent` contract and the Laravel AI SDK's `Agent` contract. Implement additional SDK contracts such as `HasTools` or `HasStructuredOutput` only when the agent needs them. `DomainAgent` requires every method shown below, including `requiredPrivacyLevel()`.

When the agent must receive the package's composed platform prompt, implement `AcceptsRuntimeInstructions` and use `UsesRuntimeInstructions`. Without that contract, `AiAgentManager` cannot replace the class's static instructions at execution time.

When the agent must adapt to the resolved provider's model — token budgets for reasoning models, tool-call wire formats, provider-specific options — implement `AcceptsProviderCapabilities`. `AiAgentManager` calls `setProviderCapabilities()` with the provider's `ModelCapabilities` right before the prompt.

```php
<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use HomeSide\AiAgents\Concerns\UsesRuntimeInstructions;
use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Contracts\AcceptsRuntimeInstructions;
use HomeSide\AiAgents\Contracts\DomainAgent;
use HomeSide\AiAgents\Enums\PrivacyLevel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

final class RecipeGeneratorAgent implements AcceptsRuntimeInstructions, Agent, DomainAgent
{
    use Promptable;
    use UsesRuntimeInstructions;

    public function key(): string
    {
        return 'recipes.recipe_generator';
    }

    public function module(): string
    {
        return 'recipes';
    }

    public function version(): int
    {
        return 1;
    }

    public function requiredCapabilities(): array
    {
        return [Capability::Text];
    }

    public function defaultConfiguration(): array
    {
        return [
            'temperature' => 0.5,
            'max_tokens' => 2048,
            'timeout' => 90,
        ];
    }

    public function contextProviders(): array
    {
        return ['recipes'];
    }

    public function maxContextTokens(): int
    {
        return 8000;
    }

    public function requiredPrivacyLevel(): ?PrivacyLevel
    {
        return null; // or e.g. PrivacyLevel::Local to forbid cloud providers
    }

    public function instructions(): string
    {
        return $this->runtimeInstructionsOr(
            'Generate recipes that satisfy the requested constraints.',
        );
    }
}
```

Use `AgentMetadata` when the class should seed an explicit label, description, or default parameters during synchronization. Database edits made later by administrators take precedence over those seed values.

If tools need the authenticated execution identity, implement `AcceptsExecutionContext`. Build tools only after `setExecutionContext()` has received the current `AiExecutionContextData`, and authorize every tool operation server-side. Model-supplied arguments are input, never proof of permission.

## Privacy gate, content modes, and encryption

After provider resolution and before any SDK call, `AiAgentManager` compares the resolved provider's privacy level against `requiredPrivacyLevel()` with `PrivacyLevel::isAtLeast()`. The semantic order is `local` ⊇ `self_hosted` ⊇ `cloud`; `unknown` satisfies only an `unknown` requirement. A violation throws `HomeSide\AiAgents\Exceptions\PrivacyViolationException` before any run row or outbound request exists.

Free-text run content (`user_message`, `reply` on `ai_runs`) is stored under a `content_mode` value (`ContentMode`) resolved from two composed policies; the most restrictive always wins:

1. **Host ceiling** — `config('ai-agents.logging.retention')` maps each privacy level to `full`, `encrypted`, `redacted`, or `none`. `encrypted` as a ceiling means "never store plaintext at this level, consent or not".
2. **User consent** — `ExecutionRecorder::startRun(..., userConsented:)`. Under a permissive ceiling, consent selects `plain`; without consent the default is `encrypted`. `AiAgentManager` records runs without consent, so manager runs land `encrypted` unless the ceiling is stricter.

| Mode | Stored value |
|---|---|
| `plain` | the text as-is (consented) |
| `encrypted` (default) | envelope ciphertext; `AiRun` attribute reads decrypt transparently via `RunContentCast` |
| `redacted` | `[redacted sha256:<digest> len=<n>]` — runs stay correlatable |
| `none` | `NULL` |

```dotenv
AI_AGENTS_RETENTION_CLOUD=encrypted
AI_AGENTS_RETENTION_UNKNOWN=none
AI_AGENTS_REDACTED_DIGEST_LENGTH=12
```

Legacy rows with a `NULL` mode are treated as `plain`, so upgrading never breaks existing history.

### Crypto-shredding

Each user owns a random 256-bit data-encryption key (DEK) in `ai_user_content_keys`, wrapped with the application key and managed by `HomeSide\AiAgents\Privacy\UserContentKeyManager`. A leaked database dump is unreadable, while jobs and schedulers keep working because decryption needs the app. `shred($userId)` irreversibly destroys the DEK (`shredded_at` marker; the wrapped value is overwritten with random bytes), invalidating every ciphertext of that user without touching run rows — later reads throw `RuntimeException`. `isShredded()` reports state. With `config('ai-agents.privacy.content.shred_policy')` = `honour_grants` (default), shredding waits while support grants are active; force it explicitly when you must not.

### Support grants

`HomeSide\AiAgents\Privacy\ContentSharing` grants time-boxed, ticket-referenced support access without ever persisting plaintext: `grantToSupport()` / `grantConversationToSupport()` verify ownership on every operation — a user can never grant another user's runs — `readGranted($run, $reference)` decrypts on the fly and logs the read, and `revoke()` / `revokeByReference()` clear grants. Lifetimes default to `privacy.content.default_grant_hours` (168) and are capped by `privacy.content.max_grant_hours` (720). Check current state with `AiRun::hasActiveSupportGrant()`.

## Agent Skills

Agent Skills are reusable instruction bundles (a directory with `SKILL.md` plus optional files) the model loads on demand. The SDK injects a `LoadSkill` tool automatically when an agent implements `Laravel\Ai\Contracts\HasSkills`. This package wraps that with a registry so skills are discovered, validated and firewall-inspected once:

```php
// config/ai-agents.php
'skills' => [
    'enabled' => env('AI_AGENTS_SKILLS_ENABLED', true),
    'directories' => [resource_path('skills')],   // scanned for '<name>/SKILL.md'
    'providers' => [App\Ai\Skills\ApplicationSkills::class], // ProvidesSkills implementations
    'firewall' => env('AI_AGENTS_SKILLS_FIREWALL', true),
],
```

An agent exposes skills by implementing `HasSkills` and using `UsesAgentSkills`; override `skills()` with `skillsNamed([...])` to restrict the agent to a subset:

```php
use HomeSide\AiAgents\Concerns\UsesAgentSkills;
use Laravel\Ai\Contracts\HasSkills;

final class RecipeGeneratorAgent implements HasSkills, /* ... */
{
    use UsesAgentSkills;
}
```

`ProvidesSkills` implementations (registered in `skills.providers`) supply skills programmatically — e.g. from a database — and override same-named directory skills. When `skills.firewall` is true, each skill's instructions pass through the bound `InspectsPrompt`; a blocked skill is dropped with a warning instead of taking the application down. Inspect the effective set with `php artisan ai-agents:skills:sync`.

## Context providers and tools

Implement `HomeSide\AiAgents\Context\ContextProvider` for small, bounded facts that should be included in every relevant prompt. Its `key()` must match a value returned by the agent's `contextProviders()` method.

Use an SDK Tool instead when data is large, optional, expensive, or should be queried on demand. Scope provider queries and tool operations to the authenticated user and accessible tenant. Do not place bulk model data or secrets into prompt context.

### Action proposals (human-in-the-loop)

Use a proposal when an agent must **not** mutate host data directly. Its tool records a pending `AiActionProposal` instead of acting; a human decides; a registered handler executes. Prefer a direct write tool only for low-risk, deterministic side effects (see the README's mutation-mode table).

Extend the published `ActionProposalTool` stub (tag `ai-agents-stubs`): the tool requires an `AiExecutionContextData` — an empty context is rejected, so a proposal is never created without a user identity — and reads the user, conversation and `runId` from it, never from the model.

**Create** — `createValidated()` (no source/run) or `createValidatedWithSource($attributes, $source, $aiRunId)` to link a morph `source` and the generating AI run:

```php
$proposal = AiActionProposal::createValidatedWithSource(
    attributes: [
        'user_id' => $context->userId,
        'conversation_id' => $context->conversationId,
        'type' => 'add_shopping_items',   // ^[a-z0-9_]+$ — host slug, never an enum
        'payload' => ['list_id' => '...', 'items' => [...]],
        'reason' => 'You asked to add milk.',
        'expires_at' => now()->addDay(),  // optional TTL
    ],
    source: $workflowNodeRun,             // nullable morph source
    aiRunId: $context->runId,             // nullable AiRun UUID → ai_run_id
);
```

**Decide** — always go through `ProposalDecisions::decide()` (atomic, authorised):

```php
use HomeSide\AiAgents\Proposals\ProposalDecisions;

$decided = ProposalDecisions::instance()->decide(
    proposal: $proposal,
    userId: (string) $request->user()->id,
    decision: ProposalDecisions::DECISION_ACCEPT, // or DECISION_REJECT
    payload: $amendedPayload,                      // accept only, nullable
    note: 'Adjusted quantity',                      // nullable
);
```

Signature: `decide(AiActionProposal $proposal, int|string $userId, string $decision, ?array $payload = null, ?string $note = null): bool`. It returns `false` (no exception) when the proposal is not `pending`, is already decided, or has expired. It throws `InvalidArgumentException` (bad decision), `AuthorizationException` (not allowed) or `ValidationException` (bad amended payload). **Do not call `$proposal->accept()` / `reject()` from host code** — the no-`$deciderId` forms are deprecated; the model methods are low-level primitives that skip handler validation.

**Authorize** — the default is owner-only (`OwnerOnlyProposalAuthorizer`). Implement `HomeSide\AiAgents\Contracts\AuthorizesProposalDecisions` (`canDecide()`, `applyDecisionScope()`) and set `proposals.authorizer`:

```php
'proposals' => [
    'authorizer' => App\Ai\WorkflowApprovalAuthorizer::class, // e.g. permission "workflow-approvals.decide"
    'handlers' => [App\Ai\Proposals\AddShoppingItemsHandler::class],
    'execute' => env('AI_AGENTS_PROPOSALS_EXECUTE', 'queue'), // queue|sync|none
    'expire_schedule_enabled' => env('AI_AGENTS_PROPOSALS_EXPIRE_SCHEDULE', true),
    'expire_every' => env('AI_AGENTS_PROPOSALS_EXPIRE_EVERY', 'everyFiveMinutes'),
],
```

List what a user can decide with `AiActionProposal::query()->awaitingDecisionBy($userId)` (pending + the authorizer's scope).

**Handle** — implement `HomeSide\AiAgents\Contracts\ProposalHandler` (`type()`, `rules()`, `execute()`) and register the class-string in `proposals.handlers` (same mechanic as `agents`/`modules`; duplicate `type` values are rejected). `rules()` use dot-notation (`'payload.items' => 'required|array'`). With a registered handler and `execute` = `queue`/`sync`, `ExecuteActionProposalJob` performs the idempotent `accepted → executing → executed|failed` transition and writes `execution_result` / `execution_error` / `executed_at`. With `execute` = `none` or no handler, the proposal stays `accepted`.

**Close loops** — the six `ActionProposal*` events fire exactly once per transition. A host listener can complete a workflow node when `$event->proposal->source_type === 'workflow_node_run'` (accept → `approved`, reject → `rejected`, expire → `expired`).

**Expire** — `AiActionProposal::expireStale()` (row by row + `ActionProposalExpired`) runs via `php artisan ai-agents:proposals:expire`, scheduled every 5 minutes when `expire_schedule_enabled`. In `database` mode the command iterates tenants through `RunsForEachTenant` and the execution job carries `tenant.current_key`.

**Checklist — to do X, use Y**

- Record a proposal → the `ActionProposalTool` stub / `AiActionProposal::createValidatedWithSource()`.
- Link a proposal to its origin/run → the `$source` and `$aiRunId` arguments (`source_type`/`source_id`, `ai_run_id`).
- Approve or reject a proposal → `ProposalDecisions::decide()` (never `accept()`/`reject()` directly).
- Show a user their decidable proposals → `awaitingDecisionBy($userId)`.
- Let non-owners or workflow approvers decide → a custom `AuthorizesProposalDecisions` in `proposals.authorizer`.
- Execute an approved action → a `ProposalHandler` in `proposals.handlers` + `execute` = `queue`/`sync`.
- React to a decision/execution → the `ActionProposal*` events.
- Retire stale proposals → `expireStale()` / `ai-agents:proposals:expire` (`expire_every`).

## Modules and synchronization

Implement `ModuleAiProvider` when a host module owns a set of agent defaults. Keep the same module string across the module class, agent keys, provider records, and module configuration records. Modules are plain strings: unknown module strings fall back to `config('ai-agents.fallback_module')` during provider resolution.

While `config('ai-agents.auto_sync')` is true (the default), the service provider runs the `AgentSynchronizer` on every boot, so a deployed agent gets its `ai_agents` row without manual steps, and boot failures (pending migrations) are swallowed and self-heal on the next run. Disable it only to make synchronization an explicit deploy step (`php artisan ai:sync-agents` or the API below), and then synchronize before executing a new agent:

```php
use HomeSide\AiAgents\Synchronizer\AgentSynchronizer;

$report = app(AgentSynchronizer::class)->sync();
$diagnosis = app(AgentSynchronizer::class)->diagnose();
```

Synchronization is idempotent and does not delete orphaned rows. Review `orphaned_keys`, `missing_rows`, `classes_without_rows`, and `rows_without_classes` instead of silently discarding them.

## Running agents

Use `AiAgentManager` whenever an execution needs the package's provider hierarchy, configuration precedence, privacy gate, prompt firewall, domain context, tenancy checks, or execution records. Calling the SDK agent's `prompt()` directly bypasses those package features.

```php
use HomeSide\AiAgents\AiAgentManager;
use HomeSide\AiAgents\Execution\AiExecutionContextData;

$context = AiExecutionContextData::fromRequest(
    userId: $user->id,
    tenantId: $authorizedWorkspace?->id,
    conversationId: $conversation?->id,
);

$result = app(AiAgentManager::class)->run(
    agentKey: 'recipes.recipe_generator',
    context: $context,
    userMessage: $validatedMessage,
    attachments: $sdkAttachments, // optional, for multimodal agents
);
```

Before running, ensure that:

1. The class is registered in `config('ai-agents.agents')`.
2. The `ai_agents` row exists (automatic with `auto_sync`, or via `AgentSynchronizer::sync()`).
3. An enabled provider exists for the requested module or `fallback_module`, and its privacy level satisfies the agent's requirement.
4. The authenticated user may access the supplied tenant and conversation.

Treat the returned `AiExecutionResultData` as the package boundary. Do not depend on a provider-specific SDK response when the manager result already contains the normalized reply, status, usage, tool calls, and metadata.

## Providers, validated writes, and endpoint policy

Create or update package models through their validated write APIs. `createValidated()` / `updateValidated()` run the model's rules and security hooks and throw a single `Illuminate\Validation\ValidationException` before anything reaches the database. Ownership is declared via the virtual `scope` attribute, resolved into the right physical columns:

```php
use HomeSide\AiAgents\Models\AiProvider;

$provider = AiProvider::createValidated([
    'name' => 'OpenAI production',
    'type' => 'openai',
    'base_url' => 'https://api.openai.com/v1',
    'model' => 'gpt-4o',
    'api_key' => 'sk-...',                    // encrypted into api_key_encrypted
    'module' => 'assistant',
    'scope' => ['user' => $user->id],         // or ['tenant' => $tenant->id] or 'global'
]);
```

Do not bypass these helpers with unrestricted mass assignment: the `scope` path enforces tenant/user exclusivity, the `api_key` attribute is encrypted at rest by the `encrypted` cast and hidden from serialization, and provider URLs are checked against the SSRF policy. Plain `create()` / `save()` remain available for hosts that manage validation themselves.

`AiProviderEndpointPolicy` validates base URLs according to `config('ai-agents.endpoint_policy.mode')`:

- `saas` (default): HTTPS only; blocks loopback, RFC1918, link-local, and cloud metadata endpoints. Use when users can register providers on shared infrastructure.
- `self-hosted`: allows private ranges (Ollama/vLLM on localhost or LAN) but still blocks cloud metadata endpoints and dangerous ports.

### Provider capabilities and fallback policy

`ModelCapabilities` (reasoning flag, reasoning effort, `ToolCallFormat` — `native` or `xml` — and advertised context window) is built from `ai_providers.configuration.model_capabilities` with `AiDriver::defaultCapabilities()` as the per-driver baseline. Agents implementing `AcceptsProviderCapabilities` receive them right before the prompt: size `max_tokens` around thinking tokens (reasoning models burn the budget before content) and pick the tool-call wire format accordingly.

Provider (built-in) tools — web search, web fetch, file search, tool search, code execution — are advertised per driver in `Capability::driverBaseline()` and exposed through `ModelCapabilities::supportsProviderTool()` / the `UsesProviderCapabilities` trait. The SDK already skips provider tools a provider does not implement, so the package does not pre-filter them; use the helper to adapt the prompt or fall back to a local tool instead of silently losing a capability. Hosts may declare an explicit `model_capabilities.provider_tools` list to override the baseline.

The driver set mirrors the SDK's `Lab` enum: `openai`, `anthropic`, `gemini`, `ollama`, `openai-compatible`, `openrouter`, `xai`, `groq`, `deepseek`, `mistral`, `cohere`, `typesafe`, `azure`, `bedrock`, `eleven`, `jina`, `voyageai`. Unknown provider types degrade to `openai-compatible`. `AiDriver::defaultPrivacyLevel()` seeds provider privacy (Ollama → local, OpenAI-compatible → unknown, the rest → cloud).

Each provider carries a `fallback_policy` (`FallbackPolicy`: `local_only`, `same_privacy_level`, `allow_cloud`). During resolution, `ProviderResolver` replaces the primary provider with a fallback only when the candidate's privacy level satisfies that policy, so module coverage never silently degrades below the privacy the host declared.

## Prompt safety: layered firewall

Prompt content passes through a layered inspection pipeline before reaching the model. `AiAgentManager` inspects the user message, and `PromptCompositor` inspects user instructions and domain context; matched text is replaced with `[SECURITY RESTRICTION]`.

1. **Lexicon** — multilingual regex (`en`, `es` opt-in via `firewall.lexicon.languages`) plus the always-on structural file of role delimiters (`<<SYS>>`, `[INST]`, `<|im_start|>`) and scaffolding. A structural match forces `block` when `block_structural` is true.
2. **Statistical scorer** — language-agnostic signals: entropy, script mixing, special-token density, control characters, repetition.
3. **Classifier** (opt-in, off by default) — trained rubix/ml model over hashed character n-grams; a missing artifact degrades the layer to disabled. Lifecycle commands: `ai-agents:firewall:datasets` (list adapters), `ai-agents:firewall:download <dataset>` (HuggingFace), `ai-agents:firewall:train <corpus>` (produce the `.rbx` artifact), `ai-agents:firewall:evaluate <model> <corpus>`, `ai-agents:firewall:inspect <model>`.

Layer signals combine into an aggregated 0–1 score. `score >= thresholds.block` (or a structural match) → `block`; `score >= thresholds.flag` → the configured `action`; otherwise allowed. With `allow_block_from_score=false` (default), only the structural override or an explicit `action=block` can abort a run — `flag` is the safe default because false positives must not break users. A custom inspector must implement `InspectsPrompt` and replaces the whole pipeline via `firewall.inspector`. Components that supply sanitisation regexes implement `ProvidesSanitisationPatterns`, so `PromptCompositor` sanitises with exactly the patterns that produced the finding — detection and sanitisation can never drift apart.

The host's legacy `injection_patterns` config is still merged into the lexicon layer; extend it there for host-specific patterns. Check the live layer state with `php artisan ai-agents:firewall:status`.

Keep firewall enforcement inside `AiAgentManager` and `PromptCompositor`. Do not concatenate the user message into system instructions or weaken the layer order: technical guardrail → global platform policy → per-agent platform prompt → user additional instructions → domain context → execution context → integrity reminder.

## Providers, tenancy, and prompt safety

- Provider precedence is tenant, then user, then system when tenancy is enabled; otherwise it is user, then system. Within a scope, the requested module precedes `fallback_module`.
- Enable tenancy deliberately in `config/ai-agents.php` (`AI_AGENTS_TENANT_ENABLED=true` plus the tenant model/table/foreign-key/membership values), and configure a `ResolvesTenant` implementation when the generic resolver does not match the host's membership model.
- Resolve tenant access server-side before constructing `AiExecutionContextData`. Never trust a request's tenant identifier by itself.

## Reference catalog: models.dev

`php artisan ai-agents:models-dev:sync` (`--force` re-downloads logos, `--skip-logos` omits them) mirrors the public models.dev catalog into the `models_dev_providers` / `models_dev_models` reference tables: provider metadata, model specifications, pricing, and SVG logos under `models_dev.logo_disk_path`. It is scheduled daily at `models_dev.schedule_at` (default 02:00) when `models_dev.schedule_enabled`. The catalog is reference data only — execution always resolves `ai_providers`. Use `CatalogQuery` for searchable listings and `CatalogPrefill::prefill($providerSlug, $modelId)` to pre-fill `AiProvider::createValidated()` payloads in provider-admin UIs.

## Per-capability model defaults and embeddings

A provider no longer has a single default model: `ai_provider_model_defaults` holds one default per (provider, capability), with ownership derived transitively through `default → model → provider → user/tenant/system` (no tenant column; always start from an authorised provider, never trust a raw default UUID from HTTP).

- Only `Capability::canBeModelDefault()` values (`text`, `embeddings`, `reranking`) may be defaults. Features (`structured_output`, `tools`, `streaming`) are secondary requirements passed to `resolveDefault($provider, $primary, [secondary...])`, never defaults of their own.
- `Capability::requiresExplicitModelSupport()` marks `embeddings`/`reranking`: they are NEVER inherited from the driver baseline and must appear in `capabilities_detected`/`capabilities_override`. Do not assume "driver can embed → every model can".
- `ProviderResolver` stays scope/module/privacy/fallback; `ProviderModelResolver` owns model/capability/enabled/compatibility. Text resolution order: `ModuleAiConfiguration.provider_model_id` → legacy `model` → default `capability=text` → legacy `AiProvider.model` → error. A pinned model is never silently swapped; a UUID never bypasses validation.
- Set defaults through `ProviderModelDefaults::set()` (transactional, validates routable capability, ownership and support), not `AiProviderModel::markAsDefault()` (deprecated).
- `EmbeddingManager::embed(EmbeddingRequestData)` returns `EmbeddingResultData` (no SDK internals leak), uses Laravel AI's own `Embeddings` API and cache (`config('ai-agents.embeddings')`, off by default), validates vector count and dimensions, and never creates `AiRun` rows. It is generic infrastructure — semantic memory and the vector store (MariaDB VECTOR, pgvector, Qdrant...) are the host's concern.
- Embeddings preserve privacy on every path: `ProviderResolver::resolveForCapability()` anchors on the normally-resolved provider and walks the SAME user → tenant → system (requested module → fallback module) chain; a pinned `providerId` must go through `resolveExplicitForCapability()` (never a raw `find()`), so accessibility, module and the privacy anchor still apply and a foreign UUID cannot become an IDOR. In `database` isolation the user column still applies: a foreign user's personal provider is rejected, only `user_id = null` shared/global providers and the caller's own are accessible.
- `requiredPrivacyLevel` filters the SELECTION, not just the final check: a lower-scope provider that satisfies it beats a higher-scope one that does not, and a pinned provider never bypasses it. Use `resolveForCapability(..., requiredPrivacyLevel: $level)`; `hasCandidateRejectedOnlyByRequiredPrivacy()` (applies every other check, including the fallback policy) tells whether privacy was the ONLY blocker, so a `PrivacyViolationException` is only raised when that is actually true.
- A stale default (model disabled/deleted, missing capability or dimensions) is treated as unusable — `ProviderModelResolver::canResolveDefault()` — so the resolver falls through to a valid provider; the default row is kept (reactivating the model restores it). A pinned stale model still errors, never falls back silently.
- Any embeddings default/pinned model MUST have `embedding_dimensions > 0` (the SDK throws when a model is passed without dimensions except `openai-compatible`); `ProviderModelDefaults::set()` and `ProviderModelResolver` both enforce it. A default may not be a disabled model.
- `EmbeddingRequestData` supports `requiredPrivacyLevel` (a requirement of the operation, enforced even when pinned) and `batchSize` (chunking that preserves order and aggregates usage). `EmbeddingManager` validates vector count and dimensions and never creates `AiRun` rows.
- `DynamicProviderRegistrar` builds `models.text/embeddings/reranking` from stored data only. `EmbeddingProviderTester::testModel($provider, $model)` returns a structured `EmbeddingProviderTestData` (built only via `configured()`/`discovered()`/`error()`, so an inconsistent success is unrepresentable), validates ownership and allows exploratory/disabled models. `testProvider()` probes the fully configured default (verify-only). Known dimensions are VERIFIED; unknown dimensions are DISCOVERED only when `AiDriver::supportsNativeEmbeddingDimensions()`, otherwise it fails with `dimensions_required` before any provider call. The tester never writes: apply explicitly with `ProviderModelProbeResultApplier::apply()`, which re-reads the row under a lock and rejects a stale snapshot (`StaleProviderModelProbeResultException`): `configured` requires the exact value; `discovered` accepts null/equal and rejects a different value. A conflict never adds the capability or marks the probe ok, and `capabilities_override` is never modified. A successful probe neither enables the model nor makes it the default.
- Embedding profiles: `EmbeddingProfileResolver` resolves the vector-space identity WITHOUT a provider call, reusing `ProviderResolver`/`ProviderModelResolver` (never a raw `find()`), and returns `ResolvedEmbeddingProfileData` (no Eloquent, no secrets). `EmbeddingPurpose` (generic/document/query) only selects which options apply; options live on `AiProviderModel.embedding_options` (`{generic, document, query}`, document/query merged over generic via `array_replace_recursive`) and callers can NEVER pass arbitrary provider options. `EmbeddingProfileFingerprint` is a canonical SHA-256 of the WHOLE profile, so Document and Query of one profile share a fingerprint and changing any bucket (even query-only) invalidates it; it ignores API-key/display/probe metadata. Bump `embedding_profile_version` (EmbeddingProfileVersioner) to invalidate the space when the visible config is unchanged. `EmbeddingManager` uses the resolved profile exclusively and always returns it on the result (`$result->profile->fingerprint`); the package never re-indexes.
- Embedding options contract: validated by `EmbeddingOptionsValidator` (the single source of rules) both on SAVE (unknown purposes and secret-ish keys like `authorization`/`api_key`/`token`/`headers` rejected recursively) and on RESOLVE (legacy/corrupt rows fail closed — the fingerprint always represents the stored data). The endpoint in the fingerprint lowercases only scheme/host and drops default ports, but PRESERVES path case (different paths may be different endpoints). `embedding_profile_version` must be `>= 1`.
- Architectural rule: any future configuration that can mathematically affect the embeddings (normalisation, encoding, task type, prefixes, pooling, preprocessing...) MUST be added to `EmbeddingProfileFingerprint` BEFORE it is used in `EmbeddingManager`; using it in the request without hashing it silently mixes incompatible vector spaces. Note that list values inside an options bucket follow `array_replace_recursive` positional semantics.
- Conversation retention is independent from telemetry: `php artisan ai-agents:conversations:prune` (per tenant in database isolation) deletes conversations outside `conversations.retention.days`, cascading messages and never touching `AiRun`; a null window is a no-op. Continuing a conversation touches `updated_at` AFTER authorisation, so an in-use conversation is never pruned as abandoned and a foreign id cannot trigger a write.

## Usage and cost tracking

Every finished run stores an `estimated_cost` snapshot (USD) computed by `CostEstimator` with the provider's pricing at run time. `php artisan ai-agents:usage:consolidate` (`--days=` override) rolls finished runs older than `usage.retention_days` (default 30) into the `ai_usage_daily` table and deletes them, bounding the hot `ai_runs` table while keeping the economic history; re-runs heal rather than double-count. It is scheduled at `usage.schedule_at` (default 03:00, after the models.dev sync) when `usage.schedule_enabled`. Read aggregates through `CostEstimator::totalSpend()` and `CostEstimator::spendByDay()`.

## Conversation memory

An agent gains memory **only** by implementing Laravel AI's native `RemembersConversations` contract (or using its trait). The package binds `Laravel\Ai\Contracts\ConversationStore` to `PackageConversationStore` when `conversations.enabled` is true, so the SDK's own protocol (tool replay, paused turns, approvals) keeps working while the transcript lives in `ai_conversations` / `ai_conversation_messages`. Do not create a parallel `agent_conversations` identity and do not reimplement the SDK's conversation middleware.

- Enable with `config('ai-agents.conversations.enabled')` (default `false`). `conversations.retention.days` governs the transcript; `usage.retention_days` governs `AiRun` telemetry. They are independent: consolidating/deleting runs never affects memory, and `logging.retention=none` never disables functional chat memory.
- Pass an existing conversation id through `AiExecutionContextData::$conversationId` (or `withConversationId()`). `AiAgentManager` authorises it, records the run against it and calls `continue()`. `AiExecutionResultData` exposes `conversationId`, `userMessageId` and `assistantMessageId`.
- **Never** call `$sdkAgent->continue($id)` or `AiConversation::find($id)` outside `ConversationAccessGuard`. The guard resolves owner + agent + tenant in one query; a missing and an inaccessible conversation both raise `ConversationNotFoundException` (no enumeration oracle). A stateless agent handed a conversation id raises `ConversationNotSupportedException`.
- The whole message payload (content, attachments, steps with tool calls/arguments/results, meta) and the title are envelope-encrypted through the shared `UserContentCipher`; crypto-shredding a user's DEK makes runs **and** chats unrecoverable. `RunContentCast` and the conversation casts share this one cipher.
- Keep token-aware compaction out of the first implementation: `PackageConversationStore::getLatestConversationMessages()` caps history at `min(sdk limit, conversations.context.max_messages)`. `DomainAgent::maxContextTokens()` is the future budget for a summarisation layer that never destructively replaces the original messages.

## Testing

- Use Laravel AI SDK fakes for agent and tool behavior; never call a real provider in automated tests.
- Exercise package integrations through `AiAgentManager` when the behavior depends on configuration, provider selection, privacy level, prompt composition, tenancy, firewall handling, or recording.
- Test authorization inside every Tool independently from the agent prompt.
- Cover missing synchronization, missing provider, privacy violations, disabled agent, inaccessible tenant, and blocked prompt paths when the change touches those decisions.
- Cover content-mode resolution (host ceiling versus consent), transparent decryption of encrypted runs, shredding, and grant read/revoke paths when the change touches `ai_runs` content handling. Never assert on plaintext persisted in `ai_runs` for non-consented users.
- Cover conversation memory when the change touches it: new conversation creation, continued history, stateless rejection, cross-user / cross-agent / cross-tenant access, encrypted payload (never assert plaintext in `ai_conversation_messages`), crypto-shred, cascade delete, paused/resumed approvals, and that deleting runs leaves the transcript intact.
- Cover per-capability defaults and embeddings when the change touches them: unique default per (provider, capability), non-routable capability rejected, disabled/foreign model rejected, required-capability filtering, text precedence (provider_model_id → legacy model → default → legacy provider.model), embeddings default not inferred from the driver baseline, crypto-free dimension validation, multiple inputs → same number of vectors, `local_only` anchoring not degrading to cloud, and the acceptance case where one provider routes text and embeddings to different models on the same endpoint.
- Cover provider capability injection and `fallback_policy` privacy filtering when the change touches resolution or agent budgeting.
- Keep package-level tests compatible with Orchestra Testbench and use the host application's factories for host models.
