<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Proposals;

use HomeSide\AiAgents\Events\ActionProposalAccepted;
use HomeSide\AiAgents\Events\ActionProposalCreated;
use HomeSide\AiAgents\Events\ActionProposalExecuted;
use HomeSide\AiAgents\Events\ActionProposalExpired;
use HomeSide\AiAgents\Events\ActionProposalFailed;
use HomeSide\AiAgents\Events\ActionProposalRejected;
use HomeSide\AiAgents\Jobs\ExecuteActionProposalJob;
use HomeSide\AiAgents\Models\AiActionProposal;
use HomeSide\AiAgents\Proposals\ProposalHandlerRegistry;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use HomeSide\AiAgents\Tests\Unit\Fixtures\FakeProposalHandler;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Proposal events: exactly one event per transition.
 */
final class ProposalEventsTest extends TestCase
{
    /**
     * @var list<ActionProposalAccepted>
     */
    protected array $acceptedEvents = [];

    /**
     * @var list<ActionProposalRejected>
     */
    protected array $rejectedEvents = [];

    /**
     * @var list<ActionProposalExpired>
     */
    protected array $expiredEvents = [];

    /**
     * @var list<ActionProposalExecuted>
     */
    protected array $executedEvents = [];

    /**
     * @var list<ActionProposalFailed>
     */
    protected array $failedEvents = [];

    /**
     * @var list<ActionProposalCreated>
     */
    protected array $createdEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Use global listeners instead of Event::fake() for events fired
        // from model booted() hooks, which Orchestra Testbench does not
        // capture with EventFake in Laravel 12.
        Event::listen(function (ActionProposalAccepted $e): void {
            $this->acceptedEvents[] = $e;
        });
        Event::listen(function (ActionProposalRejected $e): void {
            $this->rejectedEvents[] = $e;
        });
        Event::listen(function (ActionProposalExpired $e): void {
            $this->expiredEvents[] = $e;
        });
        Event::listen(function (ActionProposalExecuted $e): void {
            $this->executedEvents[] = $e;
        });
        Event::listen(function (ActionProposalFailed $e): void {
            $this->failedEvents[] = $e;
        });
        Event::listen(function (ActionProposalCreated $e): void {
            $this->createdEvents[] = $e;
        });
    }

    /**
     * Clear event accumulators between tests.
     */
    protected function tearDown(): void
    {
        $this->acceptedEvents = [];
        $this->rejectedEvents = [];
        $this->expiredEvents = [];
        $this->executedEvents = [];
        $this->failedEvents = [];
        $this->createdEvents = [];

        parent::tearDown();
    }

    private function makeUser(string $email = 'test@example.com'): TestUser
    {
        return TestUser::create(['name' => 'Test User', 'email' => $email]);
    }

    /**
     * accept() fires ActionProposalAccepted exactly once with correct deciderId.
     */
    public function test_creation_fires_created_event_exactly_once(): void
    {
        $user = $this->makeUser();

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        $this->assertNotNull($proposal->id);
        $this->assertCount(1, $this->createdEvents);
        $this->assertSame($proposal->id, $this->createdEvents[0]->proposal->id);
    }

    /**
     * accept() fires ActionProposalAccepted exactly once with correct deciderId.
     */
    public function test_accept_fires_accepted_event(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        $accepted = $proposal->accept($user->id);
        $this->assertTrue($accepted);

        $this->assertCount(1, $this->acceptedEvents);
        $this->assertSame($proposal->id, $this->acceptedEvents[0]->proposal->id);
        // Event deciderId preserves the original type; use assertEquals for
        // int|string compatibility.
        $this->assertEquals($user->id, $this->acceptedEvents[0]->deciderId);
    }

    /**
     * accept() without deciderId fires ActionProposalAccepted with null.
     */
    public function test_accept_without_decider_id_fires_event_with_null(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        $accepted = $proposal->accept();
        $this->assertTrue($accepted);

        $this->assertCount(1, $this->acceptedEvents);
        $this->assertNull($this->acceptedEvents[0]->deciderId);
    }

    /**
     * reject() fires ActionProposalRejected exactly once.
     */
    public function test_reject_fires_rejected_event(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        $rejected = $proposal->reject($user->id);
        $this->assertTrue($rejected);

        $this->assertCount(1, $this->rejectedEvents);
        $this->assertSame($proposal->id, $this->rejectedEvents[0]->proposal->id);
        $this->assertEquals($user->id, $this->rejectedEvents[0]->deciderId);
    }

    /**
     * reject() without deciderId fires ActionProposalRejected with null.
     */
    public function test_reject_without_decider_id_fires_event_with_null(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        $rejected = $proposal->reject();
        $this->assertTrue($rejected);

        $this->assertCount(1, $this->rejectedEvents);
        $this->assertNull($this->rejectedEvents[0]->deciderId);
    }

    /**
     * Failed transitions (already decided) do NOT fire event.
     */
    public function test_failed_transition_does_not_fire_event(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        $proposal->accept($user->id);
        $count1 = count($this->acceptedEvents);

        // Second accept should return false and NOT fire another event.
        $second = $proposal->accept($user->id);
        $this->assertFalse($second);

        $this->assertCount($count1, $this->acceptedEvents); // No new event.
    }

    /**
     * Expire with expired proposal fires ActionProposalExpired exactly once.
     */
    public function test_expire_stale_fires_expired_event(): void
    {
        $user = $this->makeUser();
        $expired = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'a'],
            'expires_at' => now()->subHour(),
        ]);
        AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'b'],
            'expires_at' => now()->addHour(),
        ]);
        AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'c'],
            'status' => 'accepted',
        ]);

        $count = AiActionProposal::expireStale();
        $this->assertSame(1, $count);

        $this->assertCount(1, $this->expiredEvents);
        $this->assertSame($expired->id, $this->expiredEvents[0]->proposal->id);
    }

    /**
     * expireStale with no expired proposals fires no events.
     */
    public function test_expire_stale_no_expired_fires_no_events(): void
    {
        $user = $this->makeUser();
        AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
            'expires_at' => now()->addHour(),
        ]);

        $count = AiActionProposal::expireStale();
        $this->assertSame(0, $count);

        $this->assertCount(0, $this->expiredEvents);
    }

    /**
     * markExecuted fires ActionProposalExecuted.
     */
    public function test_mark_executed_fires_executed_event(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);
        $proposal->accept($user->id);
        $proposal->markExecuting();

        $result = ['status' => 'done', 'count' => 5];
        $proposal->markExecuted($result);

        $this->assertCount(1, $this->executedEvents);
        $this->assertSame($result, $this->executedEvents[0]->result);
    }

    /**
     * markFailed fires ActionProposalFailed.
     */
    public function test_mark_failed_fires_failed_event(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);
        $proposal->accept($user->id);
        $proposal->markExecuting();

        $proposal->markFailed('Something went wrong');

        $this->assertCount(1, $this->failedEvents);
        $this->assertSame('Something went wrong', $this->failedEvents[0]->error);
    }

    /**
     * Multiple proposals: expireStale fires one event per expired proposal.
     */
    public function test_expire_stale_multiple_proposals_fires_multiple_events(): void
    {
        $user = $this->makeUser();
        AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'a'],
            'expires_at' => now()->subHour(),
        ]);
        AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'b'],
            'expires_at' => now()->subHour(),
        ]);
        AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'c'],
            'expires_at' => now()->addHour(),
        ]);

        $count = AiActionProposal::expireStale();
        $this->assertSame(2, $count);

        $this->assertCount(2, $this->expiredEvents);
    }

    /**
     * execute=none: markExecuted/failed still fire events via the job path
     * when the job is dispatched directly.
     */
    public function test_job_execution_fires_events(): void
    {
        Config::set('ai-agents.proposals.execute', 'none');

        $user = $this->makeUser();
        $handler = new FakeProposalHandler(
            type: 'job_exec_type',
            result: ['done' => true],
        );

        $registry = app(ProposalHandlerRegistry::class);
        $registry->register($handler);

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'job_exec_type',
            'payload' => ['key' => 'value'],
        ]);

        $proposal->accept($user->id);
        Queue::fake();

        $job = new ExecuteActionProposalJob($proposal->id);
        $job->handle($registry);

        $this->assertCount(1, $this->executedEvents);
    }

    /**
     * Job execution with handler exception: ActionProposalFailed fired.
     */
    public function test_job_exception_fires_failed_event(): void
    {
        Config::set('ai-agents.proposals.execute', 'none');

        $user = $this->makeUser();
        $handler = new FakeProposalHandler(
            type: 'job_fail_type',
            result: ['ok' => true],
        );
        $handler->shouldThrow = true;
        $handler->throwMessage = 'Job execution error';

        $registry = app(ProposalHandlerRegistry::class);
        $registry->register($handler);

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'job_fail_type',
            'payload' => ['key' => 'value'],
        ]);

        $proposal->accept($user->id);
        Queue::fake();

        $job = new ExecuteActionProposalJob($proposal->id);

        try {
            $job->handle($registry);
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertCount(1, $this->failedEvents);
        $this->assertSame('Job execution error', $this->failedEvents[0]->error);
    }
}
