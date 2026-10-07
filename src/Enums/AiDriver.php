<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Enums;

/**
 * Providers supported by the AI infrastructure.
 *
 * Each driver maps directly to a driver of the Laravel AI SDK. The enum
 * mirrors the SDK's own Lab enum so every connectable provider is
 * first-class here; unknown provider types still map to
 * 'openai-compatible', so hosts using generic OpenAI-protocol endpoints
 * (NaN Builders, Together AI, ...) work without declaring a dedicated
 * driver.
 */
enum AiDriver: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    case Ollama = 'ollama';
    case OpenAICompatible = 'openai-compatible';
    case OpenRouter = 'openrouter';
    case XAI = 'xai';
    case Groq = 'groq';
    case DeepSeek = 'deepseek';
    case Mistral = 'mistral';
    case Cohere = 'cohere';
    case TypeSafe = 'typesafe';
    case Azure = 'azure';
    case Bedrock = 'bedrock';
    case ElevenLabs = 'eleven';
    case Jina = 'jina';
    case VoyageAI = 'voyageai';

    /**
     * Get the human-readable label for the driver.
     */
    public function label(): string
    {
        return match ($this) {
            self::OpenAI => 'OpenAI',
            self::Anthropic => 'Anthropic',
            self::Gemini => 'Google Gemini',
            self::Ollama => 'Ollama (local)',
            self::OpenAICompatible => 'OpenAI Compatible',
            self::OpenRouter => 'OpenRouter',
            self::XAI => 'xAI (Grok)',
            self::Groq => 'Groq',
            self::DeepSeek => 'DeepSeek',
            self::Mistral => 'Mistral',
            self::Cohere => 'Cohere',
            self::TypeSafe => 'TypeSafe',
            self::Azure => 'Azure OpenAI',
            self::Bedrock => 'AWS Bedrock',
            self::ElevenLabs => 'ElevenLabs',
            self::Jina => 'Jina AI',
            self::VoyageAI => 'Voyage AI',
        };
    }

    /**
     * The SDK driver identifier used by DynamicProviderRegistrar.
     */
    public function toSdkDriver(): string
    {
        return $this->value;
    }

    /**
     * Get the default privacy level for a known driver.
     *
     * Hosts seed provider rows with this value so privacy gating and
     * fallback policies work out of the box. Local/self-hosted drivers map
     * to Local; generic OpenAI-compatible endpoints stay Unknown because the
     * package cannot know whether the host points them at a LAN box or a
     * cloud gateway.
     */
    public function defaultPrivacyLevel(): PrivacyLevel
    {
        return match ($this) {
            self::Ollama => PrivacyLevel::Local,
            self::OpenAICompatible => PrivacyLevel::Unknown,
            self::OpenAI, self::Anthropic, self::Gemini, self::Groq,
            self::XAI, self::DeepSeek, self::Mistral, self::OpenRouter,
            self::Cohere, self::TypeSafe, self::Azure, self::Bedrock,
            self::ElevenLabs, self::Jina, self::VoyageAI => PrivacyLevel::Cloud,
        };
    }

    /**
     * Resolve a stored driver/type value into an AiDriver. Unknown values
     * fall back to the generic OpenAI-compatible driver.
     */
    public static function fromColumn(?string $value): self
    {
        return $value !== null ? (self::tryFrom($value) ?? self::OpenAICompatible) : self::OpenAICompatible;
    }

    /**
     * Baseline model capabilities per driver, used when the provider's
     * configuration does not declare model_capabilities explicitly. Hosts
     * override per provider in the admin UI — these defaults are safe
     * assumptions, not guesses about specific deployments.
     *
     * @return array{reasoning: bool, tool_call_format: string|null}
     */
    public function defaultCapabilities(): array
    {
        return match ($this) {
            self::DeepSeek => ['reasoning' => true, 'tool_call_format' => 'native'],
            // OpenAI-compatible endpoints serve arbitrary community models
            // (often reasoning builds like qwen): assume reasoning is on so
            // token budgets are sized generously instead of truncating.
            self::OpenAICompatible => ['reasoning' => true, 'tool_call_format' => 'native'],
            default => ['reasoning' => false, 'tool_call_format' => 'native'],
        };
    }

    /**
     * Convert a legacy provider `type` value into an AiDriver. Alias of
     * fromColumn() kept for hosts migrating from their own driver enums.
     */
    public static function fromLegacyType(?string $type): self
    {
        return self::fromColumn($type);
    }
}
