<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Tools;

use Illuminate\Support\Str;

final class ToolRule
{
    /**
     * @param  list<string>  $tools  Exact names or glob patterns.
     * @param  list<string>  $forbidArguments  Keys the model must never supply.
     */
    public function __construct(
        public readonly array $tools,
        public readonly ToolEffect $effect,
        public readonly ?string $reason = null,
        public readonly array $forbidArguments = [],
        public readonly ?ToolPolicyReason $reasonCode = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $tools = (array) ($config['tools'] ?? []);

        return new self(
            tools: array_values(array_map(strval(...), $tools)),
            effect: ToolEffect::fromString((string) ($config['effect'] ?? 'deny')),
            reason: isset($config['reason']) ? (string) $config['reason'] : null,
            forbidArguments: array_values(array_map(strval(...), (array) ($config['forbid_arguments'] ?? []))),
        );
    }

    public function matches(string $tool): bool
    {
        foreach ($this->tools as $pattern) {
            if ($pattern === $tool || Str::is($pattern, $tool)) {
                return true;
            }
        }

        return false;
    }
}
