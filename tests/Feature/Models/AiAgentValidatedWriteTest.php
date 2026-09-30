<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Models;

use HomeSide\AiAgents\AgentRegistry;
use HomeSide\AiAgents\Models\AiAgent;
use HomeSide\AiAgents\Synchronizer\AgentSynchronizer;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\Unit\Fixtures\DummyAgent;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

final class AiAgentValidatedWriteTest extends TestCase
{
    /**
     * A valid platform row is created with version 1 by default.
     */
    public function test_valid_agent_row_is_created(): void
    {
        $agent = AiAgent::createValidated([
            'key' => 'recipes.generator',
            'module' => 'recipes',
            'label' => 'Recipe generator',
            'platform_prompt' => 'You generate recipes.',
        ]);

        $this->assertDatabaseHas('ai_agents', ['id' => $agent->id, 'key' => 'recipes.generator']);
        $this->assertSame(1, $agent->prompt_version);
    }

    /**
     * The agent key is the registry's join key: duplicates must be rejected
     * even though the DB unique index would catch them later.
     */
    public function test_duplicate_agent_key_is_rejected(): void
    {
        AiAgent::createValidated([
            'key' => 'recipes.generator',
            'module' => 'recipes',
            'label' => 'First',
            'platform_prompt' => 'Prompt A.',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already exists');

        AiAgent::createValidated([
            'key' => 'recipes.generator',
            'module' => 'recipes',
            'label' => 'Second',
            'platform_prompt' => 'Prompt B.',
        ]);
    }

    /**
     * Prompt versions only move forward: a stale admin edit cannot silently
     * downgrade the prompt that production runs use.
     */
    public function test_prompt_version_cannot_decrease(): void
    {
        $agent = AiAgent::createValidated([
            'key' => 'recipes.generator',
            'module' => 'recipes',
            'label' => 'Recipe generator',
            'platform_prompt' => 'v2 prompt.',
            'prompt_version' => 3,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot decrease');

        $agent->updateValidated(['platform_prompt' => 'v1 rollback attempt.', 'prompt_version' => 2]);
    }

    /**
     * The synchroniser seeds ai_agents rows from registered classes.
     */
    public function test_synchroniser_creates_missing_rows_and_reports_orphans(): void
    {
        $registry = $this->app->make(AgentRegistry::class);
        $registry->register('recipes.generator', DummyAgent::class);

        // A stale row whose class is no longer registered.
        AiAgent::createValidated([
            'key' => 'legacy.agent',
            'module' => 'legacy',
            'label' => 'Legacy',
            'platform_prompt' => 'Old prompt.',
        ]);

        $report = $this->app->make(AgentSynchronizer::class)->sync();

        $this->assertSame(1, $report['created']);
        $this->assertSame(['legacy.agent'], $report['orphaned_keys']);
        $this->assertDatabaseHas('ai_agents', ['key' => 'recipes.generator']);
        $this->assertDatabaseHas('ai_agents', ['key' => 'legacy.agent']); // never deleted
    }

    /**
     * The seeded row carries the agent's platform prompt and module.
     */
    public function test_synchroniser_seeds_prompt_and_module_from_class(): void
    {
        $registry = $this->app->make(AgentRegistry::class);
        $registry->register('recipes.generator', DummyAgent::class);

        $this->app->make(AgentSynchronizer::class)->sync();

        $row = AiAgent::findByKey('recipes.generator');

        $this->assertNotNull($row);
        $this->assertSame('recipes', $row->module);
        $this->assertSame('Recipes Generator', $row->label);
        $this->assertTrue((bool) $row->enabled);
    }

    /**
     * diagnose() reports both directions of the class↔row mapping.
     */
    public function test_diagnose_reports_missing_rows_and_orphaned_rows(): void
    {
        $registry = $this->app->make(AgentRegistry::class);
        $registry->register('recipes.generator', DummyAgent::class);

        AiAgent::createValidated([
            'key' => 'legacy.agent',
            'module' => 'legacy',
            'label' => 'Legacy',
            'platform_prompt' => 'Old prompt.',
        ]);

        $diagnosis = $this->app->make(AgentSynchronizer::class)->diagnose();

        $this->assertSame(['recipes.generator'], array_keys($diagnosis['classes_without_rows']));
        $this->assertSame(['legacy.agent'], array_keys($diagnosis['rows_without_classes']));
    }

    /**
     * The registered class uses its own contextProviders()/capabilities so
     * the synchroniser seeds exactly what the class declares.
     */
    public function test_synchroniser_does_not_create_duplicate_rows_on_second_run(): void
    {
        $registry = $this->app->make(AgentRegistry::class);
        $registry->register('recipes.generator', DummyAgent::class);

        $synchroniser = $this->app->make(AgentSynchronizer::class);
        $synchroniser->sync();
        $second = $synchroniser->sync();

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertDatabaseCount('ai_agents', 1);
    }

    public function test_synchroniser_updates_only_unedited_seeded_prompts(): void
    {
        $registry = $this->app->make(AgentRegistry::class);
        $registry->register('recipes.generator', PromptedDummyAgent::class);
        $synchroniser = $this->app->make(AgentSynchronizer::class);
        $synchroniser->sync();

        $row = AiAgent::findByKey('recipes.generator');
        $this->assertNotNull($row);
        $row->update(['platform_prompt' => 'Old class prompt', 'seeded_prompt_hash' => hash('sha256', 'Old class prompt')]);

        $synchroniser->sync();
        $this->assertSame((new PromptedDummyAgent)->instructions(), $row->fresh()->platform_prompt);
        $this->assertSame(2, $row->fresh()->prompt_version);

        $row->update(['platform_prompt' => 'Admin edit']);
        $synchroniser->sync();
        $this->assertSame('Admin edit', $row->fresh()->platform_prompt);
    }
}

class PromptedDummyAgent extends DummyAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Current class prompt';
    }
}
