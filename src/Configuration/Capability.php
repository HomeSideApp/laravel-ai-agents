<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Configuration;

/**
 * Capabilities supported by AI models/providers.
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

    /**
     * Known default capabilities for each SDK driver.
     * Used as a baseline when there is no detection or manual override.
     *
     * @return array<Capability>
     */
    public static function driverBaseline(string $driver): array
    {
        return match ($driver) {
            'openai', 'anthropic', 'gemini', 'azure', 'groq', 'xai', 'deepseek', 'mistral' => [
                self::Text,
                self::StructuredOutput,
                self::Tools,
                self::Streaming,
            ],
            'ollama' => [
                self::Text,
                self::StructuredOutput,
                self::Tools,
                self::Streaming,
            ],
            'openai-compatible' => [
                self::Text,
                self::Streaming,
            ],
            'openrouter' => [
                self::Text,
                self::StructuredOutput,
                self::Tools,
                self::Streaming,
            ],
            default => [
                self::Text,
            ],
        };
    }
}
