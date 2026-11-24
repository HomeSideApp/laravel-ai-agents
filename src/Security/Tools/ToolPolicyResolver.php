<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Tools;

use HomeSide\AiAgents\Attributes\ToolPolicy as ToolPolicyAttribute;
use Illuminate\Contracts\Config\Repository;
use ReflectionObject;

/**
 * Resolves the policy that governs an agent, in this order: runtime assignment,
 * the #[ToolPolicy] attribute, then the configured default policy.
 *
 * A name that resolves to nothing is not treated as "no policy": once the
 * feature is enabled, an unresolvable policy denies every call, because a
 * silently unapplied policy reads exactly like a working one.
 */
final class ToolPolicyResolver
{
    public function __construct(
        private readonly ToolPolicyManager $manager,
        private readonly Repository $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('ai-agents.firewall.tools.enabled', false);
    }

    public function resolve(object $agent): ?ToolPolicy
    {
        $name = $this->policyName($agent);

        if ($name === null) {
            return null;
        }

        $registered = $this->manager->get($name);

        if ($registered !== null) {
            return $registered;
        }

        $config = $this->config->get("ai-agents.firewall.tools.policies.{$name}");

        return is_array($config) ? ToolPolicy::fromArray($name, $config) : null;
    }

    public function resolveOrDenyAll(object $agent): ToolPolicy
    {
        return $this->resolve($agent) ?? ToolPolicy::denyAll(
            $this->policyName($agent) ?? 'unresolved',
            ToolPolicyReason::PolicyUnresolved,
        );
    }

    private function policyName(object $agent): ?string
    {
        $assigned = $this->manager->assignedPolicyName($agent);

        if ($assigned !== null && $assigned !== '') {
            return $assigned;
        }

        $attributes = (new ReflectionObject($agent))
            ->getAttributes(ToolPolicyAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->name;
        }

        $default = $this->config->get('ai-agents.firewall.tools.default_policy');

        return is_string($default) && $default !== '' ? $default : null;
    }
}
