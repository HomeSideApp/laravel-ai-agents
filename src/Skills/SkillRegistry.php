<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Skills;

use HomeSide\AiAgents\Contracts\InspectsPrompt;
use HomeSide\AiAgents\Contracts\ProvidesSkills;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Security\NullPromptInspector;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Skills\Skill;

/**
 * Registry of Agent Skills available to the host application.
 *
 * Mirrors the AgentRegistry pattern: skills are discovered once (from the
 * configured directories and any registered ProvidesSkills implementation)
 * and exposed to agents as a stable, name-keyed collection.
 *
 * Why not rely on the SDK's implicit resource_path('skills') scan? Because
 * the package wants to:
 * - validate and de-duplicate skills centrally;
 * - inspect every skill through the prompt firewall before the model can
 *   load it, so a poisoned SKILL.md cannot smuggle instructions;
 * - let hosts source skills from anywhere (database, remote catalogue) by
 *   registering a ProvidesSkills implementation.
 *
 * The registry is intentionally lazy: hydration happens on first access and
 * is cached, so a request that never touches skills pays nothing.
 */
final class SkillRegistry
{
    /** @var array<string, Skill> */
    private array $skills = [];

    /** @var array<class-string, ProvidesSkills> */
    private array $providers = [];

    private bool $hydrated = false;

    /**
     * @param  list<string>  $directories  Directories scanned for '<name>/SKILL.md'.
     * @param  bool  $enabled  Master switch; when false the registry stays empty.
     * @param  bool  $firewall  Whether to inspect each skill's instructions.
     * @param  InspectsPrompt  $inspector  The bound prompt inspector.
     */
    public function __construct(
        private readonly array $directories = [],
        private readonly bool $enabled = true,
        private readonly bool $firewall = true,
        private readonly InspectsPrompt $inspector = new NullPromptInspector,
    ) {}

    /**
     * Register a programmatic skill provider.
     *
     * Idempotent per provider class: re-registering the same implementation
     * would duplicate its skills, so the registry keys providers by class.
     */
    public function registerProvider(ProvidesSkills $provider): void
    {
        $this->providers[$provider::class] = $provider;
        $this->hydrated = false;
    }

    /**
     * Register a single skill at runtime.
     *
     * Later registrations win on name collisions, matching AgentRegistry's
     * "last registration wins" contract.
     */
    public function register(Skill $skill): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->skills[$skill->name] = $skill;
    }

    /**
     * Determine whether the registry is enabled for this installation.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Every hydrated skill, keyed by name.
     *
     * @return array<string, Skill>
     */
    public function all(): array
    {
        $this->hydrate();

        return $this->skills;
    }

    /**
     * The skills as a positional list, suitable for the SDK's LoadSkill tool.
     *
     * @return list<Skill>
     */
    public function sources(): array
    {
        return array_values($this->all());
    }

    /**
     * Whether a skill with the given name is registered.
     */
    public function has(string $name): bool
    {
        $this->hydrate();

        return isset($this->skills[$name]);
    }

    /**
     * Resolve a skill by name, or null when unknown.
     */
    public function get(string $name): ?Skill
    {
        $this->hydrate();

        return $this->skills[$name] ?? null;
    }

    /**
     * Drop the hydration cache so the next access rebuilds it.
     */
    public function refresh(): void
    {
        $this->skills = [];
        $this->hydrated = false;
    }

    /**
     * Discover and validate every skill exactly once.
     *
     * Directory skills are loaded first, then programmatic providers, so a
     * provider can override a file-based skill with the same name. Each
     * candidate passes through the firewall when enabled; a blocked skill is
     * dropped with a warning rather than aborting the whole application boot
     * (skills are additive, an invalid one must not take the app down).
     */
    private function hydrate(): void
    {
        if ($this->hydrated || ! $this->enabled) {
            $this->hydrated = true;

            return;
        }

        foreach ($this->discoverFromDirectories() as $skill) {
            $this->skills[$skill->name] = $skill;
        }

        foreach ($this->providers as $provider) {
            foreach ($provider->skills() as $skill) {
                $this->skills[$skill->name] = $skill;
            }
        }

        if ($this->firewall) {
            $this->inspectAll();
        }

        $this->hydrated = true;
    }

    /**
     * Scan the configured directories for '<name>/SKILL.md' bundles.
     *
     * @return list<Skill>
     */
    private function discoverFromDirectories(): array
    {
        $skills = [];

        foreach ($this->directories as $directory) {
            $directory = rtrim($directory, '/\\');

            if ($directory === '' || ! is_dir($directory)) {
                continue;
            }

            foreach (glob($directory.'/*/SKILL.md') ?: [] as $file) {
                $skill = Skill::fromDirectory(dirname($file));

                if ($skill instanceof Skill) {
                    $skills[] = $skill;
                }
            }
        }

        return $skills;
    }

    /**
     * Inspect every hydrated skill and drop the ones the firewall blocks.
     */
    private function inspectAll(): void
    {
        // A synthetic, identity-free context: skills are static resources,
        // so inspection must not (and cannot) depend on a real user.
        $context = new AiExecutionContextData(userId: 'system');

        foreach ($this->skills as $name => $skill) {
            $finding = $this->inspector->inspect(
                'skill',
                (string) $skill->instructions,
                $context,
            );

            if ($finding->allows()) {
                continue;
            }

            unset($this->skills[$name]);

            Log::warning('AI skill blocked by the prompt firewall', [
                'skill' => $name,
                'action' => $finding->action,
                'patterns' => $finding->patterns,
            ]);
        }
    }
}
