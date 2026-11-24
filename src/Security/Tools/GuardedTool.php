<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Tools;

use HomeSide\AiAgents\Security\Taint\TaintScope;
use HomeSide\AiAgents\Security\Taint\TaintStamp;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Stringable;

/**
 * Decorates a tool so every call passes the policy first.
 *
 * The interception point is the tool itself, not the generation middleware: a
 * generation step cannot see arguments, so it cannot veto one invocation or
 * stop the model from choosing whose data it touches. Denials come back as the
 * tool result, approvals reuse the SDK's native pause so the package's durable
 * proposal bridge still owns the human decision.
 */
final class GuardedTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    private ?ToolDecision $lastDecision = null;

    /**
     * @param  array<string, mixed>  $bindings  Host-supplied values that override the model's.
     */
    /**
     * @param  array<string, mixed>  $bindings  Host-supplied values that override the model's.
     * @param  list<string>  $untrustedToolPatterns  Tools whose output is untrusted content;
     *                                               running one taints the rest of the run.
     */
    public function __construct(
        public readonly Tool $inner,
        public readonly ToolPolicy $policy,
        private readonly Taintedness $tainted = Taintedness::Clean,
        private readonly array $bindings = [],
        private readonly ?TaintScope $scope = null,
        private readonly array $untrustedToolPatterns = [],
    ) {}

    /**
     * Wraps the tools an agent declares, when the feature is enabled.
     *
     * Auto-wrapping is deliberate: a policy that silently fails to apply is
     * indistinguishable from one that works, so enabling the feature wraps
     * everything the agent returns.
     *
     * @param  iterable<mixed>  $tools
     * @param  array<string, mixed>  $bindings
     * @return list<mixed>
     */
    public static function guard(
        object $agent,
        iterable $tools,
        Taintedness $tainted = Taintedness::Clean,
        array $bindings = [],
        ?TaintScope $scope = null,
    ): array {
        $list = is_array($tools) ? array_values($tools) : iterator_to_array($tools, false);

        $resolver = app(ToolPolicyResolver::class);

        if (! $resolver->enabled()) {
            return $list;
        }

        $policy = $resolver->resolveOrDenyAll($agent);

        $untrustedTools = (array) config('ai-agents.firewall.tools.taint.untrusted_tools', []);
        $untrustedTools = array_values(array_map(strval(...), $untrustedTools));

        return array_map(
            static fn (mixed $tool): mixed => $tool instanceof Tool
                ? new self($tool, $policy, $tainted, $bindings, $scope, $untrustedTools)
                : $tool,
            $list,
        );
    }

    /**
     * The inner tool's name, so policy patterns match what the model saw
     * instead of this decorator's class name.
     */
    public function name(): string
    {
        return ToolNameResolver::resolve($this->inner);
    }

    public function description(): Stringable|string
    {
        return $this->inner->description();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->inner->schema($schema);
    }

    public function handle(Request $request): Stringable|string
    {
        $decision = $this->decide($request);

        if ($decision->blocks()) {
            Log::warning('Tool call blocked by policy.', [
                'tool' => $this->name(),
                'policy' => $this->policy->name,
                'reason' => $decision->reasonCode->value,
                // Never the values: arguments routinely carry personal data and
                // the log must not become the leak.
                'argument_keys' => count($request->all()),
            ]);

            return $decision->refusal($this->name());
        }

        $result = $this->inner->handle($this->bind($request));

        // A tool that returns untrusted content taints the rest of the run, so a
        // later call cannot exfiltrate what this one just read.
        if ($this->scope !== null && $this->returnsUntrustedContent()) {
            $this->scope->mark(TaintStamp::tool($this->name()));
        }

        return $result;
    }

    public function shouldRequestApproval(Request $request): ?Approval
    {
        $decision = $this->decide($request);

        return $decision->requiresApproval()
            ? Approval::required($decision->reason)
            : null;
    }

    public function lastDecision(): ?ToolDecision
    {
        return $this->lastDecision;
    }

    private function decide(Request $request): ToolDecision
    {
        try {
            return $this->lastDecision = $this->policy->decide(
                $this->name(),
                $request->all(),
                $this->currentTaint(),
            );
        } catch (\Throwable $exception) {
            // A broken policy must fail closed and must not surface as an
            // exception into the model loop.
            Log::error('Tool policy evaluation failed; denying the call.', [
                'tool' => $this->name(),
                'policy' => $this->policy->name,
                'exception' => $exception::class,
            ]);

            return $this->lastDecision = ToolDecision::deny(
                ToolPolicyReason::PolicyEvaluationError,
                'The tool policy could not be evaluated.',
            );
        }
    }

    /**
     * Taint is read at call time, not at wrap time: a tool earlier in the same
     * run can turn a clean run untrusted, and the decision has to see that.
     */
    private function currentTaint(): Taintedness
    {
        if ($this->scope !== null && $this->scope->isTainted()) {
            return Taintedness::Untrusted;
        }

        return $this->tainted;
    }

    private function returnsUntrustedContent(): bool
    {
        foreach ($this->untrustedToolPatterns as $pattern) {
            if ($pattern === $this->name() || Str::is($pattern, $this->name())) {
                return true;
            }
        }

        return false;
    }

    private function bind(Request $request): Request
    {
        if ($this->bindings === []) {
            return $request;
        }

        return new Request(
            array_merge($request->all(), $this->bindings),
            $request->toolCallId(),
            $request->toolInvocationId(),
        );
    }
}
