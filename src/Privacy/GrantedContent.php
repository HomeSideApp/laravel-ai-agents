<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Privacy;

use Illuminate\Support\Carbon;

/**
 * Decrypted run content returned to an authorised support reader.
 */
final readonly class GrantedContent
{
    public function __construct(
        public string $runId,
        public ?string $userMessage,
        public ?string $reply,
        public Carbon $grantedAt,
        public ?Carbon $expiresAt,
        public ?string $reference,
    ) {}

    /**
     * Serialize to an array (for API responses).
     *
     * @return array{run_id: string, user_message: string|null, reply: string|null, granted_at: string, expires_at: string|null, reference: string|null}
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'user_message' => $this->userMessage,
            'reply' => $this->reply,
            'granted_at' => $this->grantedAt->toIso8601String(),
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'reference' => $this->reference,
        ];
    }
}
