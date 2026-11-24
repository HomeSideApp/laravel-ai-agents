<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Tools;

/**
 * Runtime registry of policies and per-object assignments.
 *
 * Hosts that build agents dynamically assign a policy here instead of using the
 * attribute; both paths converge in ToolPolicyResolver.
 */
final class ToolPolicyManager
{
    /** @var array<string, ToolPolicy> */
    private array $policies = [];

    /** @var array<int, string> */
    private array $assignments = [];

    public function register(ToolPolicy $policy): void
    {
        $this->policies[$policy->name] = $policy;
    }

    public function use(object $agent, string $policy): void
    {
        $this->assignments[spl_object_id($agent)] = $policy;
    }

    public function policyFor(object $agent): ?ToolPolicy
    {
        $name = $this->assignedPolicyName($agent);

        return $name === null ? null : ($this->policies[$name] ?? null);
    }

    public function assignedPolicyName(object $agent): ?string
    {
        return $this->assignments[spl_object_id($agent)] ?? null;
    }

    public function get(string $name): ?ToolPolicy
    {
        return $this->policies[$name] ?? null;
    }
}
