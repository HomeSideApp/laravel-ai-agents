<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Taint;

/**
 * Where un vetted content came from.
 *
 * The distinction matters for logs and for policy, not for the decision to
 * taint: everything here is content the host did not author.
 */
enum UntrustedSource: string
{
    case Url = 'url';

    case Ocr = 'ocr';

    case Upload = 'upload';

    case Tool = 'tool';

    case External = 'external';
}
