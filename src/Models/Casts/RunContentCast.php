<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models\Casts;

use HomeSide\AiAgents\Privacy\UserContentCipher;
use HomeSide\AiAgents\Privacy\UserContentKeyManager;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Tolerant encrypt/decrypt cast for the run content columns
 * (user_message, reply).
 *
 * Behaviour is driven by the row's content_mode attribute:
 *
 * - encrypted: envelope encryption with the run owner's data key on
 *   write; transparent decryption on read. Ciphertext is prefixed with
 *   "enc:v1:" so legacy rows (plaintext, redacted digests) never break
 *   reads — unknown values pass through untouched.
 * - plain / redacted / none (or legacy rows without content_mode): the
 *   value round-trips unchanged.
 *
 * The cryptographic work lives in {@see UserContentCipher}; this cast only
 * decides when it applies (content_mode + owner present). The cast never
 * throws on read: unreadable ciphertext (e.g. after a crypto-shred)
 * degrades to the raw stored value, so history pages keep rendering their
 * non-content fields.
 */
/**
 * @implements CastsAttributes<string|null, string|null>
 */
class RunContentCast implements CastsAttributes
{
    /**
     * Whether the cast is active for this model instance: content_mode
     * must be present and 'encrypted', and the row must have an owner.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function isActive(Model $model, array $attributes): bool
    {
        $mode = $attributes['content_mode'] ?? $model->getAttribute('content_mode');

        if ($mode !== 'encrypted') {
            return false;
        }

        return $model->getAttribute('user_id') !== null;
    }

    /**
     * Transform the stored value into readable content.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $cipher = app(UserContentCipher::class);

        if (! $this->isActive($model, $attributes) || ! $cipher->isCiphertext((string) $value)) {
            return $value;
        }

        try {
            $manager = app(UserContentKeyManager::class);
            $dek = $manager->keyFor((string) $model->getAttribute('user_id'));

            return $cipher->decrypt((string) $value, $dek);
        } catch (RuntimeException) {
            // Shredded key or broken ciphertext: degrade to the raw value.
            return $value;
        }
    }

    /**
     * Transform the given value into its stored form.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $this->isActive($model, $attributes)) {
            return (string) $value;
        }

        $manager = app(UserContentKeyManager::class);
        $dek = $manager->keyFor((string) $model->getAttribute('user_id'));

        return app(UserContentCipher::class)->encrypt((string) $value, $dek);
    }
}
