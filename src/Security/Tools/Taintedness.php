<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security\Tools;

/**
 * Whether the current run carries content the host does not control.
 *
 * Marking and propagating this value is a separate piece of work; until it
 * lands, callers pass Clean and when_tainted rules stay inert.
 */
enum Taintedness: string
{
    case Clean = 'clean';

    case Untrusted = 'untrusted';
}
