<?php

declare(strict_types=1);

namespace HomeSide\AiAgents;

use HomeSide\AiAgents\Configuration\AgentConfigurationResolver;
use HomeSide\AiAgents\Console\Commands\FirewallEvaluateCommand;
use HomeSide\AiAgents\Console\Commands\FirewallStatusCommand;
use HomeSide\AiAgents\Console\Commands\FirewallTrainCommand;
use HomeSide\AiAgents\Console\Commands\ModelsDevSyncCommand;
use HomeSide\AiAgents\Console\Commands\UsageConsolidateCommand;
use HomeSide\AiAgents\Context\ContextBuilder;
use HomeSide\AiAgents\Context\ContextProvider;
use HomeSide\AiAgents\Contracts\DomainAgent;
use HomeSide\AiAgents\Contracts\InspectsPrompt;
use HomeSide\AiAgents\Contracts\ModuleAiProvider;
use HomeSide\AiAgents\Contracts\ResolvesTenant;
use HomeSide\AiAgents\Execution\CostEstimator;
use HomeSide\AiAgents\Execution\ExecutionRecorder;
use HomeSide\AiAgents\Execution\RunContentRedactor;
use HomeSide\AiAgents\ModelsDev\CatalogPrefill;
use HomeSide\AiAgents\ModelsDev\CatalogQuery;
use HomeSide\AiAgents\ModelsDev\ModelsDevSynchronizer;
use HomeSide\AiAgents\Privacy\ContentSharing;
use HomeSide\AiAgents\Privacy\UserContentKeyManager;
use HomeSide\AiAgents\Prompting\PromptCompositor;
use HomeSide\AiAgents\Providers\AiProviderTester;
use HomeSide\AiAgents\Providers\DynamicProviderRegistrar;
use HomeSide\AiAgents\Providers\ImageGenerationProviderTester;
use HomeSide\AiAgents\Providers\ProviderResolver;
use HomeSide\AiAgents\Security\ClassifierPromptInspector;
use HomeSide\AiAgents\Security\LexiconPromptInspector;
use HomeSide\AiAgents\Security\NullPromptInspector;
use HomeSide\AiAgents\Security\PromptFirewallPipeline;
use HomeSide\AiAgents\Security\StatisticalPromptScorer;
use HomeSide\AiAgents\Synchronizer\AgentSynchronizer;
use HomeSide\AiAgents\Tenancy\GenericTenantResolver;
use HomeSide\AiAgents\Tenancy\NullTenantResolver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Service provider of the homeside/laravel-ai-agents package.
 *
 * Responsibilities:
 * - Load and publish the package configuration and migrations.
 * - Bind the core services as singletons.
 * - Register the host application's agents, modules and context providers
 *   declared in config('ai-agents.agents'), config('ai-agents.modules')
 *   and config('ai-agents.context_providers').
 */
final class AiServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-agents.php', 'ai-agents');

        // Factory resolution for HasFactory-enabled package models. Package
        // models resolve to the package's own Database\Factories namespace;
        // every other model keeps the host's flat Database\Factories\
        // {Model}Factory convention, so host factories are unaffected.
        Factory::guessFactoryNamesUsing(
            /** @param class-string<Model> $modelName */
            function (string $modelName): string {
                $baseName = Str::afterLast($modelName, '\\');

                if (str_starts_with($modelName, 'HomeSide\\AiAgents\\Models\\')) {
                    /** @var class-string<Factory<Model>> $factory */
                    $factory = 'HomeSide\\AiAgents\\Database\\Factories\\'.$baseName.'Factory';

                    return $factory;
                }

                /** @var class-string<Factory<Model>> $factory */
                $factory = 'Database\\Factories\\'.$baseName.'Factory';

                return $factory;
            },
        );

        $this->app->singleton(InspectsPrompt::class, function (Application $app): InspectsPrompt {
            $customInspector = config('ai-agents.firewall.inspector');

            if (is_string($customInspector) && $customInspector !== '' && class_exists($customInspector)) {
                /** @var InspectsPrompt $inspector */
                $inspector = $app->make($customInspector);

                return $inspector;
            }

            // Master switch: disabled firewall binds the null object so
            // consumers can call InspectsPrompt unconditionally, mirroring
            // the NullTenantResolver pattern.
            if (! (bool) config('ai-agents.firewall.enabled', true)) {
                return $app->make(NullPromptInspector::class);
            }

            return $app->make(PromptFirewallPipeline::class);
        });

        // The pipeline composes the toggleable layers; resolved through the
        // container so each layer keeps its own singleton identity.
        $this->app->singleton(LexiconPromptInspector::class, function (Application $app): LexiconPromptInspector {
            return new LexiconPromptInspector(dirname(__DIR__).'/resources/firewall/lexicon');
        });

        $this->app->singleton(StatisticalPromptScorer::class);

        // Layer 3: trained classifier. Self-degrades to disabled when no
        // valid artifact is present, so it is safe to always register.
        $this->app->singleton(ClassifierPromptInspector::class, function (Application $app): ClassifierPromptInspector {
            return new ClassifierPromptInspector(dirname(__DIR__).'/resources/firewall/model/prompt-injection-v1.rbx');
        });

        $this->app->singleton(PromptFirewallPipeline::class, function (Application $app): PromptFirewallPipeline {
            return new PromptFirewallPipeline([
                $app->make(LexiconPromptInspector::class),
                $app->make(StatisticalPromptScorer::class),
                $app->make(ClassifierPromptInspector::class),
            ]);
        });

        $this->app->singleton(ResolvesTenant::class, function (Application $app): ResolvesTenant {
            $customResolver = config('ai-agents.tenant.resolver');

            if (is_string($customResolver) && $customResolver !== '' && class_exists($customResolver)) {
                /** @var ResolvesTenant $resolver */
                $resolver = $app->make($customResolver);

                return $resolver;
            }

            return (bool) config('ai-agents.tenant.enabled', false)
                ? $app->make(GenericTenantResolver::class)
                : $app->make(NullTenantResolver::class);
        });

        $this->app->singleton(AgentRegistry::class);
        $this->app->singleton(AgentConfigurationResolver::class);
        $this->app->singleton(PromptCompositor::class);
        $this->app->singleton(ContextBuilder::class);
        $this->app->singleton(RunContentRedactor::class);
        $this->app->singleton(ExecutionRecorder::class);
        $this->app->singleton(ProviderResolver::class);
        $this->app->singleton(DynamicProviderRegistrar::class);
        $this->app->singleton(AiProviderTester::class);
        $this->app->singleton(ImageGenerationProviderTester::class);
        $this->app->singleton(AiAgentManager::class);
        $this->app->singleton(AgentSynchronizer::class);
        // Catalog read services (stateless queries): bind as singletons so
        // hosts can inject CatalogQuery / CatalogPrefill anywhere.
        $this->app->singleton(CatalogQuery::class);
        $this->app->singleton(CatalogPrefill::class);
        $this->app->singleton(CostEstimator::class);

        // Privacy: per-user envelope keys and support-content grants.
        $this->app->singleton(UserContentKeyManager::class);
        $this->app->singleton(ContentSharing::class);
        $this->app->singleton(ModelsDevSynchronizer::class, function (Application $app): ModelsDevSynchronizer {
            return new ModelsDevSynchronizer(
                apiUrl: config('ai-agents.models_dev.api_url'),
                logoDiskPath: (string) config('ai-agents.models_dev.logo_disk_path', 'models-dev/logos'),
                skipLogos: ! (bool) config('ai-agents.models_dev.logos_enabled', true),
            );
        });

        // Firewall operations (Phase 2 of the plan): train, evaluate and
        // status over the classifier artifact.
        $this->app->singleton(FirewallTrainCommand::class);
        $this->app->singleton(FirewallEvaluateCommand::class);
        $this->app->singleton(FirewallStatusCommand::class);

        $this->commands([
            FirewallTrainCommand::class,
            FirewallEvaluateCommand::class,
            FirewallStatusCommand::class,
            ModelsDevSyncCommand::class,
            UsageConsolidateCommand::class,
        ]);
    }

    /**
     * Boot the package: migrations, publishable assets and host registrations.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/ai-agents.php' => config_path('ai-agents.php'),
        ], 'ai-agents-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'ai-agents-migrations');

        if (config('ai-agents.tenant.enabled')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/tenant');

            $this->publishes([
                __DIR__.'/../database/migrations/tenant' => database_path('migrations'),
            ], 'ai-agents-tenant-migrations');
        }

        $this->publishes([
            __DIR__.'/../stubs' => base_path('stubs/ai-agents'),
        ], 'ai-agents-stubs');

        $this->registerHostComponents();
        $this->autoSyncAgents();
        $this->scheduleModelsDevSync();
        $this->scheduleUsageConsolidation();
    }

    /**
     * Register the daily usage consolidation on the schedule.
     *
     * Runs after the models.dev sync (default 03:00) so prices are fresh
     * before the next day's runs, with overlap protection. Disable or move
     * it via config('ai-agents.usage').
     */
    private function scheduleUsageConsolidation(): void
    {
        if (! (bool) config('ai-agents.usage.schedule_enabled', true)) {
            return;
        }

        $this->app->booted(function (Application $app): void {
            $schedule = $app->make(Schedule::class);

            $schedule->command('ai-agents:usage:consolidate')
                ->dailyAt((string) config('ai-agents.usage.schedule_at', '03:00'))
                ->withoutOverlapping(120)
                ->onOneServer()
                ->runInBackground();
        });
    }

    /**
     * Register the daily models.dev catalog sync on the schedule.
     *
     * Runs at config('ai-agents.models_dev.schedule_at') (default 02:00)
     * with overlap protection. Configurable through the same block: hosts
     * can disable it or change the hour; failures are logged by the
     * scheduler and never affect the request cycle.
     */
    private function scheduleModelsDevSync(): void
    {
        if (! (bool) config('ai-agents.models_dev.schedule_enabled', true)) {
            return;
        }

        $this->app->booted(function (Application $app): void {
            $schedule = $app->make(Schedule::class);

            $schedule->command('ai-agents:models-dev:sync')
                ->dailyAt((string) config('ai-agents.models_dev.schedule_at', '02:00'))
                ->withoutOverlapping(60)
                ->onOneServer()
                ->runInBackground();
        });
    }

    /**
     * Synchronise registered agents into the ai_agents table on boot.
     *
     * Makes the registry-to-database sync transparent to hosts: deploying a
     * new agent (config entry) immediately creates its row without anyone
     * remembering to run the sync command. Idempotent and self-healing by
     * design; failures (migrations pending, DB unreachable) never break the
     * request — the resolver's per-run self-heal covers those edge cases.
     */
    private function autoSyncAgents(): void
    {
        if (! (bool) config('ai-agents.auto_sync', true)) {
            return;
        }

        try {
            $this->app->make(AgentSynchronizer::class)->sync();
        } catch (\Throwable) {
            // Table does not exist yet (migrations pending) or the database
            // is unreachable: leave boot clean, the row is created later.
        }
    }

    /**
     * Register the host application's agents, modules and context providers
     * declared in the published configuration.
     */
    private function registerHostComponents(): void
    {
        $registry = $this->app->make(AgentRegistry::class);
        $contextBuilder = $this->app->make(ContextBuilder::class);

        /** @var array<class-string> $agentClasses */
        $agentClasses = (array) config('ai-agents.agents', []);

        foreach ($agentClasses as $agentClass) {
            if (! is_a($agentClass, DomainAgent::class, true)) {
                throw new \InvalidArgumentException(
                    "Registered AI agent [{$agentClass}] must implement ".DomainAgent::class.'.',
                );
            }

            $agent = $this->app->make($agentClass);
            $registry->register($agent->key(), $agentClass);
        }

        /** @var array<class-string> $moduleClasses */
        $moduleClasses = (array) config('ai-agents.modules', []);

        foreach ($moduleClasses as $moduleClass) {
            if (! is_a($moduleClass, ModuleAiProvider::class, true)) {
                throw new \InvalidArgumentException(
                    "Registered AI module [{$moduleClass}] must implement ".ModuleAiProvider::class.'.',
                );
            }

            $registry->registerModule($this->app->make($moduleClass));
        }

        /** @var array<class-string> $contextProviderClasses */
        $contextProviderClasses = (array) config('ai-agents.context_providers', []);

        foreach ($contextProviderClasses as $providerClass) {
            if (! is_a($providerClass, ContextProvider::class, true)) {
                throw new \InvalidArgumentException(
                    "Registered AI context provider [{$providerClass}] must implement ".ContextProvider::class.'.',
                );
            }

            $contextBuilder->register($this->app->make($providerClass));
        }
    }
}
