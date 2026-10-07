<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Conversations;

use HomeSide\AiAgents\Conversations\ConversationAccessGuard;
use HomeSide\AiAgents\Exceptions\ConversationNotFoundException;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Models\AiConversation;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use HomeSide\AiAgents\Tests\Unit\Fixtures\TestTeam;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Column-based tenancy: the guard must scope by the authorised tenant, so a
 * conversation belonging to another tenant is never accessible.
 */
final class ConversationTenancyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ai-agents.tenant.isolation', 'column');
        $app['config']->set('ai-agents.tenant.enabled', true);
        $app['config']->set('ai-agents.tenant.model', TestTeam::class);
        $app['config']->set('ai-agents.tenant.table', 'test_teams');
        $app['config']->set('ai-agents.tenant.foreign_key', 'team_id');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('test_teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations-column-tenant');
    }

    private function guard(): ConversationAccessGuard
    {
        return $this->app->make(ConversationAccessGuard::class);
    }

    public function test_same_tenant_can_access_its_conversation(): void
    {
        $team = TestTeam::create(['name' => 'Team A']);
        $user = TestUser::create(['name' => 'A', 'email' => 'a-team@example.com']);

        $conversation = AiConversation::create([
            'user_id' => $user->id,
            'agent' => 'recipes.generator',
            'title' => 'Chat',
            'team_id' => $team->id,
        ]);

        $resolved = $this->guard()->authorize(
            $conversation->id,
            'recipes.generator',
            new AiExecutionContextData(userId: $user->id, tenantId: $team->id),
        );

        $this->assertSame($conversation->id, $resolved->id);
    }

    public function test_another_tenant_cannot_access_the_conversation(): void
    {
        $teamA = TestTeam::create(['name' => 'Team A']);
        $teamB = TestTeam::create(['name' => 'Team B']);
        $user = TestUser::create(['name' => 'A', 'email' => 'a-team2@example.com']);

        $conversation = AiConversation::create([
            'user_id' => $user->id,
            'agent' => 'recipes.generator',
            'title' => 'Chat',
            'team_id' => $teamA->id,
        ]);

        $this->expectException(ConversationNotFoundException::class);

        $this->guard()->authorize(
            $conversation->id,
            'recipes.generator',
            new AiExecutionContextData(userId: $user->id, tenantId: $teamB->id),
        );
    }
}
