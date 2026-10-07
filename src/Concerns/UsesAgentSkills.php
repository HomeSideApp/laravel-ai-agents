<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Concerns;

use HomeSide\AiAgents\Skills\SkillRegistry;
use Laravel\Ai\Skills\Skill;

/**
 * Reusable implementation of the SDK's HasSkills contract for domain agents.
 *
 * The agent implements Laravel\Ai\Contracts\HasSkills and uses this trait;
 * skills() then returns the package's registry contents, which already
 * includes directory-discovered skills, programmatic providers and the
 * firewall inspection result.
 *
 * The SDK's GeneratesText::declaredTools() sees HasSkills on the agent and
 * merges a LoadSkill tool into the run automatically, so a skill-aware agent
 * gains on-demand skill loading without any manager changes.
 *
 * Agents needing a narrower set of skills override skills() and call
 * skillsNamed() with the list they allow.
 */
trait UsesAgentSkills
{
    /**
     * Get the skills available to the agent.
     *
     * @return iterable<int, Skill>
     */
    public function skills(): iterable
    {
        return $this->skillRegistry()->sources();
    }

    /**
     * Restrict the agent to a named subset of the registered skills.
     *
     * When a name is unknown the entry is skipped silently: a skill removed
     * from the host must not break an agent that still references it.
     *
     * @param  list<string>  $names  The skill names this agent may load.
     * @return list<Skill>
     */
    protected function skillsNamed(array $names): array
    {
        $registry = $this->skillRegistry();

        return array_values(array_filter(
            array_map(fn (string $name): ?Skill => $registry->get($name), $names),
        ));
    }

    /**
     * Resolve the package skill registry from the container.
     *
     * Container resolution keeps the trait usable by agents with arbitrary
     * constructors (the registry is a shared singleton).
     */
    private function skillRegistry(): SkillRegistry
    {
        /** @var SkillRegistry $registry */
        $registry = app(SkillRegistry::class);

        return $registry;
    }
}
