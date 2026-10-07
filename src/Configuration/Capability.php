<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Configuration;

/**
 * Capabilities supported by AI models/providers.
 *
 * Split in two families:
 * - Model features the agent can rely on (text, structured output, tools...).
 * - Provider (built-in) tools the provider can run server-side on the
 *   model's behalf (web search, code execution...). The manager uses these
 *   to decide whether a provider tool is safe to send or must be skipped.
 */
enum Capability: string
{
    case Text = 'text';
    case StructuredOutput = 'structured_output';
    case Tools = 'tools';
    case Vision = 'vision';
    case Embeddings = 'embeddings';
    case Reranking = 'reranking';
    case Streaming = 'streaming';
    case AudioInput = 'audio_input';
    case AudioOutput = 'audio_output';

    // Provider (built-in) tools, executed server-side by the provider.
    case WebSearch = 'web_search';
    case WebFetch = 'web_fetch';
    case FileSearch = 'file_search';
    case ToolSearch = 'tool_search';
    case CodeExecution = 'code_execution';

    /**
     * The provider tools this enum can vouch for, keyed by the SDK's
     * provider-tool class basename.
     *
     * Used by AiAgentManager to map a declared provider tool back to a
     * Capability so it can be skipped when the resolved model does not
     * support it.
     *
     * @return array<string, self>
     */
    public static function providerToolMap(): array
    {
        return [
            'WebSearch' => self::WebSearch,
            'WebFetch' => self::WebFetch,
            'FileSearch' => self::FileSearch,
            'FileSearchQuery' => self::FileSearch,
            'ToolSearch' => self::ToolSearch,
            'CodeExecution' => self::CodeExecution,
        ];
    }

    /**
     * Known default capabilities for each SDK driver.
     * Used as a baseline when there is no detection or manual override.
     *
     * The provider-tool entries are deliberately conservative: only drivers
     * known to expose the corresponding server-side tool advertise it, so
     * the manager skips-built-in-tools default is correct out of the box.
     *
     * @return array<Capability>
     */
    public static function driverBaseline(string $driver): array
    {
        return match ($driver) {
            'openai' => [
                self::Text, self::StructuredOutput, self::Tools, self::Streaming,
                self::WebSearch, self::FileSearch, self::CodeExecution,
            ],
            'anthropic' => [
                self::Text, self::StructuredOutput, self::Tools, self::Streaming,
                self::WebSearch, self::WebFetch, self::CodeExecution, self::ToolSearch,
            ],
            'gemini' => [
                self::Text, self::StructuredOutput, self::Tools, self::Streaming,
                self::WebSearch, self::WebFetch, self::FileSearch, self::CodeExecution,
            ],
            'xai' => [
                self::Text, self::StructuredOutput, self::Tools, self::Streaming,
                self::WebSearch, self::FileSearch,
            ],
            'groq' => [
                self::Text, self::StructuredOutput, self::Tools, self::Streaming,
                self::WebSearch, self::CodeExecution,
            ],
            'azure' => [
                self::Text, self::StructuredOutput, self::Tools, self::Streaming,
                self::WebSearch, self::FileSearch, self::ToolSearch,
            ],
            'openrouter' => [
                self::Text, self::StructuredOutput, self::Tools, self::Streaming,
                self::WebSearch, self::WebFetch,
            ],
            'cohere' => [
                self::Text, self::StructuredOutput, self::Tools, self::Streaming,
                self::Embeddings, self::Reranking,
            ],
            'mistral' => [
                self::Text, self::StructuredOutput, self::Tools, self::Streaming,
            ],
            'deepseek' => [
                self::Text, self::StructuredOutput, self::Tools, self::Streaming,
            ],
            'ollama' => [
                self::Text, self::StructuredOutput, self::Tools, self::Streaming,
            ],
            'openai-compatible' => [
                self::Text, self::Streaming,
            ],
            default => [
                self::Text,
            ],
        };
    }
}
