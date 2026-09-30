<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Execution;

use HomeSide\AiAgents\Enums\PrivacyLevel;
use HomeSide\AiAgents\Execution\RunContentRedactor;
use HomeSide\AiAgents\Tests\TestCase;

/**
 * Retention policy mapping in RunContentRedactor: full keeps text as-is,
 * redacted stores a deterministic digest, none stores null. Invalid
 * configured modes degrade to full instead of crashing the recorder.
 */
final class RunContentRedactorTest extends TestCase
{
    /**
     * The default configuration keeps everything: no host setup needed.
     */
    public function test_defaults_keep_full_content_on_every_level(): void
    {
        $redactor = new RunContentRedactor;

        foreach (PrivacyLevel::cases() as $level) {
            $this->assertSame('secret recipe text', $redactor->retain('secret recipe text', $level));
        }

        $this->assertNull($redactor->retain(null, PrivacyLevel::Cloud));
    }

    /**
     * The none mode discards the content entirely.
     */
    public function test_none_mode_stores_nothing(): void
    {
        config(['ai-agents.logging.retention' => [
            'cloud' => 'none',
        ]]);

        $redactor = new RunContentRedactor;

        $this->assertNull($redactor->retain('secret recipe text', PrivacyLevel::Cloud));
        $this->assertSame('kept', $redactor->retain('kept', PrivacyLevel::Local));
    }

    /**
     * The redacted mode stores a deterministic digest carrying the length,
     * never a fragment of the original text.
     */
    public function test_redacted_mode_stores_deterministic_digest(): void
    {
        config(['ai-agents.logging.retention' => [
            'cloud' => 'redacted',
        ]]);

        $redactor = new RunContentRedactor;
        $first = $redactor->retain('secret recipe text', PrivacyLevel::Cloud);
        $second = $redactor->retain('secret recipe text', PrivacyLevel::Cloud);

        $this->assertIsString($first);
        $this->assertSame($first, $second);
        $this->assertStringStartsWith('[redacted sha256:', $first);
        $this->assertStringContainsString('len=18', $first);
        $this->assertStringNotContainsString('secret', $first);
        $this->assertStringNotContainsString('recipe', $first);
    }

    /**
     * Digest length is configurable and clamped to the sha256 hex range.
     */
    public function test_digest_length_is_configurable(): void
    {
        config([
            'ai-agents.logging.retention' => ['unknown' => 'redacted'],
            'ai-agents.logging.redacted_digest_length' => 4,
        ]);

        $redactor = new RunContentRedactor;
        $digest = (string) $redactor->retain('some content', PrivacyLevel::Unknown);

        $expected = '[redacted sha256:'.substr(hash('sha256', 'some content'), 0, 4).' len=12]';

        $this->assertSame($expected, $digest);
    }

    /**
     * An invalid configured mode falls back to full instead of crashing.
     */
    public function test_invalid_mode_degrades_to_full(): void
    {
        config(['ai-agents.logging.retention' => [
            'local' => 'shred-it-please',
        ]]);

        $redactor = new RunContentRedactor;

        $this->assertSame('still here', $redactor->retain('still here', PrivacyLevel::Local));
    }
}
