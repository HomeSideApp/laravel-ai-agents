<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Contracts;

use Laravel\Ai\Skills\Skill;

/**
 * Contract for host components that supply Agent Skills programmatically.
 *
 * Mirrors the ContextProvider pattern: the package walks
 * config('ai-agents.skills.providers') and calls skills() on each registered
 * implementation, so hosts can source skills from anywhere (database,
 * remote catalogue, generated bundles) without relying on the SDK's
 * implicit resource_path('skills') scan.
 *
 * The returned Skill instances are merged with the directory-discovered
 * skills in SkillRegistry; later registrations win on name collisions.
 */
interface ProvidesSkills
{
    /**
     * Get the skills this provider contributes.
     *
     * @return iterable<int, Skill> The skills to register, keyed by the
     *                              registry (name comes from Skill::$name).
     */
    public function skills(): iterable;
}
