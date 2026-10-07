<?php

declare(strict_types=1);

namespace HomeSide\AiAgents;

use HomeSide\AiAgents\Configuration\AgentConfigurationResolver;
use HomeSide\AiAgents\Console\Commands\ExpireProposalsCommand;
use HomeSide\AiAgents\Console\Commands\FirewallEvaluateCommand;
use HomeSide\AiAgents\Console\Commands\FirewallStatusCommand;
use HomeSide\AiAgents\Console\Commands\FirewallTrainCommand;
use HomeSide\AiAgents\Console\Commands\ModelsDevSyncCommand;
use HomeSide\AiAgents\Console\Commands\PruneConversationsCommand;
use HomeSide\AiAgents\Console\Commands\SkillsSyncCommand;
use HomeSide\AiAgents\Console\Commands\SyncAgentsCommand;
use HomeSide\AiAgents\Console\Commands\UsageConsolidateCommand;
use HomeSide\AiAgents\Context\ContextBuilder;
use HomeSide\AiAgents\Context\ContextProvider;
use HomeSide\AiAgents\Contracts\AuthorizesProposalDecisions;
use HomeSide\AiAgents\Contracts\DomainAgent;
use HomeSide\AiAgents\Contracts\InspectsPrompt;
use HomeSide\AiAgents\Contracts\ModuleAiProvider;
use HomeSide\AiAgents\Contracts\ProposalHandler;
use HomeSide\AiAgents\Contracts\ProvidesSkills;
use HomeSide\AiAgents\Contracts\ResolvesTenant;
use HomeSide\AiAgents\Contracts\RunsForEachTenant;
use HomeSide\AiAgents\Conversations\ConversationAccessGuard;
use HomeSide\AiAgents\Conversations\ConversationManager;
use HomeSide\AiAgents\Conversations\PackageConversationStore;
use HomeSide\AiAgents\Embeddings\EmbeddingManager;
use HomeSide\AiAgents\Enums\TenantIsolation;
use HomeSide\AiAgents\Execution\CostEstimator;
use HomeSide\AiAgents\Execution\ExecutionRecorder;
use HomeSide\AiAgents\Execution\RunContentRedactor;
use HomeSide\AiAgents\ModelsDev\CatalogPrefill;
use HomeSide\AiAgents\ModelsDev\CatalogQuery;
use HomeSide\AiAgents\ModelsDev\ModelsDevSynchronizer;
use HomeSide\AiAgents\Privacy\ContentSharing;
use HomeSide\AiAgents\Privacy\UserContentCipher;
use HomeSide\AiAgents\Privacy\UserContentKeyManager;
use HomeSide\AiAgents\Prompting\PromptCompositor;
use HomeSide\AiAgents\Proposals\OwnerOnlyProposalAuthorizer;
use HomeSide\AiAgents\Proposals\ProposalDecisions;
use HomeSide\AiAgents\Proposals\ProposalHandlerRegistry;
use HomeSide\AiAgents\Proposals\SdkApprovalBridge;
use HomeSide\AiAgents\Providers\AiProviderTester;
use HomeSide\AiAgents\Providers\DynamicProviderRegistrar;
use HomeSide\AiAgents\Providers\EmbeddingProviderTester;
use HomeSide\AiAgents\Providers\ImageGenerationProviderTester;
use HomeSide\AiAgents\Providers\ProviderModelDefaults;
use HomeSide\AiAgents\Providers\ProviderModelResolver;
use HomeSide\AiAgents\Providers\ProviderResolver;
use HomeSide\AiAgents\Security\ClassifierPromptInspector;
use HomeSide\AiAgents\Security\LexiconPromptInspector;
use HomeSide\AiAgents\Security\NullPromptInspector;
use HomeSide\AiAgents\Security\PromptFirewallPipeline;
use HomeSide\AiAgents\Security\StatisticalPromptScorer;
use HomeSide\AiAgents\Skills\SkillRegistry;
use HomeSide\AiAgents\Synchronizer\AgentSynchronizer;
use HomeSide\AiAgents\Tenancy\DatabaseTenantResolver;
use HomeSide\AiAgents\Tenancy\GenericTenantResolver;
use HomeSide\AiAgents\Tenancy\NullTenantResolver;
use HomeSide\AiAgents\Tenancy\SingleContextRunner;
use HomeSide\AiAgents\Tenancy\StanclTenantRunner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Storage\DatabaseConversationStore;

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
     * The resolved tenant isolation mode for this installation, computed once
     * during register() and reused in boot()/autoSyncAgents().
     */
    private TenantIsolation $isolations;

    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-agents.php', 'ai-agents');

        $this->isolations = TenantIsolation::fromConfig();

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
            $isolations = TenantIsolation::fromConfig();
            $customResolver = config('ai-agents.tenant.resolver');

            if (is_string($customResolver) && $customResolver !== '' && class_exists($customResolver)) {
                /** @var ResolvesTenant $customResolverResolved */
                $customResolverResolved = $app->make($customResolver);

                return $customResolverResolved;
            }

            return match ($isolations) {
                TenantIsolation::Column => $app->make(GenericTenantResolver::class),
                TenantIsolation::Database => $app->make(DatabaseTenantResolver::class),
                TenantIsolation::None => $app->make(NullTenantResolver::class),
            };
        });

        // Agent Skills: hydrate lazily from configured directories plus any
        // programmatic ProvidesSkills providers, with firewall inspection.
        $this->app->singleton(SkillRegistry::class, function (Application $app): SkillRegistry {
            /** @var array<int, string> $directories */
            $directories = (array) config('ai-agents.skills.directories', []);

            return new SkillRegistry(
                directories: array_values(array_filter(
                    $directories,
                    static fn (mixed $directory): bool => is_string($directory) && $directory !== '',
                )),
                enabled: (bool) config('ai-agents.skills.enabled', true),
                firewall: (bool) config('ai-agents.skills.firewall', true),
                inspector: $app->make(InspectsPrompt::class),
            );
        });

        $this->app->singleton(AgentRegistry::class);
        $this->app->singleton(AgentConfigurationResolver::class);
        $this->app->singleton(PromptCompositor::class);
        $this->app->singleton(ContextBuilder::class);
        $this->app->singleton(RunContentRedactor::class);
        $this->app->singleton(ExecutionRecorder::class);
        $this->app->singleton(ProviderResolver::class);
        $this->app->singleton(ProviderModelResolver::class);
        $this->app->singleton(ProviderModelDefaults::class);
        $this->app->singleton(DynamicProviderRegistrar::class);
        $this->app->singleton(AiProviderTester::class);
        $this->app->singleton(ImageGenerationProviderTester::class);
        $this->app->singleton(EmbeddingProviderTester::class);
        $this->app->singleton(EmbeddingManager::class);
        $this->app->singleton(AiAgentManager::class);
        $this->app->singleton(AgentSynchronizer::class);
        // Catalog read services (stateless queries): bind as singletons so
        // hosts can inject CatalogQuery / CatalogPrefill anywhere.
        $this->app->singleton(CatalogQuery::class);
        $this->app->singleton(CatalogPrefill::class);
        $this->app->singleton(CostEstimator::class);

        // Privacy: per-user envelope keys, reusable cipher and support-content grants.
        $this->app->singleton(UserContentKeyManager::class);
        $this->app->singleton(UserContentCipher::class);
        $this->app->singleton(ContentSharing::class);

        // Conversations: canonical memory. The store is bound as a singleton
        // so the manager and the SDK middleware share the same instance (and
        // therefore the same per-execution agent context).
        $this->app->singleton(ConversationAccessGuard::class);
        $this->app->singleton(ConversationManager::class);
        $this->app->singleton(PackageConversationStore::class);
        $this->app->singleton(ModelsDevSynchronizer::class, function (Application $app): ModelsDevSynchronizer {
            return new ModelsDevSynchronizer(
                apiUrl: config('ai-agents.models_dev.api_url'),
                logoDiskPath: (string) config('ai-agents.models_dev.logo_disk_path', 'models-dev/logos'),
                skipLogos: ! (bool) config('ai-agents.models_dev.logos_enabled', true),
            );
        });

        // Auto-set catalog.connection to 'central' in database mode so the
        // models.dev catalog lives on the central database.
        if ($this->isolations === TenantIsolation::Database) {
            $connection = config('ai-agents.catalog.connection');
            if (is_null($connection) || $connection === '') {
                config(['ai-agents.catalog.connection' => 'central']);
            }
        }

        // Firewall operations (Phase 2 of the plan): train, evaluate and
        // status over the classifier artifact.
        $this->app->singleton(FirewallTrainCommand::class);
        $this->app->singleton(FirewallEvaluateCommand::class);
        $this->app->singleton(FirewallStatusCommand::class);

        // RunsForEachTenant: per-tenant command runner.  Resolved from a
        // custom config class-string when provided, otherwise StanclTenantRunner
        // (when the class exists in database mode) or SingleContextRunner.
        $this->app->singleton(RunsForEachTenant::class, function (Application $app): RunsForEachTenant {
            $customRunner = config('ai-agents.tenant.runner');

            if (is_string($customRunner) && $customRunner !== '' && class_exists($customRunner)) {
                /** @var RunsForEachTenant $customRunnerResolved */
                $customRunnerResolved = $app->make($customRunner);

                return $customRunnerResolved;
            }

            // In database mode, use the stancl tenant runner when available.
            if ($this->isolations === TenantIsolation::Database && class_exists(Tenancy::class)) {
                return $app->make(StanclTenantRunner::class);
            }

            return $app->make(SingleContextRunner::class);
        });

        // Proposal subsystem: authorizer singleton, handler registry singleton,
        // and the decision service that composes both.
        $this->app->singleton(ProposalHandlerRegistry::class);

        $this->app->singleton(AuthorizesProposalDecisions::class, function (Application $app): AuthorizesProposalDecisions {
            $customAuthorizer = config('ai-agents.proposals.authorizer');

            if (is_string($customAuthorizer) && $customAuthorizer !== '' && class_exists($customAuthorizer)) {
                if (! is_a($customAuthorizer, AuthorizesProposalDecisions::class, true)) {
                    throw new \InvalidArgumentException(
                        "Registered proposal authorizer [{$customAuthorizer}] must implement ".AuthorizesProposalDecisions::class.'.'
                    );
                }

                return $app->make($customAuthorizer);
            }

            return $app->make(OwnerOnlyProposalAuthorizer::class);
        });

        $this->app->singleton(ProposalDecisions::class, function (Application $app): ProposalDecisions {
            return new ProposalDecisions(
                $app->make(AuthorizesProposalDecisions::class),
                $app->make(ProposalHandlerRegistry::class),
            );
        });

        // Bridge between the SDK's native tool approvals and the package's
        // Action Proposals, so a host can use either as the transport while
        // keeping one authorisation/audit path.
        $this->app->singleton(SdkApprovalBridge::class, function (Application $app): SdkApprovalBridge {
            return new SdkApprovalBridge(
                proposalType: (string) config('ai-agents.proposals.sdk_approvals.type', 'sdk_approval'),
            );
        });

        $this->commands([
            FirewallTrainCommand::class,
            FirewallEvaluateCommand::class,
            FirewallStatusCommand::class,
            ModelsDevSyncCommand::class,
            UsageConsolidateCommand::class,
            PruneConversationsCommand::class,
            SyncAgentsCommand::class,
            SkillsSyncCommand::class,
            ExpireProposalsCommand::class,
        ]);
    }

    /**
     * Boot the package: migrations, publishable assets and host registrations.
     */
    public function boot(): void
    {
        $migrationsConfig = (array) config('ai-agents.migrations', []);
        $loadScoped = $migrationsConfig['load_scoped']
            ?? ($this->isolations !== TenantIsolation::Database);
        $loadCatalog = (bool) ($migrationsConfig['load_catalog'] ?? true);

        if ($loadScoped) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($loadCatalog) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations-catalog');
        }

        if ($this->isolations === TenantIsolation::Column) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations-column-tenant');
        }

        $this->publishes([
            __DIR__.'/../config/ai-agents.php' => config_path('ai-agents.php'),
        ], 'ai-agents-config');

        // Legacy tag: publishes everything (scoped + catalog) for backward
        // compatibility with hosts that run `--tag=ai-agents-migrations`.
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
            __DIR__.'/../database/migrations-catalog' => database_path('migrations'),
        ], 'ai-agents-migrations');

        // Scoped migrations only: for hosts in `database` mode that want to
        // publish into their tenant migration folder.
        $scopedPublishes = [
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ];

        if ($this->isolations === TenantIsolation::Column) {
            $scopedPublishes[__DIR__.'/../database/migrations-column-tenant'] = database_path('migrations');
        }

        $this->publishes($scopedPublishes, 'ai-agents-scoped-migrations');

        // Catalog migrations only: for central BD in `database` mode.
        $this->publishes([
            __DIR__.'/../database/migrations-catalog' => database_path('migrations'),
        ], 'ai-agents-catalog-migrations');

        // Legacy tenant-migrations tag (column mode only).
        if ($this->isolations === TenantIsolation::Column) {
            $this->publishes([
                __DIR__.'/../database/migrations-column-tenant' => database_path('migrations'),
            ], 'ai-agents-tenant-migrations');
        }

        $this->publishes([
            __DIR__.'/../stubs' => base_path('stubs/ai-agents'),
        ], 'ai-agents-stubs');
        $this->bindConversationStore();
        $this->registerHostComponents();
        $this->autoSyncAgents();
        $this->registerProposalHandlers();

        $this->scheduleModelsDevSync();
        $this->scheduleUsageConsolidation();
        $this->scheduleProposalsExpiry();
        $this->scheduleConversationPruning();
    }

    /**
     * Register the daily usage consolidation on the schedule.
     *
     * Runs after the models.dev sync (default 03:00) so prices are fresh
     * before the next day's runs, with overlap protection. Disable or move
     * it via config('ai-agents.usage').
     */
    /**
     * Register the conversation retention pruning on the schedule.
     *
     * Only active when conversations.retention.days is configured; a null
     * window means "keep until deleted" and needs no cron. Runs after the
     * usage consolidation with overlap protection. Conversation lifecycle is
     * independent from AiRun telemetry.
     */
    private function scheduleConversationPruning(): void
    {
        $days = config('ai-agents.conversations.retention.days');

        if ($days === null || $days === '') {
            return;
        }

        if (! (bool) config('ai-agents.conversations.retention.schedule_enabled', true)) {
            return;
        }

        $this->app->booted(function (Application $app): void {
            $schedule = $app->make(Schedule::class);

            $schedule->command('ai-agents:conversations:prune')
                ->dailyAt((string) config('ai-agents.conversations.retention.schedule_at', '04:00'))
                ->withoutOverlapping(120)
                ->onOneServer()
                ->runInBackground();
        });
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
     * Register the proposals expiry checker on the schedule.
     *
     * Runs at config('ai-agents.proposals.expire_every') (default 'everyFiveMinutes')
     * with overlap protection. Hosts can disable it via
     * config('ai-agents.proposals.expire_schedule_enabled').
     */
    /**
     * Bind the package conversation store over Laravel AI's default one.
     *
     * Bound in boot() so it wins over the SDK provider's own binding
     * (registered during register()) and the config is final. When memory is
     * disabled the SDK default store remains in place.
     */
    private function bindConversationStore(): void
    {
        // Always take over the binding so the decision is made at resolution
        // time (after host config is final): the package store when memory is
        // enabled, otherwise the SDK's own default store. Bound in boot() so
        // it wins over the SDK provider's binding.
        $this->app->singleton(
            ConversationStore::class,
            function (Application $app): ConversationStore {
                if ((bool) config('ai-agents.conversations.enabled', false)) {
                    return $app->make(PackageConversationStore::class);
                }

                return new DatabaseConversationStore(
                    config('ai.conversations.connection'),
                );
            },
        );
    }

    /**
     * Register the proposals expiry checker on the schedule.
     *
     * Runs at config('ai-agents.proposals.expire_every') (default 'everyFiveMinutes')
     * with overlap protection. Hosts can disable it via
     * config('ai-agents.proposals.expire_schedule_enabled').
     */
    private function scheduleProposalsExpiry(): void
    {
        if (! (bool) config('ai-agents.proposals.expire_schedule_enabled', true)) {
            return;
        }

        $this->app->booted(function (Application $app): void {
            $schedule = $app->make(Schedule::class);
            $frequency = (string) config('ai-agents.proposals.expire_every', 'everyFiveMinutes');

            $event = $schedule->command('ai-agents:proposals:expire');

            // Dynamic frequency: call the method if it exists on the schedule event,
            // otherwise fall back to everyFiveMinutes.
            if (method_exists($event, $frequency)) {
                $event->{$frequency}();
            } else {
                // Handle cron-like expressions or other patterns.
                $event->cron($frequency);
            }

            $event
                ->withoutOverlapping(10)
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
     *
     * Skipped in `database` isolation mode: the boot runs against the central
     * BD where the ai_agents table does not live.  Hosts should trigger the
     * synchroniser inside their tenant-creation pipeline (e.g. via
     * `ai-agents:sync-agents` or calling `AgentSynchronizer::sync()` in a
     * tenancy hook).  The per-run self-heal in AgentConfigurationResolver
     * handles the rest.
     */
    private function autoSyncAgents(): void
    {
        if ($this->isolations === TenantIsolation::Database) {
            return;
        }

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
     * Register proposal handlers declared in config('ai-agents.proposals.handlers').
     *
     * Idempotent: re-booting simply re-registers (same instances are bound
     * in the container so the second pass uses the existing singleton).
     */
    private function registerProposalHandlers(): void
    {
        $registry = $this->app->make(ProposalHandlerRegistry::class);

        // Config values are untyped (mixed); narrow each entry to a handler
        // class-string so the container `make()` call is statically sound.
        /** @var array<class-string> $handlerClasses */
        $handlerClasses = (array) config('ai-agents.proposals.handlers', []);

        foreach ($handlerClasses as $handlerClass) {
            if (! is_string($handlerClass) || ! is_a($handlerClass, ProposalHandler::class, true)) {
                throw new \InvalidArgumentException(
                    "Registered proposal handler [{$handlerClass}] must implement ".ProposalHandler::class.'.'
                );
            }

            /** @var ProposalHandler $handler */
            $handler = $this->app->make($handlerClass);

            $registry->register($handler);
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

        /** @var array<class-string> $skillProviderClasses */
        $skillProviderClasses = (array) config('ai-agents.skills.providers', []);

        if ($skillProviderClasses !== [] && (bool) config('ai-agents.skills.enabled', true)) {
            $skillRegistry = $this->app->make(SkillRegistry::class);

            foreach ($skillProviderClasses as $providerClass) {
                if (! is_a($providerClass, ProvidesSkills::class, true)) {
                    throw new \InvalidArgumentException(
                        "Registered AI skill provider [{$providerClass}] must implement ".ProvidesSkills::class.'.',
                    );
                }

                $skillRegistry->registerProvider($this->app->make($providerClass));
            }
        }
    }
}
