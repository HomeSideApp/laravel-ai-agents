<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Jobs;

use HomeSide\AiAgents\Models\AiActionProposal;
use HomeSide\AiAgents\Proposals\ProposalHandlerRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Tenancy;

/**
 * Execution job for accepted proposals with a registered handler.
 *
 * Idempotent: if the proposal is no longer in `accepted` state, this job
 * does nothing.  On failure the proposal is marked `failed` with the
 * error message so it can be inspected and retried later.
 *
 * Tenant-aware: when the host uses stancl/tenancy (database mode), the job
 * stores the tenant key at dispatch time and re-initialises the tenant
 * context before touching the proposal model.
 */
final class ExecuteActionProposalJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $proposalId  The UUID of the proposal to execute.
     * @param  string|null  $tenantKey  The tenant key (database mode).
     */
    public function __construct(
        public readonly string $proposalId,
        public ?string $tenantKey = null,
    ) {}

    /**
     * Number of attempts before giving up (idempotent, so retries are harmless).
     */
    public int $tries = 3;

    /**
     * Backoff in seconds between retries.
     *
     * @var int|array<int>
     */
    public array|int $backoff = 30;

    /**
     * Execute the job.
     *
     * @param  ProposalHandlerRegistry  $handlers  The handler registry.
     */
    public function handle(ProposalHandlerRegistry $handlers): void
    {
        // 1. Restore tenant context if in database mode and tenancy is available.
        $tenantWasResolved = false;

        if ($this->tenantKey !== null && (function_exists('tenancy') || class_exists(Tenancy::class))) {
            try {
                // tenancy() is a global helper shipped by the optional stancl/tenancy
                // package; it is invisible to static analysis unless the host installs
                // it (same justified pattern as Tenancy/StanclTenantRunner.php).
                // @phpstan-ignore-next-line
                tenancy()->initialize($this->tenantKey);
                $tenantWasResolved = true;
            } catch (\Throwable) {
                // Stancl tenancy may not be fully bootstrapped on the queue side.
                // The job will still work in none/column modes.
                $tenantWasResolved = false;
            }
        }

        try {
            // 2. Reload the proposal fresh from DB.
            /** @var class-string<AiActionProposal> $modelClass */
            $modelClass = AiActionProposal::class;
            $proposal = $modelClass::find($this->proposalId);

            if ($proposal === null) {
                return;
            }

            // 3. Idempotent: try atomic transition accepted → executing.
            if (! $proposal->markExecuting()) {
                return;
            }

            // 4. Look up the handler.
            $handler = $handlers->get($proposal->type);

            if ($handler === null) {
                $proposal->markFailed('No handler registered for type ['.$proposal->type.'].');

                return;
            }

            // 5. Execute the handler.
            try {
                $result = $handler->execute($proposal);
                $proposal->markExecuted($result);
            } catch (\Throwable $e) {
                $proposal->markFailed($e->getMessage());
                throw $e; // let queue apply retries/backoff
            }
        } finally {
            // 6. Clean up tenant context.
            if ($tenantWasResolved && (function_exists('tenancy') || class_exists(Tenancy::class))) {
                try {
                    // tenancy() is a global helper shipped by the optional stancl/tenancy
                    // package; it is invisible to static analysis unless the host installs
                    // it (same justified pattern as Tenancy/StanclTenantRunner.php).
                    // @phpstan-ignore-next-line
                    tenancy()->end();
                } catch (\Throwable) {
                    // Best-effort cleanup.
                }
            }
        }
    }

    /**
     * Handle job failure: mark proposal as failed if still executing.
     *
     * @param  \Throwable|null  $e  The original exception.
     */
    public function failed(?\Throwable $e): void
    {
        // Only mark failed if the proposal exists and is still in executing state.
        $proposal = AiActionProposal::find($this->proposalId);

        if ($proposal !== null && $proposal->status === AiActionProposal::STATUS_EXECUTING) {
            $error = $e?->getMessage() ?: 'Job failed after '.$this->tries.' attempts.';
            $proposal->markFailed($error);
        }
    }
}
