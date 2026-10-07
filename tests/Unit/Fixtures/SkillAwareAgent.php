<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Fixtures;

use HomeSide\AiAgents\Concerns\UsesAgentSkills;
use Laravel\Ai\Contracts\HasSkills;
use Laravel\Ai\Skills\Skill;

/**
 * Minimal agent exposing the package skill registry as SDK skills.
 *
 * Uses the trait's skills() as the SDK contract implementation; namedSkills()
 * exposes the restricted subset helper for assertions.
 */
final class SkillAwareAgent extends DummyAgent implements HasSkills
{
    use UsesAgentSkills;

    /**
     * The agent's skills, optionally restricted to a named subset.
     *
     * @param  list<string>|null  $names  When set, only these skills are returned.
     * @return list<Skill>
     */
    public function namedSkills(?array $names = null): array
    {
        return $names === null
            ? array_values([...$this->skills()])
            : $this->skillsNamed($names);
    }
}
