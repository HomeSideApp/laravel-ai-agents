<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Execution;

/**
 * Execution context passed to an agent.
 *
 * Contains all the information needed to run an agent, including user
 * identity, optional tenant (household/team/workspace — whatever the host
 * calls it), locale, timezone, etc. The tenant is only meaningful when
 * tenant support is enabled in config('ai-agents.tenant.enabled').
 */
final readonly class AiExecutionContextData
{
    /**
     * @param  int|string  $userId  The host user identifier (integer or UUID).
     * @param  int|string|null  $tenantId  The optional tenant identifier (integer or UUID).
     * @param  int|string|null  $conversationId  The optional conversation identifier.
     * @param  list<int|string>  $participantUserIds
     */
    public function __construct(
        public int|string $userId,
        public int|string|null $tenantId = null,
        public int|string|null $conversationId = null,
        public string $locale = 'en-US',
        public string $timezone = 'UTC',
        public array $participantUserIds = [],
        public ?string $requestId = null,
        /** @var array<string, mixed> */
        public array $extra = [],
    ) {}

    /**
     * Create from an HTTP request.
     */
    public static function fromRequest(
        int|string $userId,
        int|string|null $tenantId = null,
        int|string|null $conversationId = null,
    ): self {
        $request = request();

        return new self(
            userId: $userId,
            tenantId: $tenantId,
            conversationId: $conversationId,
            locale: $request->getLocale(),
            timezone: $request->header('X-Timezone', 'UTC'),
        );
    }

    /**
     * Serialize to an array for logging/debugging.
     *
     * @return array{user_id: int|string, tenant_id: int|string|null, conversation_id: int|string|null, locale: string, timezone: string}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantId,
            'conversation_id' => $this->conversationId,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
        ];
    }
}
