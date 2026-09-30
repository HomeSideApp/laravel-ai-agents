<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Contracts;

/**
 * Contract for agents that expose declarative metadata to the platform synchroniser.
 *
 * The synchroniser reads this metadata to create/update the ai_agents row
 * that represents the agent in the admin UI. The actual functional prompt
 * lives in the database; this interface only provides the label, description
 * and default parameters that seed the row on first sync.
 */
interface AgentMetadata
{
    /**
     * Human-readable label shown in the admin UI.
     *
     * Seeds the `label` column of the ai_agents row the first time the
     * synchroniser creates it; later edits in the admin UI take precedence.
     *
     * @return string A short display name (e.g. 'Recipe generator').
     */
    public function label(): string;

    /**
     * Optional description of what the agent does.
     *
     * Seeds the `description` column on first sync; also a practical hint
     * for admins choosing between agents of the same module.
     *
     * @return string|null One or two sentences, or null when the agent needs
     *                     no further explanation beyond its label.
     */
    public function description(): ?string;

    /**
     * Default parameter overrides stored on the ai_agents row.
     *
     * Seeded once at sync; afterwards the values live in the database and are
     * merged by AgentConfigurationResolver (user → ai_agent → class defaults).
     * Keys outside the known parameter set are ignored by the resolver.
     *
     * @return array<string, mixed>|null Allowed keys: 'temperature' (float),
     *                                   'top_p' (float), 'max_tokens' (int),
     *                                   'max_steps' (int), 'timeout' (int).
     *                                   Null leaves the row without
     *                                   overrides.
     */
    public function defaultParameters(): ?array;
}
