<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use HomeSide\AiAgents\Models\AiProvider;

use function Laravel\Ai\agent;

/**
 * Unified tester for AI providers.
 *
 * Uses the Laravel AI SDK for all tests, keeping a consistent and testable
 * implementation.
 */
class AiProviderTester
{
    /**
     * Create a provider connection tester.
     *
     * @param  DynamicProviderRegistrar  $registrar  The registrar used to expose provider configuration to the SDK.
     * @param  AiProviderEndpointPolicy  $endpointPolicy  The SSRF policy applied to endpoint URLs before testing.
     */
    public function __construct(
        private readonly DynamicProviderRegistrar $registrar,
        private readonly AiProviderEndpointPolicy $endpointPolicy,
    ) {}

    /**
     * Test a provider stored in the database.
     *
     * @throws \InvalidArgumentException When the endpoint violates the SSRF policy.
     */
    public function testProvider(AiProvider $provider): ProviderTestData
    {
        $this->endpointPolicy->validate($provider->base_url);

        return $this->test([
            'type' => $provider->type,
            'base_url' => $provider->base_url,
            'api_key' => $provider->api_key,
            'model' => $provider->model,
        ]);
    }

    /**
     * Test an unsaved provider configuration (for wizards/temporary configuration).
     *
     * @param  array{type: string, base_url: string, api_key: string, model: string}  $config  The unsaved provider configuration to test.
     */
    public function testConfig(array $config): ProviderTestData
    {
        $this->endpointPolicy->validate($config['base_url']);

        return $this->test($config);
    }

    /**
     * Test a provider using the Laravel AI SDK.
     *
     * @param  array{type: string, base_url: string, api_key: string, model: string}  $config  The normalized provider configuration to register and probe.
     */
    private function test(array $config): ProviderTestData
    {
        $start = microtime(true);

        try {
            $dynamicName = $this->registrar->registerFromData($config);

            $response = agent(
                instructions: 'You are a connection tester. Reply with the word OK and nothing else.',
            )->prompt(
                'Reply with the word OK.',
                provider: $dynamicName,
                model: $config['model'],
                timeout: 15,
            );

            $latency = (int) round((microtime(true) - $start) * 1000);

            return ProviderTestData::ok($latency, $response->text);
        } catch (\Exception $e) {
            return ProviderTestData::error("Connection failed: {$e->getMessage()}");
        }
    }
}
