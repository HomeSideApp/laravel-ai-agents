<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Proposals;

use HomeSide\AiAgents\Contracts\ProposalHandler;

/**
 * Singleton registry for proposal handlers.
 *
 * Maps proposal `type` strings to their corresponding `ProposalHandler`
 * implementations. Registered by the service provider from config.
 */
final class ProposalHandlerRegistry
{
    /**
     * @var array<string, ProposalHandler>
     */
    private array $handlers = [];

    /**
     * Register a handler.
     *
     * @param  ProposalHandler  $handler  The handler to register.
     *
     * @throws \InvalidArgumentException If the handler type is duplicated or invalid.
     */
    public function register(ProposalHandler $handler): void
    {
        $type = $handler->type();
        $class = $handler::class;

        if (! preg_match('/^[a-z0-9_]+$/', $type)) {
            throw new \InvalidArgumentException(
                "Handler type [{$type}] must match ^[a-z0-9_]+$. Registered by: {$class}."
            );
        }

        if (isset($this->handlers[$type])) {
            $existing = $this->handlers[$type]::class;

            throw new \InvalidArgumentException(
                "Duplicate handler type [{$type}] (already registered as {$existing}, trying to register {$class})."
            );
        }

        $this->handlers[$type] = $handler;
    }

    /**
     * Check whether a handler is registered for the given type.
     *
     * @param  string  $type  The proposal type.
     */
    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /**
     * Get the handler for a type, or null if not found.
     *
     * @param  string  $type  The proposal type.
     */
    public function get(string $type): ?ProposalHandler
    {
        return $this->handlers[$type] ?? null;
    }

    /**
     * Get all registered handlers.
     *
     * @return array<string, ProposalHandler>
     */
    public function all(): array
    {
        return $this->handlers;
    }

    /**
     * Singleton instance (resolved from container).
     */
    public static function instance(): self
    {
        return app(self::class);
    }
}
