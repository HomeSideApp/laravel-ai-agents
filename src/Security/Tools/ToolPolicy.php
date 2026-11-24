<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Tools;

use Illuminate\Support\Str;

/**
 * A named, ordered set of rules deciding what an agent's tools may do.
 *
 * Evaluation answers a different question than the content firewall: not "does
 * this text look like an attack" but "may this agent perform this action, with
 * these arguments, given where the input came from". Identity binds and tool
 * allowlists are deterministic, so they hold where no classifier can.
 */
final class ToolPolicy
{
    /**
     * @param  list<ToolRule>  $rules
     * @param  list<array{0: ToolEffect, 1: string}>  $whenTainted
     */
    public function __construct(
        public readonly string $name,
        public readonly ToolEffect $default,
        public readonly array $rules = [],
        public readonly array $whenTainted = [],
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(string $name, array $config): self
    {
        $rules = [];

        foreach ((array) ($config['rules'] ?? []) as $rule) {
            $rules[] = ToolRule::fromArray((array) $rule);
        }

        $whenTainted = [];
        $taint = (array) ($config['when_tainted'] ?? []);
        $effect = ToolEffect::fromString((string) ($taint['effect'] ?? 'deny'));

        foreach ((array) ($taint['sensitive_tools'] ?? []) as $tool) {
            $whenTainted[] = [$effect, (string) $tool];
        }

        return new self(
            name: $name,
            default: ToolEffect::fromString((string) ($config['default'] ?? 'deny')),
            rules: $rules,
            whenTainted: $whenTainted,
        );
    }

    /**
     * Denies everything. Used when the feature is enabled but no policy can be
     * resolved for the agent: a missing policy must not become an open door.
     */
    public static function denyAll(string $name, ToolPolicyReason $reason): self
    {
        return new self(
            name: $name,
            default: ToolEffect::Deny,
            rules: [new ToolRule(['*'], ToolEffect::Deny, $reason->value, [], $reason)],
        );
    }

    /**
     * Precedence, first hit wins: explicit deny, argument guards, taint,
     * approval, allow, then the policy default. A deny rule therefore always
     * wins: no taint rule may re-enable what an explicit rule forbade.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function decide(string $tool, array $arguments = [], Taintedness $tainted = Taintedness::Clean): ToolDecision
    {
        $matched = array_values(array_filter(
            $this->rules,
            static fn (ToolRule $rule): bool => $rule->matches($tool),
        ));

        foreach ($matched as $rule) {
            if ($rule->effect === ToolEffect::Deny) {
                return ToolDecision::deny(
                    $rule->reasonCode ?? ToolPolicyReason::ToolDenied,
                    $rule->reason ?? "Tool [{$tool}] is denied by policy [{$this->name}].",
                );
            }
        }

        foreach ($matched as $rule) {
            $supplied = array_values(array_intersect($rule->forbidArguments, array_keys($arguments)));

            if ($supplied !== []) {
                return ToolDecision::deny(
                    ToolPolicyReason::ArgumentForbidden,
                    sprintf(
                        'Argument [%s] must not be supplied by the model for tool [%s].',
                        implode(', ', $supplied),
                        $tool,
                    ),
                );
            }
        }

        if ($tainted === Taintedness::Untrusted) {
            foreach ($this->whenTainted as [$effect, $pattern]) {
                if (Str::is($pattern, $tool)) {
                    $reason = "Tool [{$tool}] is restricted while the run carries untrusted content.";

                    return $effect === ToolEffect::Approval
                        ? ToolDecision::approval(ToolPolicyReason::TaintedCapability, $reason)
                        : ToolDecision::deny(ToolPolicyReason::TaintedCapability, $reason);
                }
            }
        }

        foreach ($matched as $rule) {
            if ($rule->effect === ToolEffect::Approval) {
                return ToolDecision::approval(
                    ToolPolicyReason::SensitiveRequiresApproval,
                    $rule->reason ?? "Tool [{$tool}] requires human approval.",
                );
            }
        }

        foreach ($matched as $rule) {
            if ($rule->effect === ToolEffect::Allow) {
                return ToolDecision::allow();
            }
        }

        return match ($this->default) {
            ToolEffect::Allow => ToolDecision::allow(),
            ToolEffect::Approval => ToolDecision::approval(
                ToolPolicyReason::SensitiveRequiresApproval,
                "Tool [{$tool}] requires human approval by policy [{$this->name}].",
            ),
            ToolEffect::Deny => ToolDecision::deny(
                ToolPolicyReason::ToolNotAllowlisted,
                "Tool [{$tool}] is not allowed by policy [{$this->name}].",
            ),
        };
    }
}
