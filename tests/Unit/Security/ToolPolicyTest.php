<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Security;

use HomeSide\AiAgents\Security\Tools\Taintedness;
use HomeSide\AiAgents\Security\Tools\ToolEffect;
use HomeSide\AiAgents\Security\Tools\ToolPolicy;
use HomeSide\AiAgents\Security\Tools\ToolPolicyReason;
use HomeSide\AiAgents\Tests\TestCase;

final class ToolPolicyTest extends TestCase
{
    private function policy(array $config, string $name = 'test'): ToolPolicy
    {
        return ToolPolicy::fromArray($name, $config);
    }

    public function test_allowlisted_tool_is_allowed(): void
    {
        $policy = $this->policy([
            'default' => 'deny',
            'rules' => [['tools' => ['create_recipe'], 'effect' => 'allow']],
        ]);

        $this->assertSame(ToolEffect::Allow, $policy->decide('create_recipe')->effect);
    }

    public function test_unknown_tool_falls_through_to_default_deny(): void
    {
        $policy = $this->policy([
            'default' => 'deny',
            'rules' => [['tools' => ['create_recipe'], 'effect' => 'allow']],
        ]);

        $decision = $policy->decide('read_file');

        $this->assertSame(ToolEffect::Deny, $decision->effect);
        $this->assertSame(ToolPolicyReason::ToolNotAllowlisted, $decision->reasonCode);
    }

    public function test_globs_match_tool_names(): void
    {
        $policy = $this->policy([
            'default' => 'deny',
            'rules' => [['tools' => ['recipe.*'], 'effect' => 'allow']],
        ]);

        $this->assertSame(ToolEffect::Allow, $policy->decide('recipe.create')->effect);
        $this->assertSame(ToolEffect::Deny, $policy->decide('recipex.create')->effect);
    }

    public function test_explicit_deny_wins_over_allow_and_taint(): void
    {
        $policy = $this->policy([
            'default' => 'allow',
            'rules' => [
                ['tools' => ['*'], 'effect' => 'allow'],
                ['tools' => ['query_db'], 'effect' => 'deny'],
            ],
            'when_tainted' => ['sensitive_tools' => ['query_db'], 'effect' => 'allow'],
        ]);

        $decision = $policy->decide('query_db', [], Taintedness::Untrusted);

        $this->assertSame(ToolEffect::Deny, $decision->effect);
        $this->assertSame(ToolPolicyReason::ToolDenied, $decision->reasonCode);
    }

    public function test_model_supplied_identity_is_refused(): void
    {
        $policy = $this->policy([
            'default' => 'deny',
            'rules' => [[
                'tools' => ['get_expenses'],
                'effect' => 'allow',
                'forbid_arguments' => ['user_id', 'tenant_id'],
            ]],
        ]);

        $allowed = $policy->decide('get_expenses', ['month' => '2026-11']);
        $refused = $policy->decide('get_expenses', ['month' => '2026-11', 'user_id' => 99]);

        $this->assertSame(ToolEffect::Allow, $allowed->effect);
        $this->assertSame(ToolEffect::Deny, $refused->effect);
        $this->assertSame(ToolPolicyReason::ArgumentForbidden, $refused->reasonCode);
        $this->assertStringContainsString('user_id', $refused->reason);
    }

    public function test_taint_denies_sensitive_tools(): void
    {
        $policy = $this->policy([
            'default' => 'deny',
            'rules' => [['tools' => ['send_email'], 'effect' => 'allow']],
            'when_tainted' => ['sensitive_tools' => ['send_email'], 'effect' => 'deny'],
        ]);

        $clean = $policy->decide('send_email');
        $tainted = $policy->decide('send_email', [], Taintedness::Untrusted);

        $this->assertSame(ToolEffect::Allow, $clean->effect);
        $this->assertSame(ToolEffect::Deny, $tainted->effect);
        $this->assertSame(ToolPolicyReason::TaintedCapability, $tainted->reasonCode);
    }

    public function test_taint_can_require_approval_instead(): void
    {
        $policy = $this->policy([
            'default' => 'deny',
            'rules' => [['tools' => ['send_email'], 'effect' => 'allow']],
            'when_tainted' => ['sensitive_tools' => ['send_email'], 'effect' => 'approval'],
        ]);

        $decision = $policy->decide('send_email', [], Taintedness::Untrusted);

        $this->assertSame(ToolEffect::Approval, $decision->effect);
        $this->assertSame(ToolPolicyReason::TaintedCapability, $decision->reasonCode);
    }

    public function test_approval_effect_is_reported(): void
    {
        $policy = $this->policy([
            'default' => 'deny',
            'rules' => [['tools' => ['send_email'], 'effect' => 'approval', 'reason' => 'egress']],
        ]);

        $decision = $policy->decide('send_email');

        $this->assertTrue($decision->requiresApproval());
        $this->assertSame('egress', $decision->reason);
    }

    public function test_deny_all_reports_unresolved_policy(): void
    {
        $policy = ToolPolicy::denyAll('missing', ToolPolicyReason::PolicyUnresolved);

        $decision = $policy->decide('anything');

        $this->assertSame(ToolEffect::Deny, $decision->effect);
        $this->assertSame(ToolPolicyReason::PolicyUnresolved, $decision->reasonCode);
    }

    public function test_unknown_effect_string_falls_back_to_deny(): void
    {
        $policy = $this->policy(['default' => 'nonsense', 'rules' => []]);

        $this->assertSame(ToolEffect::Deny, $policy->decide('x')->effect);
    }
}
