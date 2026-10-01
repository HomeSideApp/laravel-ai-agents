<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Proposals;

use HomeSide\AiAgents\Contracts\RunsForEachTenant;
use HomeSide\AiAgents\Events\ActionProposalExpired;
use HomeSide\AiAgents\Models\AiActionProposal;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use HomeSide\AiAgents\Tests\Unit\Fixtures\FakeTenantRunner;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;

/**
 * ExpireProposalsCommand: multi-tenant runner, artisan invocation.
 */
final class ExpireProposalsCommandTest extends TestCase
{
    private function makeUser(string $email = 'test@example.com'): TestUser
    {
        return TestUser::create(['name' => 'Test User', 'email' => $email]);
    }

    /**
     * Command runs successfully with default SingleContextRunner.
     */
    public function test_command_runs_successfully(): void
    {
        // Reset to default SingleContextRunner.
        Config::set('ai-agents.tenant.runner', null);
        if (app()->resolved(RunsForEachTenant::class)) {
            app()->forgetInstance(RunsForEachTenant::class);
        }

        $output = $this->artisan('ai-agents:proposals:expire');
        $output->assertSuccessful();
    }

    /**
     * Command expires stale proposals when the runner callback runs expireStale().
     */
    public function test_command_expires_stale_proposals(): void
    {
        Event::fake();

        $user = $this->makeUser();

        // Create expired proposals.
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
        AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'd'],
            'status' => 'accepted',
        ]);

        // Reset to default SingleContextRunner.
        Config::set('ai-agents.tenant.runner', null);
        if (app()->resolved(RunsForEachTenant::class)) {
            app()->forgetInstance(RunsForEachTenant::class);
        }

        $this->artisan('ai-agents:proposals:expire')->assertSuccessful();

        // Two should be expired, two should not.
        $aProposal = AiActionProposal::where('payload->key', 'a')->first();
        $bProposal = AiActionProposal::where('payload->key', 'b')->first();
        $cProposal = AiActionProposal::where('payload->key', 'c')->first();
        $dProposal = AiActionProposal::where('payload->key', 'd')->first();

        $this->assertSame('expired', $aProposal->refresh()->status);
        $this->assertSame('expired', $bProposal->refresh()->status);
        $this->assertSame('pending', $cProposal->refresh()->status);
        $this->assertSame('accepted', $dProposal->refresh()->status);

        // Events fired.
        Event::assertDispatchedTimes(ActionProposalExpired::class, 2);
    }

    /**
     * With FakeTenantRunner: custom invocation count.
     */
    public function test_command_respects_invoke_count(): void
    {
        Event::fake();

        $user = $this->makeUser();

        // Create an expired proposal.
        AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'a'],
            'expires_at' => now()->subHour(),
        ]);

        $runner = new FakeTenantRunner;
        $runner->invokeCount = 2;
        Config::set('ai-agents.tenant.runner', FakeTenantRunner::class);

        if (app()->resolved(RunsForEachTenant::class)) {
            app()->forgetInstance(RunsForEachTenant::class);
        }

        // Bind the fake instance as singleton.
        app()->bind(RunsForEachTenant::class, fn () => $runner);

        $this->artisan('ai-agents:proposals:expire')->assertSuccessful();

        // Two invocations.
        $this->assertSame(2, $runner->actualInvocations);

        // Both invocations ran expireStale, so total expired is 1 (first) + 0 (second).
        $aProposal = AiActionProposal::where('payload->key', 'a')->first();
        $this->assertSame('expired', $aProposal->refresh()->status);

        Event::assertDispatchedTimes(ActionProposalExpired::class, 1);
    }

    /**
     * With invokeCount = 0, nothing is executed.
     */
    public function test_command_with_zero_invocations_does_nothing(): void
    {
        Event::fake();

        $user = $this->makeUser();

        AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'a'],
            'expires_at' => now()->subHour(),
        ]);

        $runner = new FakeTenantRunner;
        $runner->invokeCount = 0;
        Config::set('ai-agents.tenant.runner', FakeTenantRunner::class);

        if (app()->resolved(RunsForEachTenant::class)) {
            app()->forgetInstance(RunsForEachTenant::class);
        }

        app()->bind(RunsForEachTenant::class, fn () => $runner);

        $this->artisan('ai-agents:proposals:expire')->assertSuccessful();

        $this->assertSame(0, $runner->actualInvocations);
        Event::assertNotDispatched(ActionProposalExpired::class);

        // Proposal still pending.
        $aProposal = AiActionProposal::where('payload->key', 'a')->first();
        $this->assertSame('pending', $aProposal->refresh()->status);
    }

    /**
     * With execute = false, the runner counts invocations but doesn't run callbacks.
     */
    public function test_runner_without_execution_counts_but_skips(): void
    {
        Event::fake();

        $user = $this->makeUser();

        AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'a'],
            'expires_at' => now()->subHour(),
        ]);

        $runner = new FakeTenantRunner;
        $runner->invokeCount = 3;
        $runner->execute = false;
        Config::set('ai-agents.tenant.runner', FakeTenantRunner::class);

        if (app()->resolved(RunsForEachTenant::class)) {
            app()->forgetInstance(RunsForEachTenant::class);
        }

        app()->bind(RunsForEachTenant::class, fn () => $runner);

        $this->artisan('ai-agents:proposals:expire')->assertSuccessful();

        $this->assertSame(3, $runner->actualInvocations);
        Event::assertNotDispatched(ActionProposalExpired::class);

        // Proposal still pending.
        $aProposal = AiActionProposal::where('payload->key', 'a')->first();
        $this->assertSame('pending', $aProposal->refresh()->status);
    }
}
