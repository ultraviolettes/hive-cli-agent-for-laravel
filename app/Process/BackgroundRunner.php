<?php

namespace App\Process;

/**
 * Starts long-running commands detached from the CLI so several agents can run
 * in parallel and the command returns immediately. Output is redirected to a
 * per-process log file; liveness is checked by PID.
 */
interface BackgroundRunner
{
    /**
     * Start a detached command (survives the CLI exit) writing stdout+stderr to
     * $logFile. Returns the OS process id.
     *
     * @param  array<int, string>  $command
     */
    public function start(array $command, ?string $cwd, string $logFile): int;

    public function isRunning(int $pid): bool;
}
