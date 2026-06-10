<?php

namespace App\Process;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $command, ?string $cwd = null, ?int $timeout = null): ProcessResult
    {
        $process = new Process($command, $cwd);

        if ($timeout !== null) {
            $process->setTimeout($timeout);
        }

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // Surface the timeout as data so callers can react (fallback,
            // clear message) instead of catching a Symfony-specific exception.
            return new ProcessResult(
                false,
                $process->getOutput(),
                $process->getErrorOutput(),
                $process->getExitCode() ?? 1,
                timedOut: true,
            );
        }

        return new ProcessResult(
            $process->isSuccessful(),
            $process->getOutput(),
            $process->getErrorOutput(),
            $process->getExitCode() ?? 1,
        );
    }
}
