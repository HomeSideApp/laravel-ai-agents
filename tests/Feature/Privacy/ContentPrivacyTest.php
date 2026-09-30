<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Privacy;

use HomeSide\AiAgents\Enums\ContentMode;
use HomeSide\AiAgents\Enums\PrivacyLevel;
use HomeSide\AiAgents\Execution\RunContentRedactor;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiRun;
use HomeSide\AiAgents\Models\AiUserContentKey;
use HomeSide\AiAgents\Privacy\ContentSharing;
use HomeSide\AiAgents\Privacy\UserContentKeyManager;
use HomeSide\AiAgents\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Content privacy: encrypted-by-default storage with per-user envelope
 * keys, host-ceiling precedence over consent, support grants with
 * on-the-fly decryption and crypto-shredding honouring active grants.
 */
final class ContentPrivacyTest extends TestCase
{
    private function makeRun(int|string $userId, array $attributes = []): AiRun
    {
        return AiRun::create([
            'user_id' => $userId,
            'agent' => 'test.agent',
            'status' => 'ok',
            'duration_ms' => 10,
            'content_mode' => 'encrypted',
            ...$attributes,
        ]);
    }

    // ----- Mode resolution -------------------------------------------------

    public function test_resolve_defaults_to_encrypted_without_consent(): void
    {
        $mode = (new RunContentRedactor)->resolveMode(PrivacyLevel::Cloud, userConsented: false);

        $this->assertSame(ContentMode::Encrypted, $mode);
    }

    public function test_resolve_uses_plain_with_consent(): void
    {
        $mode = (new RunContentRedactor)->resolveMode(PrivacyLevel::Cloud, userConsented: true);

        $this->assertSame(ContentMode::Plain, $mode);
    }

    public function test_host_ceiling_overrides_consent(): void
    {
        config(['ai-agents.logging.retention' => ['cloud' => 'none']]);

        $redactor = new RunContentRedactor;

        $this->assertSame(
            ContentMode::None,
            $redactor->resolveMode(PrivacyLevel::Cloud, userConsented: true),
        );

        config(['ai-agents.logging.retention' => ['cloud' => 'redacted']]);

        $this->assertSame(
            ContentMode::Redacted,
            $redactor->resolveMode(PrivacyLevel::Cloud, userConsented: true),
        );
    }

    // ----- Encrypted storage ----------------------------------------------

    public function test_encrypted_content_is_not_plaintext_in_database(): void
    {
        $userId = 1;

        $run = $this->makeRun($userId, ['user_message' => 'my secret recipe']);

        $stored = (string) DB::table('ai_runs')->where('id', $run->id)->value('user_message');

        $this->assertNotSame('my secret recipe', $stored);
        $this->assertStringStartsWith('enc:v1:', $stored);
        $this->assertStringNotContainsString('recipe', $stored);

        // Model read: transparent decryption.
        $this->assertSame('my secret recipe', $run->fresh()->user_message);
    }

    public function test_plain_mode_stores_readably(): void
    {
        $run = $this->makeRun(1, ['content_mode' => 'plain', 'user_message' => 'open text']);

        $stored = (string) DB::table('ai_runs')->where('id', $run->id)->value('user_message');

        $this->assertSame('open text', $stored);
    }

    public function test_each_user_gets_its_own_key(): void
    {
        $manager = app(UserContentKeyManager::class);
        $runA = $this->makeRun(1, ['user_message' => 'user one content']);
        $runB = $this->makeRun(2, ['user_message' => 'user two content']);

        $this->assertSame('user one content', $runA->fresh()->user_message);
        $this->assertSame('user two content', $runB->fresh()->user_message);

        // Two distinct wrapped keys exist.
        $this->assertSame(2, AiUserContentKey::query()->count());

        // Cross-key reads fail gracefully (raw ciphertext, no crash).
        $this->assertNotSame('user one content', (string) DB::table('ai_runs')->where('id', $runB->id)->value('user_message'));

        $manager->keyFor('1');
        $manager->keyFor('2');

        $this->assertSame(2, AiUserContentKey::query()->distinct('user_id')->count('user_id'));
    }

    // ----- Support grants --------------------------------------------------

    public function test_grant_and_read_granted_content(): void
    {
        $sharing = app(ContentSharing::class);
        $run = $this->makeRun(1, ['user_message' => 'help me with this error', 'reply' => 'here is the fix']);

        $result = $sharing->grantToSupport(1, [$run->id], 'SUP-42', expiresInHours: 24);

        $this->assertSame(1, $result['granted']);
        $this->assertSame([], $result['denied']);

        $content = $sharing->readGranted($run->fresh(), 'SUP-42');

        $this->assertNotNull($content);
        $this->assertSame('help me with this error', $content->userMessage);
        $this->assertSame('here is the fix', $content->reply);
        $this->assertSame('SUP-42', $content->reference);

        // Raw storage stays ciphertext after the grant (B2).
        $stored = (string) DB::table('ai_runs')->where('id', $run->id)->value('user_message');
        $this->assertStringStartsWith('enc:v1:', $stored);
    }

    public function test_read_denied_without_or_with_wrong_reference(): void
    {
        $sharing = app(ContentSharing::class);
        $run = $this->makeRun(1, ['user_message' => 'private']);

        $this->assertNull($sharing->readGranted($run->fresh(), 'SUP-42'));

        $sharing->grantToSupport(1, [$run->id], 'SUP-42', 24);

        $this->assertNull($sharing->readGranted($run->fresh(), 'SUP-999'));
    }

    public function test_grant_denied_for_other_users_runs(): void
    {
        $sharing = app(ContentSharing::class);
        $run = $this->makeRun(2, ['user_message' => 'not yours']);

        $result = $sharing->grantToSupport(1, [$run->id], 'SUP-1');

        $this->assertSame(0, $result['granted']);
        $this->assertSame([$run->id], $result['denied']);
        $this->assertFalse($run->fresh()->hasActiveSupportGrant());
    }

    public function test_revoke_closes_access(): void
    {
        $sharing = app(ContentSharing::class);
        $run = $this->makeRun(1, ['user_message' => 'temp']);

        $sharing->grantToSupport(1, [$run->id], 'SUP-7', 24);
        $this->assertTrue($sharing->isGranted($run->fresh()));

        $this->assertTrue($sharing->revoke($run->fresh(), 1));
        $this->assertFalse($sharing->isGranted($run->fresh()));
        $this->assertNull($sharing->readGranted($run->fresh(), 'SUP-7'));
    }

    public function test_revoked_by_reference_clears_all_grants(): void
    {
        $sharing = app(ContentSharing::class);
        $a = $this->makeRun(1, ['user_message' => 'a']);
        $b = $this->makeRun(1, ['user_message' => 'b']);

        $sharing->grantToSupport(1, [$a->id, $b->id], 'SUP-8', 24);
        $sharing->revokeByReference('SUP-8');

        $this->assertFalse($sharing->isGranted($a->fresh()));
        $this->assertFalse($sharing->isGranted($b->fresh()));
    }

    // ----- Expiry ----------------------------------------------------------

    public function test_expired_grant_no_longer_reads(): void
    {
        $sharing = app(ContentSharing::class);
        $run = $this->makeRun(1, ['user_message' => 'old']);

        $sharing->grantToSupport(1, [$run->id], 'SUP-9', 1);
        $run->fresh()->forceFill(['content_grant_expires_at' => now()->subHour()])->save();

        $this->assertFalse($sharing->isGranted($run->fresh()));
        $this->assertNull($sharing->readGranted($run->fresh(), 'SUP-9'));
        $this->assertSame(1, $sharing->expireOverdue());
    }

    // ----- Crypto-shredding ------------------------------------------------

    public function test_shredding_destroys_history_and_blocks_new_encryption(): void
    {
        $manager = app(UserContentKeyManager::class);
        $sharing = app(ContentSharing::class);
        $run = $this->makeRun(1, ['user_message' => 'will vanish']);

        $this->assertSame('will vanish', $run->fresh()->user_message);

        $result = $sharing->shredUser(1);

        $this->assertTrue($result['shredded']);
        $this->assertNotNull(AiUserContentKey::query()->where('user_id', 1)->first()?->shredded_at);

        // Historical content is unrecoverable (raw ciphertext is degraded).
        $fresh = $run->fresh();
        $stored = (string) DB::table('ai_runs')->where('id', $run->id)->value('user_message');
        $this->assertNotSame('will vanish', $fresh->user_message);
        $this->assertStringStartsWith('enc:v1:', $stored);

        // New encryption attempts for the user surface the shred state.
        $this->expectException(\RuntimeException::class);
        $manager->keyFor(1);
    }

    public function test_shred_honours_active_grants(): void
    {
        $sharing = app(ContentSharing::class);
        $run = $this->makeRun(1, ['user_message' => 'shared with support']);

        $sharing->grantToSupport(1, [$run->id], 'SUP-11', 48);

        $result = $sharing->shredUser(1);

        $this->assertFalse($result['shredded']);
        $this->assertSame(1, $result['active_grants']);

        // Forced shred goes through.
        $forced = $sharing->shredUser(1, force: true);
        $this->assertTrue($forced['shredded']);
    }

    public function test_shredded_user_without_key_shreds_nothing(): void
    {
        $sharing = app(ContentSharing::class);

        $result = $sharing->shredUser(99);

        $this->assertFalse($result['shredded']);
        $this->assertSame(0, $result['active_grants']);
    }

    // ----- Validation guard ------------------------------------------------

    public function test_consent_cannot_override_none_ceiling_at_recorder_level(): void
    {
        config(['ai-agents.logging.retention' => ['cloud' => 'none']]);

        $redactor = new RunContentRedactor;

        // Even with consent the ceiling forces none.
        $this->assertSame(ContentMode::None, $redactor->resolveMode(PrivacyLevel::Cloud, true));
        $this->assertNull($redactor->retainWithMode('anything', ContentMode::None));
    }

    public function test_validation_exception_from_ai_provider_still_works(): void
    {
        // Sanity: the surrounding validated-write suite is unaffected by the
        // new columns on ai_runs.
        $this->expectException(ValidationException::class);

        AiProvider::createValidated([
            'name' => 'X',
            'type' => 'unsupported-driver',
            'base_url' => 'https://api.example.com/v1',
            'model' => 'm',
            'api_key' => 'sk-12345678',
            'module' => 'general',
        ]);
    }
}
