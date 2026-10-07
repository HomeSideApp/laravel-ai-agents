<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Console\Commands;

use HomeSide\AiAgents\Skills\SkillRegistry;
use Illuminate\Console\Command;

/**
 * Inspect the registered Agent Skills.
 *
 *   php artisan ai-agents:skills:sync
 *
 * Hydrates the SkillRegistry (directory discovery + programmatic providers)
 * and reports every skill that survived the prompt firewall's inspection.
 * Skills dropped by the firewall are not listed — they never reach an agent.
 */
class SkillsSyncCommand extends Command
{
    protected $signature = 'ai-agents:skills:sync';

    protected $description = 'List the registered Agent Skills after discovery and firewall inspection';

    /**
     * Execute the skills inspection.
     *
     * @return int Exit code — SUCCESS unless the skills feature is disabled.
     */
    public function handle(SkillRegistry $registry): int
    {
        if (! $registry->isEnabled()) {
            $this->warn('Agent Skills are disabled (ai-agents.skills.enabled = false).');

            return self::SUCCESS;
        }

        $skills = $registry->all();

        if ($skills === []) {
            $this->info('No Agent Skills registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Description'],
            array_map(
                static fn ($skill): array => [
                    $skill->name,
                    (string) $skill->description,
                ],
                array_values($skills),
            ),
        );

        $this->info(count($skills).' skill(s) available.');

        return self::SUCCESS;
    }
}
