<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Contracts;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Enums\PrivacyLevel;

/**
 * Metadata contract for native Laravel AI SDK agents.
 *
 * Domain agents implement the SDK native interfaces (Agent,
 * HasStructuredOutput, HasTools, Conversational) plus this interface to
 * declare their metadata.
 *
 * Do not re-implement capabilities the SDK already provides:
 * - instructions → via Agent::instructions()
 * - tools → via HasTools::tools()
 * - schema → via HasStructuredOutput::schema()
 * - middleware → via HasMiddleware
 * - structured output → via HasStructuredOutput
 */
interface DomainAgent
{
    /**
     * Unique identifier of the agent (e.g. 'recipes.recipe_generator').
     */
    public function key(): string;

    /**
     * Module the agent belongs to (e.g. 'recipes', 'economy').
     */
    public function module(): string;

    /**
     * Version of the agent (e.g. 1).
     * Recorded on every execution to detect regressions.
     */
    public function version(): int;

    /**
     * Capabilities the agent requires to work correctly.
     * CapabilityResolver will verify compatibility with the selected model.
     *
     * @return Capability[]
     */
    public function requiredCapabilities(): array;

    /**
     * Default configuration of the agent (temperature, max_tokens, etc.).
     * Overrides may replace these values per scope.
     *
     * @return array{temperature?: float, top_p?: float, max_tokens?: int, max_steps?: int, timeout?: int}
     */
    public function defaultConfiguration(): array;

    /**
     * List of Context Provider keys the agent needs.
     * Each one supplies small host data to the AI context.
     *
     * @return string[]
     */
    public function contextProviders(): array;

    /**
     * Maximum tokens the agent may use for context.
     */
    public function maxContextTokens(): int;

    /**
     * Minimum privacy level the resolved provider must satisfy, or null
     * when the agent accepts any provider.
     *
     * Checked after provider resolution: when the resolved provider is
     * less private than required, the run aborts with a
     * PrivacyViolationException instead of sending data to an
     * unapproved host (e.g. personal data confined to local models).
     */
    public function requiredPrivacyLevel(): ?PrivacyLevel;
}
