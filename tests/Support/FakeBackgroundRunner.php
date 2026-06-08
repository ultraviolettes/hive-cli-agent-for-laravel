<?php

namespace Tests\Support;

use App\Process\BackgroundRunner;

/**
 * Test double for BackgroundRunner: records launches and hands out fake PIDs,
 * so background launching can be tested without detaching real processes.
 */
final class FakeBackgroundRunner implements BackgroundRunner
{
    /** @var array<int, array{command: array<int, string>, cwd: ?string, logFile: string, pid: int}> */
    public array $started = [];

    /** @var array<int, bool> pid => running override */
    public array $running = [];

    private int $nextPid = 4242;

    public function start(array $command, ?string $cwd, string $logFile): int
    {
        $pid = $this->nextPid++;
        $this->started[] = ['command' => $command, 'cwd' => $cwd, 'logFile' => $logFile, 'pid' => $pid];

        return $pid;
    }

    public function isRunning(int $pid): bool
    {
        return $this->running[$pid] ?? true;
    }
}
