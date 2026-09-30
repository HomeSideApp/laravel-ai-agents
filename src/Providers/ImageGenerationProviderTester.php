<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Providers;

use Illuminate\Support\Facades\Http;

/**
 * Tests image generation provider connectivity.
 *
 * Uses the OpenAI-compatible /v1/images/generations endpoint instead of
 * chat completions, since image models don't support text generation.
 */
class ImageGenerationProviderTester
{
    /**
     * Test an image generation provider configuration.
     *
     * @param  array{base_url: string, api_key: string, model: string}  $config
     */
    public function testConfig(array $config): ProviderTestData
    {
        $start = microtime(true);

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$config['api_key'],
                    'Content-Type' => 'application/json',
                ])
                ->post($config['base_url'].'/images/generations', [
                    'model' => $config['model'],
                    'prompt' => 'A simple test icon, white background',
                    'n' => 1,
                    'size' => '256x256',
                    'response_format' => 'url',
                ]);

            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $data = $response->json('data', []);

                if ($data !== []) {
                    return ProviderTestData::ok($latency, 'Image generation successful');
                }

                return ProviderTestData::error('Image generation returned no images.');
            }

            $errorBody = $response->json('error.message', $response->body());

            return ProviderTestData::error($this->parseErrorMessage($errorBody, $response->status()));
        } catch (\Throwable $e) {
            return ProviderTestData::error("Connection failed: {$e->getMessage()}");
        }
    }

    /**
     * Turn an HTTP error response into an actionable message.
     *
     * Maps the common image-endpoint status codes to specific guidance
     * (credentials, model access, endpoint existence, rate limiting) and
     * falls back to the raw provider body for anything unexpected.
     *
     * @param  string  $body  The raw error body returned by the provider.
     * @param  int  $statusCode  The HTTP status code of the failed response.
     * @return string A message suitable for surfacing to admins configuring
     *                the provider.
     */
    private function parseErrorMessage(string $body, int $statusCode): string
    {
        return match ($statusCode) {
            401 => 'Authentication failed: check the API key.',
            403 => 'Access forbidden: the API key has no access to this model.',
            404 => 'Model or endpoint not found.',
            429 => 'Rate limit exceeded, try again later.',
            default => "Connection failed: {$body}",
        };
    }
}
