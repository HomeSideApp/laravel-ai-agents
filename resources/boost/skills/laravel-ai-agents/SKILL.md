---
name: laravel-ai-agents
description: Develop and integrate homeside/laravel-ai-agents features — domain agents, modules, context providers, provider resolution, privacy gates (requiredPrivacyLevel, PrivacyLevel), encrypted per-user run content (content modes, crypto-shredding, support grants), validated model writes (createValidated/updateValidated), SSRF endpoint policy, provider model capabilities and privacy fallback policy, the layered prompt firewall with classifier training commands, models.dev catalog sync, daily usage and cost consolidation, human-in-the-loop action proposals, optional tenancy, and execution recording. Use when a Laravel application contains homeside/laravel-ai-agents, code in the HomeSide\AiAgents namespace, or config/ai-agents.php, or when creating or modifying AI agents, tools, modules, or provider setup built on it.
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

## Context providers and tools

Implement `HomeSide\AiAgents\Context\ContextProvider` for small, bounded facts that should be included in every relevant prompt. Its `key()` must match a value returned by the agent's `contextProviders()` method.

Use an SDK Tool instead when data is large, optional, expensive, or should be queried on demand. Scope provider queries and tool operations to the authenticated user and accessible tenant. Do not place bulk model data or secrets into prompt context.

### Action proposals (human-in-the-loop)

When an agent must not mutate host data directly, its tool records a pending `AiActionProposal` instead of acting. Extend the published `ActionProposalTool` stub (tag `ai-agents-stubs`): the tool requires an `AiExecutionContextData` — an empty context is rejected, so proposals are never created without a user identity — persists through the package's validated writes, and leaves acceptance and execution to host-side actions. Proposal types and handlers are domain-specific: the model proposes, a human decides.

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

## Usage and cost tracking

Every finished run stores an `estimated_cost` snapshot (USD) computed by `CostEstimator` with the provider's pricing at run time. `php artisan ai-agents:usage:consolidate` (`--days=` override) rolls finished runs older than `usage.retention_days` (default 30) into the `ai_usage_daily` table and deletes them, bounding the hot `ai_runs` table while keeping the economic history; re-runs heal rather than double-count. It is scheduled at `usage.schedule_at` (default 03:00, after the models.dev sync) when `usage.schedule_enabled`. Read aggregates through `CostEstimator::totalSpend()` and `CostEstimator::spendByDay()`.

## Testing

- Use Laravel AI SDK fakes for agent and tool behavior; never call a real provider in automated tests.
- Exercise package integrations through `AiAgentManager` when the behavior depends on configuration, provider selection, privacy level, prompt composition, tenancy, firewall handling, or recording.
- Test authorization inside every Tool independently from the agent prompt.
- Cover missing synchronization, missing provider, privacy violations, disabled agent, inaccessible tenant, and blocked prompt paths when the change touches those decisions.
- Cover content-mode resolution (host ceiling versus consent), transparent decryption of encrypted runs, shredding, and grant read/revoke paths when the change touches `ai_runs` content handling. Never assert on plaintext persisted in `ai_runs` for non-consented users.
- Cover provider capability injection and `fallback_policy` privacy filtering when the change touches resolution or agent budgeting.
- Keep package-level tests compatible with Orchestra Testbench and use the host application's factories for host models.
