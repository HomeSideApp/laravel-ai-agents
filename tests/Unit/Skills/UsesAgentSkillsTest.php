<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Skills;

use HomeSide\AiAgents\Skills\SkillRegistry;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\Unit\Fixtures\SkillAwareAgent;
use Laravel\Ai\Skills\Skill;

/**
 * UsesAgentSkills exposes the registry through the SDK HasSkills contract.
 */
final class UsesAgentSkillsTest extends TestCase
{
    private function seedRegistry(): void
    {
        $registry = new SkillRegistry(enabled: true, firewall: false);
        $registry->register(new Skill('greeting', 'Say hi.', 'Greet warmly.'));
        $registry->register(new Skill('farewell', 'Say bye.', 'Wave goodbye.'));

        $this->app->instance(SkillRegistry::class, $registry);
    }

    /**
     * The agent's skills() returns every registered skill.
     */
    public function test_skills_returns_registered_skills(): void
    {
        $this->seedRegistry();

        $names = array_map(
            static fn (Skill $skill): string => $skill->name,
            (new SkillAwareAgent)->namedSkills(),
        );

        $this->assertEqualsCanonicalizing(['greeting', 'farewell'], $names);
    }

    /**
     * skillsNamed() restricts the agent to a named subset, skipping unknown
     * names silently.
     */
    public function test_named_subset_skips_unknown_skills(): void
    {
        $this->seedRegistry();

        $skills = (new SkillAwareAgent)->namedSkills(['greeting', 'missing']);

        $this->assertCount(1, $skills);
        $this->assertSame('greeting', $skills[0]->name);
    }

    /**
     * An empty registry yields no skills (agent without skills works).
     */
    public function test_empty_registry_yields_no_skills(): void
    {
        $this->app->instance(SkillRegistry::class, new SkillRegistry(enabled: true, firewall: false));

        $this->assertSame([], (new SkillAwareAgent)->namedSkills());
    }
}
