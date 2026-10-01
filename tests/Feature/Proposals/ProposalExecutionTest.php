<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Proposals;

use HomeSide\AiAgents\Jobs\ExecuteActionProposalJob;
use HomeSide\AiAgents\Models\AiActionProposal;
use HomeSide\AiAgents\Proposals\ProposalDecisions;
use HomeSide\AiAgents\Proposals\ProposalHandlerRegistry;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use HomeSide\AiAgents\Tests\Unit\Fixtures\FakeProposalHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

/**
 * Proposal execution: sync/queue/none modes, idempotency, handler exceptions.
 */
final class ProposalExecutionTest extends TestCase
{
    private function makeUser(string $email = 'test@example.com'): TestUser
    {
        return TestUser::create(['name' => 'Test User', 'email' => $email]);
    }

    /**
     * Sync mode: handler execute → proposal goes accepted → executing → executed.
     */
    public function test_sync_execution_succeeds(): void
    {
        Config::set('ai-agents.proposals.execute', 'sync');

        $user = $this->makeUser();
        $handler = new FakeProposalHandler(
            type: 'sync_type',
            result: ['status' => 'done', 'count' => 42],
        );

        $registry = app(ProposalHandlerRegistry::class);
        $registry->register($handler);

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'sync_type',
            'payload' => ['key' => 'value'],
        ]);

        $result = ProposalDecisions::instance()->decide(
            $proposal,
            (string) $user->id,
            ProposalDecisions::DECISION_ACCEPT,
        );
        $this->assertTrue($result);

        $this->assertSame('executed', $proposal->refresh()->status);
        $this->assertSame(['status' => 'done', 'count' => 42], $proposal->execution_result);
        $this->assertNotNull($proposal->executed_at);
        $this->assertNull($proposal->execution_error);
        $this->assertSame(1, $handler->executeCount);
    }

    /**
     * Handler exception: proposal goes to failed, error stored, exception re-thrown.
     */
    public function test_handler_exception_marks_failed(): void
    {
        Config::set('ai-agents.proposals.execute', 'sync');

        $user = $this->makeUser();
        $handler = new FakeProposalHandler(
            type: 'fail_type',
            result: ['ok' => true],
        );
        $handler->shouldThrow = true;
        $handler->throwMessage = 'Something went wrong';

        $registry = app(ProposalHandlerRegistry::class);
        $registry->register($handler);

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'fail_type',
            'payload' => ['key' => 'value'],
        ]);

        try {
            ProposalDecisions::instance()->decide(
                $proposal,
                (string) $user->id,
                ProposalDecisions::DECISION_ACCEPT,
            );
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame('failed', $proposal->refresh()->status);
        $this->assertSame('Something went wrong', $proposal->execution_error);
        $this->assertNotNull($proposal->executed_at);
    }

    /**
     * Idempotency: dispatching the job twice only executes the handler once.
     */
    public function test_job_idempotency(): void
    {
        Config::set('ai-agents.proposals.execute', 'none');

        $user = $this->makeUser();
        $handler = new FakeProposalHandler(
            type: 'idempotent_type',
            result: ['done' => true],
        );

        $registry = app(ProposalHandlerRegistry::class);
        $registry->register($handler);

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'idempotent_type',
            'payload' => ['key' => 'value'],
        ]);

        // Accept the proposal (no execution because execute=none).
        $result = ProposalDecisions::instance()->decide(
            $proposal,
            (string) $user->id,
            ProposalDecisions::DECISION_ACCEPT,
        );
        $this->assertTrue($result);
        $this->assertSame('accepted', $proposal->refresh()->status);

        // Dispatch the job directly (simulates queue).
        Queue::fake();

        $job = new ExecuteActionProposalJob($proposal->id);
        $job->handle($registry);

        $this->assertSame('executed', $proposal->refresh()->status);
        $this->assertSame(1, $handler->executeCount);

        // Dispatch again: idempotent, handler NOT called again.
        $job2 = new ExecuteActionProposalJob($proposal->id);
        $job2->handle($registry);

        $this->assertSame('executed', $proposal->refresh()->status);
        $this->assertSame(1, $handler->executeCount); // Still 1.
    }

    /**
     * No handler registered: proposal stays accepted, no job dispatched.
     */
    public function test_no_handler_stays_accepted(): void
    {
        Config::set('ai-agents.proposals.execute', 'queue');
        Bus::fake();

        $user = $this->makeUser();

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'no_handler_type',
            'payload' => ['key' => 'value'],
        ]);

        // Accept directly (no handler registered for this type).
        $result = $proposal->accept($user->id);
        $this->assertTrue($result);
        $this->assertSame('accepted', $proposal->refresh()->status);

        // No job should be dispatched because there's no handler.
        Bus::assertNothingDispatched();
    }

    /**
     * execute = none: even with a handler, no job is dispatched and proposal stays accepted.
     */
    public function test_execute_none_no_job_dispatched(): void
    {
        Config::set('ai-agents.proposals.execute', 'none');
        Bus::fake();

        $user = $this->makeUser();
        $handler = new FakeProposalHandler(
            type: 'none_type',
            result: ['done' => true],
        );

        $registry = app(ProposalHandlerRegistry::class);
        $registry->register($handler);

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'none_type',
            'payload' => ['key' => 'value'],
        ]);

        $result = ProposalDecisions::instance()->decide(
            $proposal,
            (string) $user->id,
            ProposalDecisions::DECISION_ACCEPT,
        );
        $this->assertTrue($result);
        $this->assertSame('accepted', $proposal->refresh()->status);
        $this->assertNull($proposal->refresh()->execution_result);

        Bus::assertNothingDispatched();
    }

    /**
     * execute = sync with handler: job executes synchronously.
     */
    public function test_execute_sync_runs_immediately(): void
    {
        Config::set('ai-agents.proposals.execute', 'sync');

        $user = $this->makeUser();
        $handler = new FakeProposalHandler(
            type: 'sync_immediate',
            result: ['executed' => true],
        );

        $registry = app(ProposalHandlerRegistry::class);
        $registry->register($handler);

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'sync_immediate',
            'payload' => ['key' => 'value'],
        ]);

        $result = ProposalDecisions::instance()->decide(
            $proposal,
            (string) $user->id,
            ProposalDecisions::DECISION_ACCEPT,
        );
        $this->assertTrue($result);

        $this->assertSame('executed', $proposal->refresh()->status);
        $this->assertSame(1, $handler->executeCount);
    }

    /**
     * Job with no handler registered for type: marks as failed.
     */
    public function test_job_no_handler_marks_failed(): void
    {
        $user = $this->makeUser();
        $handler = new FakeProposalHandler(type: 'registered_type');
        $registry = app(ProposalHandlerRegistry::class);
        $registry->register($handler);

        // Create a proposal with a type that has NO handler.
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'unregistered_type',
            'payload' => ['key' => 'value'],
        ]);

        // Manually set to accepted (normally done by decide with handler).
        $accepted = $proposal->accept($user->id);
        $this->assertTrue($accepted);

        // Now dispatch the job directly.
        Queue::fake();
        $job = new ExecuteActionProposalJob($proposal->id);
        $job->handle($registry);

        $this->assertSame('failed', $proposal->refresh()->status);
        $this->assertStringContainsString('No handler', $proposal->execution_error);
    }

    /**
     * execute = queue (default): ProposalDecisions dispatches
     * ExecuteActionProposalJob onto the queue when a handler is registered.
     */
    public function test_execute_queue_dispatches_job(): void
    {
        Config::set('ai-agents.proposals.execute', 'queue');
        Queue::fake();

        $user = $this->makeUser();
        $handler = new FakeProposalHandler(
            type: 'queued_type',
            result: ['queued' => true],
        );

        $registry = app(ProposalHandlerRegistry::class);
        $registry->register($handler);

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'queued_type',
            'payload' => ['key' => 'value'],
        ]);

        $result = ProposalDecisions::instance()->decide(
            $proposal,
            (string) $user->id,
            ProposalDecisions::DECISION_ACCEPT,
        );

        $this->assertTrue($result);
        $this->assertSame('accepted', $proposal->refresh()->status);

        Queue::assertPushed(
            ExecuteActionProposalJob::class,
            fn (ExecuteActionProposalJob $job): bool => $job->proposalId === $proposal->id,
        );
    }

    /**
     * Job idempotency via dispatchSync: second call does not re-execute.
     *
     * We invoke handle() directly to avoid Bus::dispatchSync quirks in
     * Orchestra Testbench — the job's queue handling is tested elsewhere.
     */
    public function test_dispatch_sync_idempotency(): void
    {
        Config::set('ai-agents.proposals.execute', 'none');

        $user = $this->makeUser();
        $handler = new FakeProposalHandler(
            type: 'dispatch_sync_type',
            result: ['done' => true],
        );

        $registry = app(ProposalHandlerRegistry::class);
        $registry->register($handler);

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'dispatch_sync_type',
            'payload' => ['key' => 'value'],
        ]);

        // Accept without execution.
        $proposal->accept($user->id);

        Queue::fake();

        // First handle(): marks accepted → executing → executed.
        $job = new ExecuteActionProposalJob($proposal->id);
        $job->handle($registry);
        $this->assertSame('executed', $proposal->refresh()->status);
        $this->assertSame(1, $handler->executeCount);

        // Second handle(): proposal no longer accepted, no re-execution.
        $job2 = new ExecuteActionProposalJob($proposal->id);
        $job2->handle($registry);
        $this->assertSame('executed', $proposal->refresh()->status);
        $this->assertSame(1, $handler->executeCount);
    }
}
