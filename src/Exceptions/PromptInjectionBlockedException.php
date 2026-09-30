<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Exceptions;

use RuntimeException;

/**
 * Thrown when the prompt firewall blocks content before execution.
 *
 * Only raised when config('ai-agents.firewall.action') is 'block'; the
 * default action is 'flag', which records the finding without aborting.
 */
class PromptInjectionBlockedException extends RuntimeException {}
