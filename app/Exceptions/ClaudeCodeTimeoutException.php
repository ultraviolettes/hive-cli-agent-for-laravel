<?php

namespace App\Exceptions;

/**
 * Raised when a headless `claude -p` call exceeds its timeout, so callers can
 * distinguish "Claude was too slow" (fallback-able) from a hard failure.
 */
final class ClaudeCodeTimeoutException extends \RuntimeException
{
    public function __construct(public readonly int $timeoutSeconds)
    {
        parent::__construct("Claude Code timed out after {$timeoutSeconds}s.");
    }
}
