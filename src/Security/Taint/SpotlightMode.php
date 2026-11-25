<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Taint;

/**
 * How untrusted content is presented to the model.
 */
enum SpotlightMode: string
{
    /** Wrap the content in random delimiters with an explicit data preamble. */
    case Delimit = 'delimit';

    /** Delimit and prefix every line with a marker rare in ordinary prose. */
    case Datamark = 'datamark';

    public static function fromString(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Delimit;
    }
}
