<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Security;

use HomeSide\AiAgents\Security\FirewallFinding;
use HomeSide\AiAgents\Security\Tools\GuardedTool;
use HomeSide\AiAgents\Security\Tools\ToolPolicy;
use HomeSide\AiAgents\Security\Tools\ToolPolicyFindings;
use HomeSide\AiAgents\Tests\TestCase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class FindingsProbeTool implements Tool
{
    public function __construct(private readonly string $toolName) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): Stringable|string
    {
        return 'A findings probe.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'executed';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

final class ToolPolicyFindingsTest extends TestCase
{
    public function test_a_denied_call_records_a_blocking_finding(): void
    {
        $findings = new ToolPolicyFindings;
        $guarded = new GuardedTool(
            new FindingsProbeTool('read_file'),
            ToolPolicy::fromArray('test', ['default' => 'deny', 'rules' => []]),
            findings: $findings,
        );

        $guarded->handle(new Request(['path' => '.env', 'token' => 'secret']));

        $finding = $findings->last();

        $this->assertNotNull($finding);
        $this->assertTrue($finding->blocks());
        $this->assertSame(['tool_not_allowlisted'], $finding->patterns);
        // Signals are a layer => score map, the same shape the content layers emit.
        $this->assertSame(1.0, $finding->signals['tool_policy']);
    }

    public function test_argument_values_never_reach_the_finding_context(): void
    {
        $findings = new ToolPolicyFindings;
        $guarded = new GuardedTool(
            new FindingsProbeTool('read_file'),
            ToolPolicy::fromArray('test', ['default' => 'deny', 'rules' => []]),
            findings: $findings,
        );

        $guarded->handle(new Request(['path' => '.env', 'token' => 'super-secret-value']));

        $serialised = json_encode($findings->entries());

        $this->assertIsString($serialised);
        $this->assertStringNotContainsString('super-secret-value', $serialised);
        $this->assertStringContainsString('argument_keys', $serialised);
    }

    public function test_an_allowed_call_records_nothing(): void
    {
        $findings = new ToolPolicyFindings;
        $guarded = new GuardedTool(
            new FindingsProbeTool('create_recipe'),
            ToolPolicy::fromArray('test', [
                'default' => 'deny',
                'rules' => [['tools' => ['create_recipe'], 'effect' => 'allow']],
            ]),
            findings: $findings,
        );

        $guarded->handle(new Request([]));

        $this->assertSame(0, $findings->count());
    }

    public function test_an_approval_records_a_flag_not_a_block(): void
    {
        $findings = new ToolPolicyFindings;
        $guarded = new GuardedTool(
            new FindingsProbeTool('send_email'),
            ToolPolicy::fromArray('test', [
                'default' => 'deny',
                'rules' => [['tools' => ['send_email'], 'effect' => 'approval', 'reason' => 'egress']],
            ]),
            findings: $findings,
        );

        $guarded->shouldRequestApproval(new Request([]));

        $finding = $findings->last();

        $this->assertNotNull($finding);
        $this->assertTrue($finding->flags());
        $this->assertSame(['sensitive_requires_approval'], $finding->patterns);
    }

    public function test_the_buffer_is_bounded(): void
    {
        $findings = new ToolPolicyFindings(limit: 2);

        foreach (range(1, 3) as $i) {
            $findings->record(new FirewallFinding(FirewallFinding::ACTION_BLOCK, [(string) $i]));
        }

        $this->assertSame(2, $findings->count());
        $this->assertSame(['2'], $findings->findings()[0]->patterns);
    }

    public function test_clearing_empties_the_buffer(): void
    {
        $findings = new ToolPolicyFindings;
        $findings->record(new FirewallFinding(FirewallFinding::ACTION_FLAG));

        $findings->clear();

        $this->assertSame(0, $findings->count());
        $this->assertNull($findings->last());
    }
}
