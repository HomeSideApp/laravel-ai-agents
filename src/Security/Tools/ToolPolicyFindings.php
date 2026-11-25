<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Tools;

use HomeSide\AiAgents\Security\FirewallFinding;

/**
 * Collects the findings produced by tool-policy decisions.
 *
 * The content firewall returns a finding to its caller; a tool call has no
 * return channel for one, so denials and approvals are recorded here and the
 * host decides where they go — an audit log, a dashboard, a test assertion.
 * Bounded in memory because the primary sink is the host's, not this buffer.
 */
final class ToolPolicyFindings
{
    /** @var list<array{finding: FirewallFinding, context: array<string, mixed>}> */
    private array $entries = [];

    public function __construct(private readonly int $limit = 200) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(FirewallFinding $finding, array $context = []): void
    {
        if (count($this->entries) >= $this->limit) {
            array_shift($this->entries);
        }

        $this->entries[] = ['finding' => $finding, 'context' => $context];
    }

    /** @return list<FirewallFinding> */
    public function findings(): array
    {
        return array_map(static fn (array $entry): FirewallFinding => $entry['finding'], $this->entries);
    }

    /** @return list<array{finding: FirewallFinding, context: array<string, mixed>}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function last(): ?FirewallFinding
    {
        return $this->entries === [] ? null : $this->entries[array_key_last($this->entries)]['finding'];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
