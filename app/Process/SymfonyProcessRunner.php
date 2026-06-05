<?php

namespace App\Process;

use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $command, ?string $cwd = null, ?int $timeout = null): ProcessResult
    {
        $process = new Process($command, $cwd);

        if ($timeout !== null) {
            $process->setTimeout($timeout);
        }

        $process->run();

        return new ProcessResult(
            $process->isSuccessful(),
            $process->getOutput(),
            $process->getErrorOutput(),
            $process->getExitCode() ?? 1,
        );
    }
}
