<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Tools;

/**
 * What the policy decided a tool call may do.
 */
enum ToolEffect: string
{
    case Allow = 'allow';

    case Deny = 'deny';

    case Approval = 'approval';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Deny;
    }
}
