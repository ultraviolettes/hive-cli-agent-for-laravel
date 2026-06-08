<?php

namespace App\Process;

/**
 * Outcome of running an external command, decoupled from Symfony Process so
 * services depend on a small, mockable value object instead of the concrete
 * process implementation.
 */
final class ProcessResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly string $output,
        public readonly string $errorOutput,
        public readonly int $exitCode,
    ) {}
}
