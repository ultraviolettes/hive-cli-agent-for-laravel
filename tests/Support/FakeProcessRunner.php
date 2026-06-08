<?php

namespace Tests\Support;

use App\Process\ProcessResult;
use App\Process\ProcessRunner;

/**
 * Test double for ProcessRunner: records every call and returns queued
 * results (falling back to a default), so command-running services can be
 * tested without invoking git/gh/claude.
 */
final class FakeProcessRunner implements ProcessRunner
{
    /** @var array<int, array{command: array<int, string>, cwd: ?string, timeout: ?int}> */
    public array $calls = [];

    /** @var array<int, ProcessResult> */
    private array $queue = [];

    public function __construct(
        private readonly ProcessResult $default = new ProcessResult(true, '', '', 0),
    ) {}

    public function queue(ProcessResult $result): self
    {
        $this->queue[] = $result;

        return $this;
    }

    public function run(array $command, ?string $cwd = null, ?int $timeout = null): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'cwd' => $cwd, 'timeout' => $timeout];

        return array_shift($this->queue) ?? $this->default;
    }
}
