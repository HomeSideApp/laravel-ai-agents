<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Context\Providers;

use HomeSide\AiAgents\Context\ContextProvider;
use HomeSide\AiAgents\Execution\AiExecutionContextData;

/**
 * Context provider: user data (locale, timezone, preferences).
 *
 * Resolves the user model from the package configuration so it works with
 * any host application.
 */
class UserContextProvider implements ContextProvider
{
    public function key(): string
    {
        return 'user';
    }

    public function provide(AiExecutionContextData $context): array
    {
        $userModel = config('ai-agents.user_model');
        $user = $userModel !== null ? $userModel::find($context->userId) : null;

        return [
            'key' => 'user',
            'data' => [
                'id' => $context->userId,
                'locale' => $context->locale,
                'timezone' => $context->timezone,
                'name' => $user?->name,
            ],
        ];
    }
}
