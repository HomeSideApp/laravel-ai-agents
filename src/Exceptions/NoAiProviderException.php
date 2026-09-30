<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Exceptions;

use RuntimeException;

/**
 * Indicates that no configured AI provider can serve the requested module.
 */
class NoAiProviderException extends RuntimeException {}
