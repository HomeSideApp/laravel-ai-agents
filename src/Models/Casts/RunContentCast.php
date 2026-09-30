<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Models\Casts;

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
 * The cast never throws on read: unreadable ciphertext (e.g. after a
 * crypto-shred) degrades to the raw stored value, so history pages keep
 * rendering their non-content fields.
 */
/**
 * @implements CastsAttributes<string|null, string|null>
 */
class RunContentCast implements CastsAttributes
{
    private const CIPHER_PREFIX = 'enc:v1:';

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

        if (! $this->isActive($model, $attributes) || ! str_starts_with($value, self::CIPHER_PREFIX)) {
            return $value;
        }

        try {
            $manager = app(UserContentKeyManager::class);
            $dek = $manager->keyFor((string) $model->getAttribute('user_id'));

            return $this->decrypt((string) $value, $dek);
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

        return $this->encrypt((string) $value, $dek);
    }

    /**
     * Encrypt plaintext with the user's DEK (AES-256-GCM via sodium) and
     * prefix the version marker.
     */
    private function encrypt(string $plaintext, string $dek): string
    {
        $key = $this->deriveKey($dek);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::CIPHER_PREFIX, // additional data binds the marker into the tag
            $nonce,
            $key,
        );

        return self::CIPHER_PREFIX.base64_encode($nonce.$cipher);
    }

    /**
     * Decrypt prefixed ciphertext back to plaintext.
     *
     * @throws RuntimeException When decryption fails (wrong key, corrupt tag).
     */
    private function decrypt(string $stored, string $dek): string
    {
        $payload = substr($stored, strlen(self::CIPHER_PREFIX));
        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new RuntimeException('Corrupt ciphertext payload.');
        }

        $nonceSize = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $nonce = substr($raw, 0, $nonceSize);
        $cipher = substr($raw, $nonceSize);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $cipher,
            self::CIPHER_PREFIX,
            $nonce,
            $this->deriveKey($dek),
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed.');
        }

        return $plaintext;
    }

    /**
     * Stretch the base64 DEK into a 32-byte sodium key.
     */
    private function deriveKey(string $dek): string
    {
        return hash('sha256', $dek, true);
    }
}
