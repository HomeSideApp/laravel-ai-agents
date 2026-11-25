<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Taint;

/**
 * Content the host did not author, carried together with its provenance.
 *
 * Wrapping is what makes taint usable: the caller marks the content at the
 * moment it arrives, and the run scope learns about it without every call site
 * having to remember to flag itself.
 */
final class UntrustedContent
{
    public function __construct(
        public readonly string $text,
        public readonly TaintStamp $stamp,
    ) {}

    public static function fromUrl(string $url, string $text): self
    {
        return new self($text, TaintStamp::url($url));
    }

    public static function fromOcr(string $reference, string $text): self
    {
        return new self($text, TaintStamp::ocr($reference));
    }

    public static function fromUpload(string $filename, string $text): self
    {
        return new self($text, TaintStamp::upload($filename));
    }

    public static function fromTool(string $tool, string $text): self
    {
        return new self($text, TaintStamp::tool($tool));
    }

    /**
     * Marks the scope and returns the text, so a call site reads as one line:
     * `$prompt .= $content->into($scope);`
     */
    public function into(TaintScope $scope): string
    {
        $scope->mark($this->stamp);

        return $this->text;
    }

    /**
     * Marks the run and returns the text wrapped for a prompt, so the model sees
     * it as data instead of as an instruction.
     */
    public function forPrompt(TaintScope $scope, ?Spotlight $spotlight = null, string $source = 'an untrusted source'): string
    {
        $scope->mark($this->stamp);

        return ($spotlight ?? new Spotlight)->apply($this->text, $source);
    }
}
