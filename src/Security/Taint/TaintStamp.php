<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Taint;

/**
 * One record of untrusted content entering a run.
 */
final class TaintStamp
{
    public function __construct(
        public readonly UntrustedSource $source,
        public readonly string $label,
    ) {}

    public static function url(string $url): self
    {
        return new self(UntrustedSource::Url, self::clip($url));
    }

    public static function ocr(string $reference): self
    {
        return new self(UntrustedSource::Ocr, self::clip($reference));
    }

    public static function upload(string $filename): self
    {
        return new self(UntrustedSource::Upload, self::clip($filename));
    }

    public static function tool(string $tool): self
    {
        return new self(UntrustedSource::Tool, self::clip($tool));
    }

    /**
     * Labels are logged, so they are clipped: a URL can carry a token in its
     * query string, and the log must not become the leak.
     */
    private static function clip(string $value): string
    {
        return mb_substr($value, 0, 120);
    }
}
