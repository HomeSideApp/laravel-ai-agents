<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Fixtures;

use HomeSide\AiAgents\Contracts\AcceptsRuntimeConfiguration;
use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Stringable;

/**
 * DomainAgent satisfying the SDK Agent contract.
 *
 * prompt() returns a real AgentResponse so AiAgentManager runs to
 * completion (recording included) in feature tests. The reply text is
 * configurable per instance; the bound container instance decides.
 */
final class SdkBoundaryAgent extends DummyAgent implements AcceptsRuntimeConfiguration, Agent
{
    /**
     * The reply text prompt() returns; tests set it before binding.
     */
    public string $reply = 'boundary reply';

    public ?Usage $usage = null;

    /** @var array<string, int|float> */
    public array $runtimeConfiguration = [];

    /** @param array<string, int|float> $parameters */
    public function setRuntimeConfiguration(array $parameters): void
    {
        $this->runtimeConfiguration = $parameters;
    }

    public function instructions(): Stringable|string
    {
        return 'SDK boundary agent for tests.';
    }

    public function prompt(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        return new AgentResponse('test-invocation', $this->reply, $this->usage ?? new Usage, new Meta);
    }

    public function stream(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StreamableAgentResponse {
        throw new \BadMethodCallException('stream() is not used in tests.');
    }

    public function queue(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new \BadMethodCallException('queue() is not used in tests.');
    }

    public function broadcast(
        Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        bool $now = false,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new \BadMethodCallException('broadcast() is not used in tests.');
    }

    public function broadcastNow(
        Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new \BadMethodCallException('broadcastNow() is not used in tests.');
    }

    public function broadcastOnQueue(
        Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new \BadMethodCallException('broadcastOnQueue() is not used in tests.');
    }
}
