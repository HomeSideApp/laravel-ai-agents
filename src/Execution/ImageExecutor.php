<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Execution;

use HomeSide\AiAgents\Contracts\InspectsPrompt;
use HomeSide\AiAgents\Enums\PrivacyLevel;
use HomeSide\AiAgents\Exceptions\PrivacyViolationException;
use HomeSide\AiAgents\Exceptions\PromptInjectionBlockedException;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiRun;
use HomeSide\AiAgents\Providers\AiProviderEndpointPolicy;
use HomeSide\AiAgents\Providers\ProviderResolver;
use RuntimeException;

final class ImageExecutor
{
    public function __construct(
        private readonly ProviderResolver $providers,
        private readonly AiProviderEndpointPolicy $endpoints,
        private readonly InspectsPrompt $inspector,
        private readonly ExecutionRecorder $recorder,
    ) {}

    /**
     * @template T
     *
     * @param  callable(AiProvider): T  $transport
     * @return T
     */
    public function execute(
        AiExecutionContextData $context,
        string $prompt,
        int $count,
        callable $transport,
        ?AiRun $existingRun = null,
        ?PrivacyLevel $requiredPrivacyLevel = null,
    ): mixed {
        $provider = $this->providers->resolve('image_generation', $context->userId, $context->tenantId);

        if ($provider === null) {
            throw new RuntimeException('No image generation provider is available.');
        }

        $privacy = PrivacyLevel::fromColumn($provider->privacy_level);

        if ($requiredPrivacyLevel !== null && ! $privacy->isAtLeast($requiredPrivacyLevel)) {
            throw new PrivacyViolationException('The image provider does not meet the required privacy level.');
        }

        $this->endpoints->validate($provider->base_url);

        if ($this->inspector->inspect('user_message', $prompt, $context)->blocks()) {
            throw new PromptInjectionBlockedException('The AI firewall blocked the image prompt.');
        }

        if ($existingRun !== null && ($existingRun->user_id !== $context->userId
            || $existingRun->household_id !== $context->tenantId)) {
            throw new RuntimeException('The image run does not belong to this execution context.');
        }

        $run = $existingRun ?? $this->recorder->startRun($context, 'image_generation.generate', $prompt, privacyLevel: $privacy);

        if ($existingRun !== null) {
            $this->recorder->applyRetention($run, $privacy);
        }

        $run->update(['provider_id' => $provider->id, 'provider_name' => $provider->name, 'model_name' => $provider->model]);
        $startedAt = microtime(true);

        try {
            $images = $transport($provider);
            $duration = (int) round((microtime(true) - $startedAt) * 1000);
            $run->update([
                'status' => 'ok',
                'duration_ms' => $duration,
                'metadata' => [...($run->metadata ?? []), 'generated_images_count' => is_countable($images) ? count($images) : $count],
            ]);
            $this->recorder->recordAttempt($run, 1, $provider->name, $provider->model, 'ok', $duration);

            return $images;
        } catch (\Throwable $error) {
            $duration = (int) round((microtime(true) - $startedAt) * 1000);
            $this->recorder->recordError($run, 'image_generation_failed');
            $run->update(['duration_ms' => $duration]);
            $this->recorder->recordAttempt($run, 1, $provider->name, $provider->model, 'error', $duration, 'image_generation_failed');

            throw $error;
        }
    }
}
