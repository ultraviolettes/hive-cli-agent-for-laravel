<?php

namespace App\Services;

use App\Process\BackgroundRunner;
use App\Process\NohupBackgroundRunner;
use App\Process\ProcessRunner;
use App\Process\SymfonyProcessRunner;

/**
 * Launches an autonomous Claude Code agent headlessly inside a worktree.
 *
 * Runs `claude -p <prompt> --output-format json --permission-mode <mode>` in
 * the worktree directory and parses the session id / result. Goes through the
 * ProcessRunner seam so it is fully testable without invoking the real binary.
 */
final class AgentLauncher
{
    public function __construct(
        private readonly string $binary = 'claude',
        private readonly ProcessRunner $process = new SymfonyProcessRunner,
        private readonly BackgroundRunner $background = new NohupBackgroundRunner,
    ) {}

    /**
     * Start an agent detached in the worktree, streaming output to $logFile.
     * Returns the OS process id; nothing is waited on, so several agents can
     * run in parallel.
     */
    public function launchBackground(
        string $worktreePath,
        string $prompt,
        string $permissionMode,
        string $logFile,
        ?string $sessionId = null,
    ): int {
        $command = [$this->binary, '-p', $prompt, '--permission-mode', $permissionMode];

        // Pin the session id so it can be captured up front (background output
        // isn't parsed) and the bee resumed later via `claude --resume`.
        if ($sessionId !== null) {
            $command[] = '--session-id';
            $command[] = $sessionId;
        }

        return $this->background->start($command, $worktreePath, $logFile);
    }

    /**
     * @return array{successful: bool, session_id: ?string, result: string, cost_usd: ?float, error: ?string}
     */
    public function run(
        string $worktreePath,
        string $prompt,
        string $permissionMode = 'default',
        int $timeout = 1800,
    ): array {
        $command = [
            $this->binary,
            '-p', $prompt,
            '--output-format', 'json',
            '--permission-mode', $permissionMode,
        ];

        $result = $this->process->run($command, $worktreePath, $timeout);

        if (! $result->successful) {
            return [
                'successful' => false,
                'session_id' => null,
                'result' => '',
                'cost_usd' => null,
                'error' => $result->errorOutput !== '' ? $result->errorOutput : 'Claude Code agent failed',
            ];
        }

        $json = json_decode($result->output, true);
        $json = is_array($json) ? $json : [];

        return [
            'successful' => true,
            'session_id' => $json['session_id'] ?? null,
            'result' => (string) ($json['result'] ?? $result->output),
            'cost_usd' => isset($json['total_cost_usd']) ? (float) $json['total_cost_usd'] : null,
            'error' => null,
        ];
    }
}
