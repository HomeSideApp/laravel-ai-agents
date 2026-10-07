<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Skills;

use HomeSide\AiAgents\Contracts\InspectsPrompt;
use HomeSide\AiAgents\Contracts\ProvidesSkills;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Security\FirewallFinding;
use HomeSide\AiAgents\Skills\SkillRegistry;
use HomeSide\AiAgents\Tests\TestCase;
use Laravel\Ai\Skills\Skill;

/**
 * SkillRegistry discovery, provider merging and firewall gating.
 */
final class SkillRegistryTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function registry(bool $firewall = true, array $directories = [], array $providers = []): SkillRegistry
    {
        $inspector = new class implements InspectsPrompt
        {
            public bool $block = false;

            public function inspect(string $layer, string $content, AiExecutionContextData $context): FirewallFinding
            {
                return $this->block
                    ? new FirewallFinding(FirewallFinding::ACTION_BLOCK, ['inject'])
                    : FirewallFinding::clean();
            }
        };

        $this->app->instance(InspectsPrompt::class, $inspector);

        return new SkillRegistry(
            directories: $directories,
            enabled: true,
            firewall: $firewall,
            inspector: $inspector,
        );
    }

    /**
     * A directory with a SKILL.md is discovered and keyed by name.
     */
    public function test_discovers_skills_from_directories(): void
    {
        $base = sys_get_temp_dir().'/ai-skills-'.uniqid();
        mkdir($base.'/greeting', 0777, true);
        file_put_contents($base.'/greeting/SKILL.md', "---\nname: greeting\ndescription: Say hi.\n---\nGreet warmly.");

        $registry = $this->registry(directories: [$base]);

        $this->assertTrue($registry->has('greeting'));
        $this->assertSame('Say hi.', (string) $registry->get('greeting')?->description);
    }

    /**
     * A programmatic provider contributes skills, overriding a same-named
     * discovered one.
     */
    public function test_provider_skills_override_discovered(): void
    {
        $provider = new class implements ProvidesSkills
        {
            public function skills(): iterable
            {
                return [new Skill('greeting', 'Programmatic.', 'Programmatic body.')];
            }
        };

        $registry = $this->registry(providers: []);
        $registry->register(new Skill('greeting', 'Discovered.', 'Discovered body.'));
        $registry->registerProvider($provider);

        $this->assertSame('Programmatic.', (string) $registry->get('greeting')?->description);
    }

    /**
     * A blocked skill is dropped; the rest survive.
     */
    public function test_firewall_blocked_skills_are_dropped(): void
    {
        $inspector = new class implements InspectsPrompt
        {
            public function inspect(string $layer, string $content, AiExecutionContextData $context): FirewallFinding
            {
                return str_contains($content, 'poison')
                    ? new FirewallFinding(FirewallFinding::ACTION_BLOCK, ['poison'])
                    : FirewallFinding::clean();
            }
        };

        $registry = new SkillRegistry(enabled: true, firewall: true, inspector: $inspector);
        $registry->register(new Skill('safe', 'Safe.', 'Safe body.'));
        $registry->register(new Skill('bad', 'Bad.', 'A poison payload.'));

        $this->assertTrue($registry->has('safe'));
        $this->assertFalse($registry->has('bad'));
    }

    /**
     * With the firewall disabled, blocked-looking content is kept.
     */
    public function test_firewall_disabled_keeps_all_skills(): void
    {
        $registry = $this->registry(firewall: false);
        $registry->register(new Skill('any', 'Any.', 'A poison payload.'));

        $this->assertTrue($registry->has('any'));
    }

    /**
     * A disabled registry never exposes skills.
     */
    public function test_disabled_registry_is_empty(): void
    {
        $registry = new SkillRegistry(enabled: false);
        $registry->register(new Skill('safe', 'Safe.', 'Safe body.'));

        $this->assertSame([], $registry->all());
        $this->assertFalse($registry->isEnabled());
    }
}
