<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Providers;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Enums\PrivacyLevel;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiProviderModel;
use HomeSide\AiAgents\Providers\ProviderModelDefaults;
use HomeSide\AiAgents\Providers\ProviderResolver;
use HomeSide\AiAgents\Tests\TestCase;

/**
 * requiredPrivacyLevel participates in selection: a lower-scope provider that
 * satisfies it wins over a higher-scope one that does not, instead of failing
 * after picking the wrong provider.
 */
final class ProviderPrivacySelectionTest extends TestCase
{
    private function makeProvider(array $overrides = []): AiProvider
    {
        return AiProvider::createValidated(array_merge([
            'name' => 'P'.uniqid(),
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'api_key' => 'sk-test-1234',
            'module' => 'assistant',
            'privacy_level' => 'cloud',
            'fallback_policy' => 'allow_cloud',
        ], $overrides));
    }

    private function addEmbeddingDefault(AiProvider $provider, string $model = 'embed'): AiProviderModel
    {
        $row = AiProviderModel::create([
            'ai_provider_id' => $provider->id,
            'model' => $model,
            'enabled' => true,
            'capabilities_override' => ['embeddings'],
            'embedding_dimensions' => 1536,
        ]);

        $this->app->make(ProviderModelDefaults::class)->set($provider, Capability::Embeddings, $row);

        return $row;
    }

    public function test_required_privacy_selects_a_lower_scope_that_satisfies_it(): void
    {
        $user = $this->makeProvider(['scope' => ['user' => 1], 'privacy_level' => 'cloud']);
        $this->addEmbeddingDefault($user, 'cloud-embed');

        $system = $this->makeProvider(['scope' => 'global', 'privacy_level' => 'local']);
        $this->addEmbeddingDefault($system, 'local-embed');

        $resolved = $this->app->make(ProviderResolver::class)->resolveForCapability(
            'assistant',
            Capability::Embeddings,
            userId: 1,
            requiredPrivacyLevel: PrivacyLevel::Local,
        );

        $this->assertNotNull($resolved);
        $this->assertSame($system->id, $resolved->id);
    }

    public function test_without_requirement_the_user_anchor_wins(): void
    {
        $user = $this->makeProvider(['scope' => ['user' => 1], 'privacy_level' => 'cloud']);
        $this->addEmbeddingDefault($user, 'cloud-embed');

        $system = $this->makeProvider(['scope' => 'global', 'privacy_level' => 'local']);
        $this->addEmbeddingDefault($system, 'local-embed');

        $resolved = $this->app->make(ProviderResolver::class)->resolveForCapability(
            'assistant',
            Capability::Embeddings,
            userId: 1,
        );

        $this->assertNotNull($resolved);
        $this->assertSame($user->id, $resolved->id);
    }

    public function test_no_candidate_satisfies_privacy_returns_null_but_reports_candidates(): void
    {
        $user = $this->makeProvider(['scope' => ['user' => 1], 'privacy_level' => 'cloud']);
        $this->addEmbeddingDefault($user, 'cloud-embed');

        $resolver = $this->app->make(ProviderResolver::class);

        $this->assertNull($resolver->resolveForCapability(
            'assistant',
            Capability::Embeddings,
            userId: 1,
            requiredPrivacyLevel: PrivacyLevel::Local,
        ));

        // A candidate DOES exist that satisfies everything except privacy.
        $this->assertTrue($resolver->hasCandidateRejectedOnlyByRequiredPrivacy(
            'assistant',
            Capability::Embeddings,
            userId: 1,
        ));
    }

    public function test_candidate_blocked_by_fallback_policy_is_not_reported_as_privacy(): void
    {
        // Anchor: local_only, no embeddings.
        $anchor = $this->makeProvider([
            'scope' => ['user' => 1],
            'privacy_level' => 'local',
            'fallback_policy' => 'local_only',
        ]);

        // Candidate: self-hosted embeddings, which fallback policy forbids.
        $candidate = $this->makeProvider([
            'scope' => 'global',
            'privacy_level' => 'self_hosted',
        ]);
        $this->addEmbeddingDefault($candidate, 'self-hosted-embed');

        $resolver = $this->app->make(ProviderResolver::class);

        $this->assertNull($resolver->resolveForCapability(
            'assistant',
            Capability::Embeddings,
            userId: 1,
            requiredPrivacyLevel: PrivacyLevel::SelfHosted,
        ));

        // The only candidate is rejected by the fallback policy, NOT by the
        // privacy requirement, so it must not be reported as a privacy issue.
        $this->assertFalse($resolver->hasCandidateRejectedOnlyByRequiredPrivacy(
            'assistant',
            Capability::Embeddings,
            userId: 1,
        ));
    }

    public function test_pinned_cloud_provider_is_rejected_for_a_local_requirement(): void
    {
        $cloud = $this->makeProvider(['scope' => 'global', 'privacy_level' => 'cloud']);
        $model = $this->addEmbeddingDefault($cloud);

        $this->assertNull($this->app->make(ProviderResolver::class)->resolveExplicitForCapability(
            providerId: $cloud->id,
            module: 'assistant',
            capability: Capability::Embeddings,
            userId: 1,
            pinnedModelId: $model->id,
            requiredPrivacyLevel: PrivacyLevel::Local,
        ));
    }
}
