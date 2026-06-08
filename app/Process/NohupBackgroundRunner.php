<?php

namespace App\Process;

/**
 * Detaches commands so they outlive the CLI and run in parallel.
 *
 * A short-lived `/bin/sh` backgrounds the command with nohup and prints the
 * agent's PID. The shell returns in milliseconds (so proc_close never blocks,
 * regardless of how long the agent runs); the agent is orphaned and, because
 * nohup ignores SIGHUP, keeps running after the CLI exits.
 *
 * Untrusted input is passed as positional arguments ($0 = log file, $@ = the
 * command) and only referenced through quoted expansions — the script string
 * itself is a fixed literal, so there is no shell injection.
 */
final class NohupBackgroundRunner implements BackgroundRunner
{
    public function start(array $command, ?string $cwd, string $logFile): int
    {
        $dir = dirname($logFile);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = proc_open(
            ['/bin/sh', '-c', 'nohup "$@" >> "$0" 2>&1 & echo $!', $logFile, ...$command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
        );

        if (! is_resource($handle)) {
            throw new \RuntimeException('Failed to start background agent process.');
        }

        $pid = (int) trim(stream_get_contents($pipes[1]));

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($handle); // the shell has already returned — this does not block

        if ($pid <= 0) {
            throw new \RuntimeException('Could not determine background agent PID.');
        }

        return $pid;
    }

    public function isRunning(int $pid): bool
    {
        if ($pid <= 0 || ! function_exists('posix_kill')) {
            return false;
        }

        // Signal 0 probes existence: true if alive, EPERM (1) if it exists
        // but is owned by another user.
        return posix_kill($pid, 0) || posix_get_last_error() === 1;
    }
}
