<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Tools;

final class ToolDecision
{
    private function __construct(
        public readonly ToolEffect $effect,
        public readonly ToolPolicyReason $reasonCode,
        public readonly string $reason,
    ) {}

    public static function allow(): self
    {
        return new self(ToolEffect::Allow, ToolPolicyReason::ToolNotAllowlisted, 'Allowed by tool policy.');
    }

    public static function deny(ToolPolicyReason $code, string $reason): self
    {
        return new self(ToolEffect::Deny, $code, $reason);
    }

    public static function approval(ToolPolicyReason $code, string $reason): self
    {
        return new self(ToolEffect::Approval, $code, $reason);
    }

    public function blocks(): bool
    {
        return $this->effect === ToolEffect::Deny;
    }

    public function requiresApproval(): bool
    {
        return $this->effect === ToolEffect::Approval;
    }

    /**
     * The refusal handed back to the model as the tool result.
     *
     * It states the reason code so a run can be debugged from the transcript,
     * and tells the model not to retry, because a model that keeps calling a
     * denied tool burns tokens without changing the outcome.
     */
    public function refusal(string $tool): string
    {
        return "Tool [{$tool}] was blocked by policy [{$this->reasonCode->value}]: {$this->reason} "
            .'Do not retry this call; tell the user what is missing.';
    }
}
