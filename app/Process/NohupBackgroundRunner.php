<?php

namespace App\Process;

/**
 * Detaches commands with nohup + proc_open (array command, so no shell): the
 * child ignores SIGHUP and keeps running after the CLI exits. PHP does not
 * reap proc_open children on shutdown, so the process is left running on
 * purpose (no proc_close, which would block).
 */
final class NohupBackgroundRunner implements BackgroundRunner
{
    public function start(array $command, ?string $cwd, string $logFile): int
    {
        $dir = dirname($logFile);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $log = fopen($logFile, 'a');

        if ($log === false) {
            throw new \RuntimeException("Cannot open agent log file: {$logFile}");
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => $log,
            2 => $log,
        ];

        $handle = proc_open(['nohup', ...$command], $descriptors, $pipes, $cwd);

        if (! is_resource($handle)) {
            fclose($log);

            throw new \RuntimeException('Failed to start background agent process.');
        }

        $pid = proc_get_status($handle)['pid'];

        // The child holds its own dup'd fd, so the parent handle can close.
        // Deliberately no proc_close(): it would wait for the (long-running)
        // agent. nohup keeps it alive past CLI exit.
        fclose($log);

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
