<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Execution;

use HomeSide\AiAgents\Contracts\ResolvesTenant;
use HomeSide\AiAgents\Enums\ContentMode;
use HomeSide\AiAgents\Enums\PrivacyLevel;
use HomeSide\AiAgents\Enums\TenantIsolation;
use HomeSide\AiAgents\Models\AiExecutionAttempt;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiRun;
use HomeSide\AiAgents\Models\AiToolCall;

/**
 * Records agent executions in the database.
 *
 * Persists ai_runs, ai_execution_attempts and ai_tool_calls. Free-text
 * content (user_message, reply) passes through RunContentRedactor so the
 * host can disable or anonymise conversation storage per provider privacy
 * level.
 */
class ExecutionRecorder
{
    public function __construct(
        private readonly RunContentRedactor $redactor,
    ) {}

    public function queueRun(AiExecutionContextData $context, string $agentKey, string $userMessage): AiRun
    {
        $run = $this->startRun($context, $agentKey, $userMessage);
        $run->update(['status' => 'queued']);

        return $run;
    }

    public function applyRetention(AiRun $run, PrivacyLevel $privacyLevel): void
    {
        $mode = $this->redactor->resolveMode($privacyLevel);

        if ($run->content_mode === $mode->value) {
            return;
        }

        $message = $run->user_message;
        $run->content_mode = $mode->value;
        $run->user_message = $this->redactor->retainWithMode($message, $mode);
        $run->save();
    }

    /**
     * Start a new run and return the AiRun model.
     *
     * The tenant column is only written when tenant support is enabled; the
     * column itself only exists in that case. The user message passes
     * through RunContentRedactor with the resolved provider's privacy
     * level, so high-privacy setups may store a digest or nothing.
     *
     * @param  string|null  $userMessage  The user message (for conversation history).
     * @param  array{layer_hashes?: array<string, string>, guardrail_warnings?: string[], prompt_version?: int}|null  $metadata  Prompt compositor metadata.
     */
    public function startRun(
        AiExecutionContextData $context,
        string $agentKey,
        ?string $userMessage = null,
        ?array $metadata = null,
        ?PrivacyLevel $privacyLevel = null,
        ?bool $userConsented = null,
    ): AiRun {
        /** @var ResolvesTenant $tenantResolver */
        $tenantResolver = app(ResolvesTenant::class);

        $level = $privacyLevel ?? PrivacyLevel::Unknown;
        $mode = $this->redactor->resolveMode($level, $userConsented ?? false);

        $attributes = [
            'user_id' => $context->userId,
            'conversation_id' => $context->conversationId,
            'agent' => $agentKey,
            'agent_version' => 1,
            'status' => 'running',
            'duration_ms' => 0,
            'content_mode' => $mode->value,
            'user_message' => $this->redactor->retainWithMode($userMessage, $mode),
            'metadata' => $metadata,
        ];

        // Write tenant FK column only in column mode.  In database mode the
        // column does not exist; instead store the key in metadata so
        // analytics can still group runs by tenant.
        if ($tenantResolver->isolation() === TenantIsolation::Column && $context->tenantId !== null) {
            $attributes[$tenantResolver->foreignKey()] = $context->tenantId;
        } elseif ($tenantResolver->isolation() === TenantIsolation::Database && $context->tenantId !== null) {
            $metadata = $metadata ?? [];
            $metadata['tenant_key'] = $context->tenantId;
            $attributes['metadata'] = $metadata;
        }

        return AiRun::create($attributes);
    }

    /**
     * Persist the successful outcome of a run onto its AiRun row.
     *
     * Updates the provider/model attribution, token accounting, duration,
     * final status and the reply text. The reply passes through
     * RunContentRedactor with the provider's privacy level. Token values of
     * 0 are stored as null (unknown) rather than misleading zeros.
     *
     * The estimated cost is a snapshot: computed with the provider's
     * CURRENT pricing at result time, so later catalog price changes never
     * rewrite economical history.
     *
     * @param  AiRun  $run  The run row to update.
     * @param  AiExecutionResultData  $result  The normalised execution result.
     */
    public function recordResult(
        AiRun $run,
        AiExecutionResultData $result,
        ?PrivacyLevel $privacyLevel = null,
    ): void {
        // Reuse the mode decided at startRun so message and reply are
        // stored with the same policy (and the same user key).
        $mode = ContentMode::fromColumn($run->content_mode);

        $run->fill([
            'provider_name' => $result->provider,
            'model_name' => $result->model,
            'input_tokens' => $result->usage->inputTokens,
            'output_tokens' => $result->usage->outputTokens,
            'cached_tokens' => $result->usage->cachedTokens,
            'duration_ms' => $result->usage->latencyMs,
            'status' => $result->status,
            'reply' => $this->redactor->retainWithMode($result->reply, $mode),
        ]);
        $run->estimated_cost = $this->estimateCost($run);
        $run->save();
    }

    /**
     * Snapshot the run's estimated cost from its provider's pricing.
     *
     * Best-effort: providers without pricing (or runs without a stored
     * provider_id) keep estimated_cost null instead of a misleading zero.
     */
    private function estimateCost(AiRun $run): ?string
    {
        if ($run->provider_id === null) {
            return null;
        }

        /** @var AiProvider|null $provider */
        $provider = AiProvider::query()
            ->where('id', $run->provider_id)
            ->first(['cost_input', 'cost_output', 'cost_cache_read']);

        if ($provider === null) {
            return null;
        }

        $estimate = app(CostEstimator::class)->estimateFor(
            $run->input_tokens,
            $run->output_tokens,
            $run->cached_tokens,
            $provider->cost_input,
            $provider->cost_output,
            $provider->cost_cache_read,
        );

        return $estimate === null ? null : number_format($estimate, 6, '.', '');
    }

    /**
     * Mark a run as failed with a machine-readable error code.
     *
     * Sets status to 'error' and stores the code (e.g. 'execution_failed');
     * the human-readable message stays wherever the caller reported it.
     *
     * @param  AiRun  $run  The run row to update.
     * @param  string  $error  Short error code persisted in error_code.
     */
    public function recordError(
        AiRun $run,
        string $error,
    ): void {
        $run->update([
            'status' => 'error',
            'error_code' => $error,
        ]);
    }

    /**
     * Record one attempt of a run (for failover/retry tracking).
     *
     * Each attempt is an append-only row so the history of providers tried
     * within a run stays auditable.
     *
     * @param  AiRun  $run  The run the attempt belongs to.
     * @param  int  $attemptNumber  1-based ordinal position within the run.
     * @param  string  $provider  Provider name (dynamic SDK name) used.
     * @param  string  $model  Model identifier used for this attempt.
     * @param  string  $status  Attempt outcome (e.g. 'ok', 'error').
     * @param  int  $latencyMs  Wall-clock duration of the attempt in ms.
     * @param  string|null  $error  Optional error code when the attempt failed.
     * @return AiExecutionAttempt The created attempt row.
     */
    public function recordAttempt(
        AiRun $run,
        int $attemptNumber,
        string $provider,
        string $model,
        string $status,
        int $latencyMs,
        ?string $error = null,
    ): AiExecutionAttempt {
        return AiExecutionAttempt::create([
            'ai_run_id' => $run->id,
            'attempt_order' => $attemptNumber,
            'model_name' => $model,
            'status' => $status,
            'error_code' => $error,
            'duration_ms' => $latencyMs,
        ]);
    }

    /**
     * Record a single tool invocation made during a run.
     *
     * @param  AiRun  $run  The run the tool call belongs to.
     * @param  string  $tool  The tool identifier as invoked by the model.
     * @param  string  $status  Call outcome (e.g. 'ok', 'error').
     * @param  int|null  $durationMs  Wall-clock duration of the call in ms,
     *                                null when not measured.
     * @param  string|null  $error  Optional error code when the call failed.
     * @return AiToolCall The created tool call row.
     */
    public function recordToolCall(
        AiRun $run,
        string $tool,
        string $status,
        ?int $durationMs = null,
        ?string $error = null,
    ): AiToolCall {
        return AiToolCall::create([
            'ai_run_id' => $run->id,
            'tool' => $tool,
            'duration_ms' => $durationMs,
            'status' => $status,
            'error_code' => $error,
        ]);
    }
}
