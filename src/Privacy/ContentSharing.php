<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Privacy;

use HomeSide\AiAgents\Models\AiRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Support-access grants over encrypted run content (variant B2).
 *
 * The ciphertext never leaves the database: a grant only authorises
 * on-the-fly decryption through readGranted(). Grants are owned by the
 * run's user, carry an external reference (support ticket), may expire,
 * and are revoked by clearing the grant columns.
 *
 * Ownership is verified on every operation — a user can never grant or
 * read another user's runs.
 */
class ContentSharing
{
    /**
     * Whether the run currently has an active (non-expired) support grant.
     */
    public function isGranted(AiRun $run): bool
    {
        return $run->hasActiveSupportGrant();
    }

    /**
     * Grant support access to specific runs owned by the user.
     *
     * @param  list<string>  $runIds
     * @param  string  $reference  External ticket reference (e.g. "SUP-1234").
     * @param  int|null  $expiresInHours  Grant lifetime; null = configured default.
     * @param  int|string|null  $grantedBy  The acting user when an admin grants on behalf.
     * @return array{granted: int, denied: list<string>}
     */
    public function grantToSupport(
        int|string $userId,
        array $runIds,
        string $reference,
        ?int $expiresInHours = null,
        int|string|null $grantedBy = null,
    ): array {
        $expiresAt = $this->resolveExpiry($expiresInHours);
        $granted = 0;
        $denied = [];

        foreach ($runIds as $runId) {
            $run = AiRun::query()->find($runId);

            // Ownership check: only the run's own user can grant access.
            if ($run === null || (string) $run->user_id !== (string) $userId) {
                $denied[] = (string) $runId;

                continue;
            }

            $run->forceFill([
                'content_granted_at' => Carbon::now(),
                'content_granted_by' => $grantedBy ?? $userId,
                'content_grant_reference' => $reference,
                'content_grant_expires_at' => $expiresAt,
            ])->save();

            $granted++;
        }

        Log::info('AI content grant created', [
            'user_id' => $userId,
            'reference' => $reference,
            'granted' => $granted,
            'denied' => count($denied),
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return ['granted' => $granted, 'denied' => $denied];
    }

    /**
     * Grant support access to every finished run of one conversation.
     *
     * @return array{granted: int, denied: list<string>}
     */
    public function grantConversationToSupport(
        int|string $userId,
        int|string $conversationId,
        string $reference,
        ?int $expiresInHours = null,
    ): array {
        /** @var list<string> $runIds */
        $runIds = AiRun::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->pluck('id')
            ->all();

        return $this->grantToSupport($userId, $runIds, $reference, $expiresInHours);
    }

    /**
     * Revoke one run's grant (owner or admin action).
     */
    public function revoke(AiRun $run, int|string $userId): bool
    {
        if ((string) $run->user_id !== (string) $userId) {
            return false;
        }

        $run->clearSupportGrant();

        return true;
    }

    /**
     * Revoke every grant carrying a reference; returns the count cleared.
     */
    public function revokeByReference(string $reference): int
    {
        return AiRun::query()
            ->where('content_grant_reference', $reference)
            ->get()
            ->each(fn (AiRun $run) => $run->clearSupportGrant())
            ->count();
    }

    /**
     * Read the decrypted content of a granted run.
     *
     * @param  string  $reference  The ticket reference the reader presents;
     *                             must match the grant on the run.
     */
    public function readGranted(AiRun $run, string $reference): ?GrantedContent
    {
        if (! $run->hasActiveSupportGrant()
            || $run->content_grant_reference !== $reference
        ) {
            return null;
        }

        Log::info('AI granted content read', [
            'run_id' => $run->id,
            'reference' => $reference,
            'read_at' => Carbon::now()->toIso8601String(),
        ]);

        return new GrantedContent(
            runId: $run->id,
            userMessage: $run->user_message,
            reply: $run->reply,
            grantedAt: $run->content_granted_at ?? Carbon::now(),
            expiresAt: $run->content_grant_expires_at,
            reference: $run->content_grant_reference,
        );
    }

    /**
     * All runs with an active grant, optionally filtered by reference.
     *
     * @return Collection<int, AiRun>
     */
    public function grantedRuns(?string $reference = null): Collection
    {
        return AiRun::query()
            ->whereNotNull('content_granted_at')
            ->when($reference !== null, fn ($q) => $q->where('content_grant_reference', $reference))
            ->get()
            ->filter(fn (AiRun $run) => $run->hasActiveSupportGrant())
            ->values();
    }

    /**
     * Expire overdue grants (clears grant columns so crypto-shredding with
     * honour_grants no longer blocks on them). Returns the count cleared.
     */
    public function expireOverdue(): int
    {
        return AiRun::query()
            ->whereNotNull('content_granted_at')
            ->whereNotNull('content_grant_expires_at')
            ->where('content_grant_expires_at', '<', Carbon::now())
            ->get()
            ->each(fn (AiRun $run) => $run->clearSupportGrant())
            ->count();
    }

    /**
     * Crypto-shred a user's content key unless grants are active
     * (shred_policy = honour_grants).
     *
     * @param  bool  $force  Destroy the key even with active grants.
     * @return array{shredded: bool, active_grants: int}
     */
    public function shredUser(int|string $userId, bool $force = false): array
    {
        $activeGrants = AiRun::query()
            ->where('user_id', $userId)
            ->whereNotNull('content_granted_at')
            ->get()
            ->filter(fn (AiRun $run) => $run->hasActiveSupportGrant())
            ->count();

        if ($activeGrants > 0 && ! $force) {
            return ['shredded' => false, 'active_grants' => $activeGrants];
        }

        $shredded = app(UserContentKeyManager::class)->shred($userId);

        return ['shredded' => $shredded, 'active_grants' => $activeGrants];
    }

    /**
     * Resolve the grant expiry from hours, clamped by the configured max.
     * Always in the future (the floor is 1 hour), so the return type is
     * non-nullable — "no expiry" grants are not offered.
     */
    private function resolveExpiry(?int $expiresInHours): Carbon
    {
        $hours = $expiresInHours ?? (int) config('ai-agents.privacy.content.default_grant_hours', 168);
        $max = (int) config('ai-agents.privacy.content.max_grant_hours', 720);

        $hours = min(max($hours, 1), $max);

        return Carbon::now()->addHours($hours);
    }
}
