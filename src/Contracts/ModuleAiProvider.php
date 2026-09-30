<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Contracts;

/**
 * Contract that a module must implement to integrate its AI agents
 * and default configuration with the provider resolver.
 */
interface ModuleAiProvider
{
    /**
     * Get the module identifier this provider belongs to.
     *
     * Modules are plain strings so hosts can declare domain-specific
     * modules ('recipes', 'economy', ...) without touching package code.
     * Unknown module strings fall back to the configured
     * config('ai-agents.fallback_module') during provider resolution.
     */
    public function module(): string;

    /**
     * Agents this module needs.
     *
     * @return array<string, array{
     *     label: string,
     *     system_prompt: string,
     *     description: string|null,
     *     parameters: array{temperature?: float, max_tokens?: int}|null
     * }>
     */
    public function agents(): array;

    /**
     * @return array<string, mixed>
     */
    public function defaultConfiguration(): array;
}
