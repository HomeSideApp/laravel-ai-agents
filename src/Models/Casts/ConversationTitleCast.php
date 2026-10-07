<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models\Casts;

use HomeSide\AiAgents\Privacy\UserContentCipher;
use HomeSide\AiAgents\Privacy\UserContentKeyManager;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Tolerant encrypt/decrypt cast for the conversation title.
 *
 * A title can reveal private information ("abogado divorcio María"), so it
 * is treated as protected content exactly like the message payload. The
 * cast is tolerant with legacy rows: plaintext titles (or rows without the
 * cipher prefix) pass through unchanged, so enabling encryption on an
 * existing installation never breaks reads.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class ConversationTitleCast implements CastsAttributes
{
    /**
     * Transform the stored value into the readable title.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $stored = (string) $value;
        $cipher = app(UserContentCipher::class);

        if (! $cipher->isCiphertext($stored)) {
            return $stored;
        }

        try {
            $dek = app(UserContentKeyManager::class)
                ->keyFor((string) $model->getAttribute('user_id'));

            return $cipher->decrypt($stored, $dek);
        } catch (RuntimeException) {
            // Shredded key or broken ciphertext: degrade to the raw value.
            return $stored;
        }
    }

    /**
     * Transform the title into its stored form.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! ConversationPayloadCast::encryptionEnabled() || $model->getAttribute('user_id') === null) {
            return (string) $value;
        }

        $dek = app(UserContentKeyManager::class)
            ->keyFor((string) $model->getAttribute('user_id'));

        return app(UserContentCipher::class)->encrypt((string) $value, $dek);
    }
}
