<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Data;

use Illuminate\Validation\ValidationException;

/**
 * Standardised data object for creating an AI provider.
 *
 * Covers every column on the `ai_providers` table — basic connection fields
 * plus the catalog-aligned spec columns mirrored from models.dev. Host
 * controllers build this DTO from validated request data and pass it to
 * `AiProvider::createValidated()` or a host action that wraps it.
 *
 * The DTO is a plain data carrier: all validation and security checks
 * happen in `AiProvider::createValidated()` / `ValidatesOnWrite`.
 */
final readonly class CreateAiProviderData
{
    /**
     * Build from a raw request / validated array.
     *
     * Unknown keys are silently ignored so the host can merge extra
     * fields without breaking the DTO.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (isset($data['module'], $data['modules'])) {
            throw ValidationException::withMessages(['modules' => 'Use either module or modules, not both.']);
        }

        return new self(
            name: (string) ($data['name'] ?? ''),
            type: (string) ($data['type'] ?? $data['driver'] ?? 'openai-compatible'),
            driver: $data['driver'] ?? null,
            base_url: (string) ($data['base_url'] ?? ''),
            model: (string) ($data['model'] ?? ''),
            api_key: (string) ($data['api_key'] ?? ''),
            module: (string) ($data['module'] ?? $data['modules'][0] ?? 'general'),
            modules: $data['modules'] ?? [],
            enabled: (bool) ($data['enabled'] ?? true),
            configuration: (array) ($data['configuration'] ?? []),
            privacy_level: $data['privacy_level'] ?? null,
            fallback_policy: $data['fallback_policy'] ?? null,
            // Catalog-aligned spec columns.
            family: $data['family'] ?? null,
            description: $data['description'] ?? null,
            attachment: (bool) ($data['attachment'] ?? false),
            reasoning: (bool) ($data['reasoning'] ?? false),
            reasoning_options: $data['reasoning_options'] ?? null,
            tool_call: (bool) ($data['tool_call'] ?? false),
            structured_output: (bool) ($data['structured_output'] ?? false),
            temperature: (bool) ($data['temperature'] ?? false),
            open_weights: (bool) ($data['open_weights'] ?? false),
            modalities_input: $data['modalities_input'] ?? null,
            modalities_output: $data['modalities_output'] ?? null,
            context_window: isset($data['context_window']) ? (int) $data['context_window'] : null,
            max_input_tokens: isset($data['max_input_tokens']) ? (int) $data['max_input_tokens'] : null,
            max_output_tokens: isset($data['max_output_tokens']) ? (int) $data['max_output_tokens'] : null,
            cost_input: $data['cost_input'] ?? null,
            cost_output: $data['cost_output'] ?? null,
            cost_cache_read: $data['cost_cache_read'] ?? null,
            cost_cache_write: $data['cost_cache_write'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $configuration  Additional provider configuration.
     * @param  list<string>|null  $modalities_input  Input modalities (text, image, audio, video).
     * @param  list<string>|null  $modalities_output  Output modalities.
     * @param  list<array<string, mixed>>|null  $reasoning_options  Reasoning configuration options.
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $driver,
        public string $base_url,
        public string $model,
        public string $api_key,
        public string $module = 'general',
        public bool $enabled = true,
        public array $configuration = [],
        public ?string $privacy_level = null,
        public ?string $fallback_policy = null,
        // Catalog-aligned spec columns.
        public ?string $family = null,
        public ?string $description = null,
        public bool $attachment = false,
        public bool $reasoning = false,
        public ?array $reasoning_options = null,
        public bool $tool_call = false,
        public bool $structured_output = false,
        public bool $temperature = false,
        public bool $open_weights = false,
        public ?array $modalities_input = null,
        public ?array $modalities_output = null,
        public ?int $context_window = null,
        public ?int $max_input_tokens = null,
        public ?int $max_output_tokens = null,
        public ?string $cost_input = null,
        public ?string $cost_output = null,
        public ?string $cost_cache_read = null,
        public ?string $cost_cache_write = null,
        /** @var list<string> */
        public array $modules = [],
    ) {}

    /**
     * Convert to an array suitable for `AiProvider::create()` / `createValidated()`.
     *
     * Null values are excluded so `createValidated()` applies column defaults.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $attributes = get_object_vars($this);

        if ($this->modules !== []) {
            unset($attributes['module']);
        } else {
            unset($attributes['modules']);
        }

        return array_filter(
            $attributes,
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
