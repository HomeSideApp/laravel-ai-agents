<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Privacy;

use HomeSide\AiAgents\Models\AiUserContentKey;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Envelope key management for per-user run content encryption.
 *
 * Each user gets a random 256-bit data-encryption key (DEK). The DEK
 * encrypts the run content; the DEK itself is stored wrapped with the
 * application key. Consequences:
 *
 * - Decryption needs the app (jobs and schedulers keep working).
 * - Crypto-shredding (destroying the wrapped DEK) irreversibly invalidates
 *   every ciphertext of that user without touching the run rows.
 */
class UserContentKeyManager
{
    /**
     * Return the user's raw DEK, creating and wrapping one on first use.
     *
     * @throws RuntimeException When the key exists but has been shredded.
     */
    public function keyFor(int|string $userId): string
    {
        /** @var AiUserContentKey|null $record */
        $record = AiUserContentKey::query()->where('user_id', $userId)->first();

        if ($record === null) {
            $dek = $this->generateDek();

            AiUserContentKey::create([
                'user_id' => $userId,
                'wrapped_dek' => Crypt::encryptString($dek),
                'key_version' => 1,
            ]);

            return $dek;
        }

        if ($record->shredded_at !== null) {
            throw new RuntimeException("Content key for user [{$userId}] has been shredded.");
        }

        return Crypt::decryptString($record->wrapped_dek);
    }

    /**
     * Crypto-shred a user's content key: the wrapped DEK value is destroyed
     * so every ciphertext of that user becomes unrecoverable.
     *
     * Idempotent: shredding an already-shredded key is a no-op.
     */
    public function shred(int|string $userId): bool
    {
        /** @var AiUserContentKey|null $record */
        $record = AiUserContentKey::query()->where('user_id', $userId)->first();

        if ($record === null || $record->shredded_at !== null) {
            return false;
        }

        // Overwrite with random bytes before marking: the wrapped value is
        // gone even from row-level backups of the table itself.
        $record->forceFill([
            'wrapped_dek' => bin2hex(random_bytes(32)),
            'shredded_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Whether the user's key has been shredded (content unrecoverable).
     */
    public function isShredded(int|string $userId): bool
    {
        /** @var AiUserContentKey|null $record */
        $record = AiUserContentKey::query()->where('user_id', $userId)->first();

        return $record?->shredded_at !== null;
    }

    /**
     * Generate a base64-encoded 256-bit key.
     */
    private function generateDek(): string
    {
        return base64_encode(random_bytes(32));
    }
}
