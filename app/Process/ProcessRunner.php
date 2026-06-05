<?php

namespace App\Process;

/**
 * Seam over external command execution. Production uses SymfonyProcessRunner;
 * tests can inject a fake so the services that shell out to git/gh/claude are
 * exercised without those binaries (and without a real repository on disk).
 */
interface ProcessRunner
{
    /**
     * @param  array<int, string>  $command
     */
    public function run(array $command, ?string $cwd = null, ?int $timeout = null): ProcessResult;
}
