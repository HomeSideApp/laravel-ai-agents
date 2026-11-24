<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Tools;

/**
 * Stable reason codes shared by logs, tests and (later) firewall findings.
 */
enum ToolPolicyReason: string
{
    case ToolNotAllowlisted = 'tool_not_allowlisted';

    case ToolDenied = 'tool_denied';

    case ArgumentForbidden = 'argument_forbidden';

    case TaintedCapability = 'tainted_capability';

    case SensitiveRequiresApproval = 'sensitive_requires_approval';

    case PolicyUnresolved = 'policy_unresolved';

    case PolicyEvaluationError = 'policy_evaluation_error';
}
