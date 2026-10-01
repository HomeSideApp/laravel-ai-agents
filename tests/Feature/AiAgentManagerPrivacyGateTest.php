<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature;

use HomeSide\AiAgents\AgentRegistry;
use HomeSide\AiAgents\AiAgentManager;
use HomeSide\AiAgents\Enums\PrivacyLevel;
use HomeSide\AiAgents\Exceptions\NoAiProviderException;
use HomeSide\AiAgents\Exceptions\PrivacyViolationException;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Models\AiAgent;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiRun;
use HomeSide\AiAgents\Models\ModuleAiConfiguration;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use HomeSide\AiAgents\Tests\Unit\Fixtures\DummyAgent;
use HomeSide\AiAgents\Tests\Unit\Fixtures\SdkBoundaryAgent;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Agent-level privacy gate and privacy-driven content retention in
 * AiAgentManager::run().
 *
 * When an agent requires a minimum privacy level, a less private provider
 * aborts the run with PrivacyViolationException before the SDK is invoked
 * and before any run row is recorded. When the gate passes, the provider's
 * privacy level drives how user_message and reply are persisted.
 */
final class AiAgentManagerPrivacyGateTest extends TestCase
{
    private DummyAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // ai_runs.user_id targets the configured user model; the runs that
        // reach the recorder need a real row.
        TestUser::query()->create(['name' => 'Test User', 'email' => 'test@example.com']);

        $this->agent = new DummyAgent;

        // AgentRegistry resolves fresh instances through the container, so
        // the tests bind the exact instance they mutate.
        $this->app->instance(DummyAgent::class, $this->agent);
        $this->app->make(AgentRegistry::class)
            ->register($this->agent->key(), $this->agent::class);

        // Platform row the configuration resolver requires.
        AiAgent::createValidated([
            'key' => $this->agent->key(),
            'module' => $this->agent->module(),
            'label' => 'Generator',
            'platform_prompt' => 'You generate things.',
        ]);
    }

    /**
     * Helper: create a system provider for the agent's module.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeProvider(array $overrides = []): AiProvider
    {
        return AiProvider::createValidated(array_merge([
            'name' => 'P'.uniqid(),
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-test-1234',
            'module' => $this->agent->module(),
            'privacy_level' => 'cloud',
            'fallback_policy' => 'allow_cloud',
            'scope' => 'global',
        ], $overrides));
    }

    /**
     * Register an SDK-capable agent under the same key with the given
     * privacy requirement and reply, so passing tests run to completion.
     */
    private function registerSdkAgentWith(?PrivacyLevel $requirement, string $reply = 'boundary reply'): SdkBoundaryAgent
    {
        $sdkAgent = new SdkBoundaryAgent;
        $sdkAgent->requiredPrivacyLevel = $requirement;
        $sdkAgent->reply = $reply;

        $this->app->instance(SdkBoundaryAgent::class, $sdkAgent);
        $this->app->make(AgentRegistry::class)
            ->register($this->agent->key(), $sdkAgent::class);

        return $sdkAgent;
    }

    /**
     * A cloud provider violates the agent's local requirement.
     */
    public function test_cloud_provider_violates_local_requirement(): void
    {
        $provider = $this->makeProvider(['privacy_level' => 'cloud']);
        $this->agent->requiredPrivacyLevel = PrivacyLevel::Local;

        try {
            $this->manager()->run($this->agent->key(), $this->context(), 'Generate a recipe');
            $this->fail('PrivacyViolationException was not thrown.');
        } catch (PrivacyViolationException $e) {
            $this->assertStringContainsString('requires privacy level [local]', $e->getMessage());
            $this->assertStringContainsString($provider->name, $e->getMessage());
            $this->assertStringContainsString('cloud', $e->getMessage());
        }
    }

    /**
     * The gate aborts before the run is recorded, so no ai_runs row exists.
     */
    public function test_no_run_is_recorded_when_the_gate_aborts(): void
    {
        $this->makeProvider(['privacy_level' => 'cloud']);
        $this->agent->requiredPrivacyLevel = PrivacyLevel::Local;

        try {
            $this->manager()->run($this->agent->key(), $this->context(), 'Generate a recipe');
            $this->fail('PrivacyViolationException was not thrown.');
        } catch (PrivacyViolationException) {
        }

        $this->assertSame(0, DB::table('ai_runs')->count());
    }

    /**
     * A local provider satisfies the strictest requirement and the run
     * completes (SDK returns, recorder persists).
     */
    public function test_local_provider_satisfies_local_requirement(): void
    {
        $this->registerSdkAgentWith(PrivacyLevel::Local);
        $this->makeProvider(['privacy_level' => 'local']);

        $result = $this->manager()->run($this->agent->key(), $this->context(), 'Generate a recipe');

        $this->assertSame('ok', $result->status);
        $this->assertSame(1, DB::table('ai_runs')->count());
    }

    public function test_manager_records_sdk_usage_provider_cost_and_attempt(): void
    {
        $sdkAgent = $this->registerSdkAgentWith(null);
        $sdkAgent->usage = new TextUsage(inputTokens: 1000, outputTokens: 500, cacheReadInputTokens: 100);
        $provider = $this->makeProvider([
            'cost_input' => '2.000000',
            'cost_output' => '4.000000',
            'cost_cache_read' => '1.000000',
        ]);

        $this->manager()->run($this->agent->key(), $this->context(), 'Generate a recipe');

        $run = AiRun::query()->sole();
        $this->assertSame($provider->id, $run->provider_id);
        $this->assertSame(1000, $run->input_tokens);
        $this->assertSame(500, $run->output_tokens);
        $this->assertSame(100, $run->cached_tokens);
        $this->assertSame('0.003900', $run->estimated_cost);
        $this->assertSame(1, $run->attempts()->count());
    }

    public function test_manager_keeps_unknown_usage_and_cost_null(): void
    {
        $this->registerSdkAgentWith(null);
        $this->makeProvider(['cost_input' => '2.000000']);

        $this->manager()->run($this->agent->key(), $this->context(), 'Generate a recipe');

        $run = AiRun::query()->sole();
        $this->assertNull($run->input_tokens);
        $this->assertNull($run->output_tokens);
        $this->assertNull($run->estimated_cost);
    }

    public function test_provider_temperature_is_used_when_the_scope_has_no_override(): void
    {
        $sdkAgent = $this->registerSdkAgentWith(null);
        $this->makeProvider(['configuration' => ['temperature' => 0.7]]);

        $this->manager()->run($this->agent->key(), $this->context(), 'Generate a recipe');

        $this->assertSame(0.7, $sdkAgent->runtimeConfiguration['temperature']);
    }

    public function test_scope_temperature_overrides_provider_temperature(): void
    {
        $sdkAgent = $this->registerSdkAgentWith(null);
        $this->makeProvider(['configuration' => ['temperature' => 0.7]]);
        ModuleAiConfiguration::factory()->forUser(1)->create([
            'module' => 'recipes',
            'agent_name' => 'generator',
            'parameters' => ['temperature' => 0.2],
        ]);

        $this->manager()->run($this->agent->key(), $this->context(), 'Generate a recipe');

        $this->assertSame(0.2, $sdkAgent->runtimeConfiguration['temperature']);
    }

    /**
     * An unclassified provider must not satisfy a concrete requirement:
     * unknown privacy means the provider may be hosted anywhere.
     */
    public function test_unknown_privacy_fails_local_requirement(): void
    {
        $this->makeProvider(['privacy_level' => 'unknown']);
        $this->agent->requiredPrivacyLevel = PrivacyLevel::Local;

        $this->expectException(PrivacyViolationException::class);
        $this->expectExceptionMessage('unknown');

        $this->manager()->run($this->agent->key(), $this->context(), 'Generate a recipe');
    }

    /**
     * Agents without a declared requirement never trigger the gate.
     */
    public function test_agent_without_requirement_accepts_any_provider(): void
    {
        $this->registerSdkAgentWith(null);
        $this->makeProvider(['privacy_level' => 'cloud']);

        $result = $this->manager()->run($this->agent->key(), $this->context(), 'Generate a recipe');

        $this->assertSame('ok', $result->status);
    }

    /**
     * The no-provider check keeps priority over the privacy gate.
     */
    public function test_no_provider_still_raises_no_ai_provider_exception(): void
    {
        $this->agent->requiredPrivacyLevel = PrivacyLevel::Local;

        $this->expectException(NoAiProviderException::class);

        $this->manager()->run($this->agent->key(), $this->context(), 'Generate a recipe');
    }

    /**
     * Default retention encrypts the conversation content per user: raw
     * storage is ciphertext, model reads decrypt transparently.
     */
    public function test_default_retention_encrypts_content(): void
    {
        $this->registerSdkAgentWith(null, reply: 'the composed reply');
        $this->makeProvider(['privacy_level' => 'cloud']);

        $this->manager()->run($this->agent->key(), $this->context(), 'my user message');

        $run = DB::table('ai_runs')->first();

        $this->assertNotNull($run);
        $this->assertSame('encrypted', $run->content_mode);
        // Raw storage: ciphertext, never plaintext.
        $this->assertStringStartsWith('enc:v1:', (string) $run->user_message);
        $this->assertStringStartsWith('enc:v1:', (string) $run->reply);
        $this->assertStringNotContainsString('my user message', (string) $run->user_message);
        $this->assertStringNotContainsString('composed', (string) $run->reply);

        // Model read path decrypts both fields back.
        $model = AiRun::query()->firstOrFail();
        $this->assertSame('my user message', $model->user_message);
        $this->assertSame('the composed reply', $model->reply);
    }

    /**
     * A host confining cloud providers to redacted retention gets digests
     * instead of conversation content on runs resolved to cloud providers.
     */
    public function test_cloud_retention_redacted_stores_digests(): void
    {
        config(['ai-agents.logging.retention' => ['cloud' => 'redacted']]);

        $this->registerSdkAgentWith(null, reply: 'the composed reply');
        $this->makeProvider(['privacy_level' => 'cloud']);

        $this->manager()->run($this->agent->key(), $this->context(), 'my user message');

        $run = DB::table('ai_runs')->first();

        $this->assertNotNull($run);
        $this->assertStringStartsWith('[redacted sha256:', (string) $run->user_message);
        $this->assertStringNotContainsString('my user message', (string) $run->user_message);
        $this->assertStringStartsWith('[redacted sha256:', (string) $run->reply);
        $this->assertStringNotContainsString('composed', (string) $run->reply);
    }

    /**
     * A host disabling cloud retention stores no conversation content at
     * all for cloud-resolved runs.
     */
    public function test_cloud_retention_none_stores_nothing(): void
    {
        config(['ai-agents.logging.retention' => ['cloud' => 'none']]);

        $this->registerSdkAgentWith(null, reply: 'the composed reply');
        $this->makeProvider(['privacy_level' => 'cloud']);

        $this->manager()->run($this->agent->key(), $this->context(), 'my user message');

        $run = DB::table('ai_runs')->first();

        $this->assertNotNull($run);
        $this->assertNull($run->user_message);
        $this->assertNull($run->reply);
        $this->assertSame('ok', $run->status);
    }

    /**
     * Retention rows are per privacy level: a local provider stores
     * (encrypted) content even when cloud is restricted to none.
     */
    public function test_retention_is_per_privacy_level(): void
    {
        config(['ai-agents.logging.retention' => ['cloud' => 'none']]);

        $this->registerSdkAgentWith(null, reply: 'local reply');
        $this->makeProvider(['privacy_level' => 'local']);

        $this->manager()->run($this->agent->key(), $this->context(), 'local message');

        $run = DB::table('ai_runs')->first();

        $this->assertNotNull($run);
        $this->assertSame('encrypted', $run->content_mode);
        $this->assertStringStartsWith('enc:v1:', (string) $run->user_message);
        $this->assertStringStartsWith('enc:v1:', (string) $run->reply);

        $model = AiRun::query()->firstOrFail();
        $this->assertSame('local message', $model->user_message);
        $this->assertSame('local reply', $model->reply);
    }

    /**
     * The manager with the firewall disabled so the tests isolate the gate.
     */
    private function manager(): AiAgentManager
    {
        config(['ai-agents.firewall.enabled' => false]);

        return $this->app->make(AiAgentManager::class);
    }

    private function context(): AiExecutionContextData
    {
        return new AiExecutionContextData(userId: 1);
    }
}
