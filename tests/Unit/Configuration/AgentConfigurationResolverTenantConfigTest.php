<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Configuration;

use HomeSide\AiAgents\AgentRegistry;
use HomeSide\AiAgents\Configuration\AgentConfigurationResolver;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Models\AiAgent;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\ModuleAiConfiguration;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\Unit\Fixtures\DummyAgent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The user → tenant scope chain of per-scope agent configuration:
 * tenant-owned rows (config managed collectively for a household/team)
 * are honoured when no user-owned row exists, mirroring the provider
 * resolver's precedence.
 */
final class AgentConfigurationResolverTenantConfigTest extends TestCase
{
    private function bootstrapAgentRow(): void
    {
        $this->app->make(AgentRegistry::class)->register('recipes.generator', DummyAgent::class);

        AiAgent::createValidated([
            'key' => 'recipes.generator',
            'module' => 'recipes',
            'label' => 'Recipe generator',
            'platform_prompt' => 'You generate recipes.',
        ]);
    }

    /**
     * Enable tenancy and simulate the tenant migration on
     * module_ai_configurations (the suite boots with tenancy disabled).
     */
    private function enableTenancy(): void
    {
        config()->set('ai-agents.tenant.enabled', true);
        config()->set('ai-agents.tenant.foreign_key', 'household_id');

        Schema::table('module_ai_configurations', function (Blueprint $table): void {
            $table->string('household_id')->nullable()->index();
        });
    }

    /**
     * The user-owned row wins over the tenant-owned row.
     */
    public function test_user_owned_row_takes_precedence_over_tenant_owned_row(): void
    {
        $this->enableTenancy();
        $this->bootstrapAgentRow();

        ModuleAiConfiguration::query()->create([
            'module' => 'recipes',
            'agent_name' => 'generator',
            'label' => 'Tenant-wide',
            'system_prompt' => 'Tenant prompt.',
            'additional_instructions' => 'Tenant instructions.',
            'household_id' => 'h-1',
        ]);
        ModuleAiConfiguration::query()->create([
            'user_id' => 7,
            'module' => 'recipes',
            'agent_name' => 'generator',
            'label' => 'Personal',
            'system_prompt' => 'Personal prompt.',
            'additional_instructions' => 'Personal instructions.',
        ]);

        $resolver = $this->app->make(AgentConfigurationResolver::class);
        $instructions = $resolver->getUserInstructions('recipes.generator', 7, 'h-1');

        $this->assertSame('Personal instructions.', $instructions);

        Schema::table('module_ai_configurations', function (Blueprint $table): void {
            $table->dropIndex(['household_id']);
            $table->dropColumn('household_id');
        });
    }

    /**
     * Without a user-owned row, the tenant-owned row is used — hosts that
     * manage configuration collectively per household get it honoured.
     */
    public function test_tenant_owned_row_is_used_when_no_user_row_exists(): void
    {
        $this->enableTenancy();
        $this->bootstrapAgentRow();

        ModuleAiConfiguration::query()->create([
            'module' => 'recipes',
            'agent_name' => 'generator',
            'label' => 'Tenant-wide',
            'system_prompt' => 'Tenant prompt.',
            'additional_instructions' => 'Tenant instructions.',
            'household_id' => 'h-1',
        ]);

        $resolver = $this->app->make(AgentConfigurationResolver::class);

        $this->assertSame(
            'Tenant instructions.',
            $resolver->getUserInstructions('recipes.generator', 7, 'h-1'),
        );
        $this->assertNull($resolver->getUserInstructions('recipes.generator', 7, 'h-OTHER'));

        Schema::table('module_ai_configurations', function (Blueprint $table): void {
            $table->dropIndex(['household_id']);
            $table->dropColumn('household_id');
        });
    }

    /**
     * resolve() merges the tenant-owned parameters into the effective
     * configuration when the household owns the configuration.
     */
    public function test_resolve_merges_tenant_owned_parameters(): void
    {
        $this->enableTenancy();
        $this->bootstrapAgentRow();

        ModuleAiConfiguration::query()->create([
            'module' => 'recipes',
            'agent_name' => 'generator',
            'label' => 'Tenant-wide',
            'system_prompt' => 'Tenant prompt.',
            'household_id' => 'h-1',
            'parameters' => ['temperature' => 0.9, 'max_tokens' => 1000],
        ]);

        $resolver = $this->app->make(AgentConfigurationResolver::class);
        $resolved = $resolver->resolve('recipes.generator', new AiExecutionContextData(
            userId: 7,
            tenantId: 'h-1',
        ));

        $this->assertTrue($resolved->enabled);
        $this->assertSame(0.9, $resolved->parameters['temperature']);
        $this->assertSame(1000, $resolved->parameters['max_tokens']);

        Schema::table('module_ai_configurations', function (Blueprint $table): void {
            $table->dropIndex(['household_id']);
            $table->dropColumn('household_id');
        });
    }

    public function test_personal_config_is_scoped_to_its_household_and_controls_provider_model_and_enabled(): void
    {
        $this->enableTenancy();
        $this->bootstrapAgentRow();
        $provider = AiProvider::factory()->create();

        ModuleAiConfiguration::query()->create([
            'user_id' => 7,
            'household_id' => 'h-1',
            'module' => 'recipes',
            'agent_name' => 'generator',
            'label' => 'Personal',
            'system_prompt' => 'Personal prompt.',
            'ai_provider_id' => $provider->id,
            'model' => 'preferred-model',
            'enabled' => false,
        ]);
        ModuleAiConfiguration::query()->create([
            'household_id' => 'h-2',
            'module' => 'recipes',
            'agent_name' => 'generator',
            'label' => 'Tenant',
            'system_prompt' => 'Tenant prompt.',
        ]);

        $resolver = $this->app->make(AgentConfigurationResolver::class);
        $personal = $resolver->resolve('recipes.generator', new AiExecutionContextData(userId: 7, tenantId: 'h-1'));
        $otherHousehold = $resolver->resolve('recipes.generator', new AiExecutionContextData(userId: 7, tenantId: 'h-2'));

        $this->assertFalse($personal->enabled);
        $this->assertSame($provider->id, $personal->providerId);
        $this->assertSame('preferred-model', $personal->configuredModel);
        $this->assertTrue($otherHousehold->enabled);
        $this->assertNull($otherHousehold->providerId);

        Schema::table('module_ai_configurations', function (Blueprint $table): void {
            $table->dropIndex(['household_id']);
            $table->dropColumn('household_id');
        });
    }
}
