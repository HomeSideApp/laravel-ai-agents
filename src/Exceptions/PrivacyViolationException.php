<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Exceptions;

use RuntimeException;

/**
 * Indicates that the resolved provider does not satisfy the privacy level
 * the agent requires.
 */
class PrivacyViolationException extends RuntimeException {}
