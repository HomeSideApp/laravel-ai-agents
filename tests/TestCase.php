<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests;

use HomeSide\AiAgents\AiServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as TestbenchTestCase;

/**
 * Base test case for the package test suite.
 *
 * Boots a minimal Laravel application through Orchestra Testbench, registers
 * the package provider and refreshes the in-memory SQLite database between
 * tests so feature tests hit the real migrations.
 */
abstract class TestCase extends TestbenchTestCase
{
    use RefreshDatabase;

    /**
     * Register the package's service provider in the testbench app.
     *
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
        ];
    }

    /**
     * Define the host-side configuration the package expects.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai-agents.user_model', TestUser::class);
        $app['config']->set('ai-agents.users_table', 'test_users');
        $app['config']->set('ai-agents.tenant.enabled', false);
        $app['config']->set('ai-agents.firewall.action', 'flag');
        $app['config']->set('ai-agents.fallback_module', 'general');
    }

    /**
     * Run the package migrations (and the test-support migrations) against
     * the in-memory database before each test.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
