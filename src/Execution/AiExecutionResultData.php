<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Execution;

/**
 * Result of an agent execution.
 *
 * Contains the agent's reply, usage metadata and tool calls.
 */
final readonly class AiExecutionResultData
{
    /**
     * @param  string  $runId  The unique execution id.
     * @param  string  $agent  The key of the executed agent.
     * @param  int  $agentVersion  The agent version.
     * @param  string  $provider  The registered provider name.
     * @param  string  $model  The model used.
     * @param  string  $status  'ok' | 'error' | 'partial'.
     * @param  string  $reply  The agent reply (plain text or JSON).
     * @param  AiUsageData  $usage  Usage data (tokens, latency, cost).
     * @param  bool  $structured  Whether the reply is structured output.
     * @param  list<array<string, mixed>>  $toolCalls  Executed tool calls.
     * @param  array<string, mixed>  $metadata  Additional metadata.
     */
    public function __construct(
        public string $runId,
        public string $agent,
        public int $agentVersion,
        public string $provider,
        public string $model,
        public string $status,
        public string $reply,
        public AiUsageData $usage,
        public bool $structured = false,
        /** @var list<array<string, mixed>> */
        public array $toolCalls = [],
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {}

    /**
     * Serialize to an array for logging/API.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'agent' => $this->agent,
            'agent_version' => $this->agentVersion,
            'provider' => $this->provider,
            'model' => $this->model,
            'status' => $this->status,
            'reply' => $this->reply,
            'usage' => $this->usage->toArray(),
            'structured' => $this->structured,
            'tool_calls' => $this->toolCalls,
            'metadata' => $this->metadata,
        ];
    }
}
