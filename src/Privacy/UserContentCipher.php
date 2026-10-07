<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Privacy;

use RuntimeException;

/**
 * Reusable envelope-encryption service for per-user content.
 *
 * Wraps the raw cryptographic work shared by every durable, per-user
 * payload in the package (run content and conversation message payloads):
 *
 * - Ciphertext carries a version marker ("enc:v1:") so legacy plaintext or
 *   redacted rows never break reads and the scheme can evolve.
 * - Authenticated encryption with XChaCha20-Poly1305 via libsodium; the
 *   marker itself is bound as additional authenticated data.
 * - The per-user DEK always comes from {@see UserContentKeyManager}, which
 *   remains the single authority for key material and crypto-shredding.
 *
 * Destroying a user's DEK therefore makes every ciphertext produced here
 * (runs and conversations alike) irrecoverable without touching the rows.
 */
final class UserContentCipher
{
    /**
     * Version marker prefixed to every ciphertext this service writes.
     */
    public const PREFIX = 'enc:v1:';

    /**
     * Encrypt plaintext with the given DEK and prefix the version marker.
     *
     * @param  string  $plaintext  The raw value to protect.
     * @param  string  $dek  The user's base64 data-encryption key.
     */
    public function encrypt(string $plaintext, string $dek): string
    {
        $key = $this->deriveKey($dek);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::PREFIX, // additional data binds the marker into the tag
            $nonce,
            $key,
        );

        return self::PREFIX.base64_encode($nonce.$cipher);
    }

    /**
     * Decrypt prefixed ciphertext back to plaintext.
     *
     * @param  string  $stored  The stored ciphertext (including the prefix).
     * @param  string  $dek  The user's base64 data-encryption key.
     *
     * @throws RuntimeException When decryption fails (wrong key, corrupt tag).
     */
    public function decrypt(string $stored, string $dek): string
    {
        $payload = substr($stored, strlen(self::PREFIX));
        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new RuntimeException('Corrupt ciphertext payload.');
        }

        $nonceSize = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $nonce = substr($raw, 0, $nonceSize);
        $cipher = substr($raw, $nonceSize);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $cipher,
            self::PREFIX,
            $nonce,
            $this->deriveKey($dek),
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed.');
        }

        return $plaintext;
    }

    /**
     * Whether a stored value is ciphertext produced by this service.
     */
    public function isCiphertext(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /**
     * Stretch the base64 DEK into a 32-byte sodium key.
     */
    private function deriveKey(string $dek): string
    {
        return hash('sha256', $dek, true);
    }
}
