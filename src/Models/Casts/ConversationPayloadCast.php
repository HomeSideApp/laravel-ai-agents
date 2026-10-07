<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models\Casts;

use HomeSide\AiAgents\Privacy\UserContentCipher;
use HomeSide\AiAgents\Privacy\UserContentKeyManager;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Encrypt/decrypt cast for the conversation message payload column.
 *
 * The payload holds everything needed to replay a full Laravel AI turn
 * (content, attachments, steps with tool calls/results, meta). Steps and
 * tool arguments/results can carry highly sensitive data, so the WHOLE
 * payload is protected, not just the user's text.
 *
 * Storage follows the installation's conversations.storage.mode:
 *
 * - encrypted (default): the JSON payload is envelope-encrypted with the
 *   owner's per-user DEK before it reaches the database; reads decrypt
 *   transparently. After a crypto-shred the ciphertext is unrecoverable
 *   and reads degrade to null.
 * - plain: the JSON is stored as-is (tests/diagnostics only).
 *
 * Decoded shape:
 * {
 *     "content": "...",
 *     "attachments": [],
 *     "steps": [],
 *     "meta": {}
 * }
 *
 * @implements CastsAttributes<array<string, mixed>|null, array<string, mixed>|string|null>
 */
class ConversationPayloadCast implements CastsAttributes
{
    /**
     * Transform the stored value into the decoded payload array.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $stored = (string) $value;
        $cipher = app(UserContentCipher::class);

        if ($cipher->isCiphertext($stored)) {
            try {
                $dek = app(UserContentKeyManager::class)
                    ->keyFor((string) $model->getAttribute('user_id'));

                $stored = $cipher->decrypt($stored, $dek);
            } catch (RuntimeException) {
                // Shredded key or corrupt ciphertext: content is gone.
                return null;
            }
        }

        $decoded = json_decode($stored, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Transform the payload array into its stored form.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $json = is_string($value)
            ? $value
            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            return null;
        }

        if (! self::encryptionEnabled() || $model->getAttribute('user_id') === null) {
            return $json;
        }

        $dek = app(UserContentKeyManager::class)
            ->keyFor((string) $model->getAttribute('user_id'));

        return app(UserContentCipher::class)->encrypt($json, $dek);
    }

    /**
     * Whether conversation payloads are stored encrypted for this install.
     */
    public static function encryptionEnabled(): bool
    {
        return config('ai-agents.conversations.storage.mode', 'encrypted') === 'encrypted';
    }
}
