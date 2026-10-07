<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Fixtures;

use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * Minimal TextProvider stand-in so tests can build real AgentPrompt objects
 * (which type-hint TextProvider) without a live provider.
 *
 * Only the surface needed to construct prompts is implemented; anything that
 * would actually call a model throws.
 */
final class FakeTextProvider implements TextProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function driver(): string
    {
        return 'fake';
    }

    /** @return array<string, mixed> */
    public function providerCredentials(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function additionalConfiguration(): array
    {
        return [];
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): static
    {
        return $this;
    }

    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        throw new \BadMethodCallException('FakeTextProvider::prompt() is not used in tests.');
    }

    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        throw new \BadMethodCallException('FakeTextProvider::stream() is not used in tests.');
    }

    public function useTextGateway(StepTextGateway $gateway): self
    {
        return $this;
    }

    public function textGenerationLoop(): TextGenerationLoop
    {
        throw new \BadMethodCallException('FakeTextProvider::textGenerationLoop() is not used in tests.');
    }

    public function defaultTextModel(): string
    {
        return 'fake-model';
    }

    public function cheapestTextModel(): string
    {
        return 'fake-model';
    }

    public function smartestTextModel(): string
    {
        return 'fake-model';
    }
}
