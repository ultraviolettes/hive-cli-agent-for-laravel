<?php

namespace App\Services;

use App\Process\ProcessRunner;
use App\Process\SymfonyProcessRunner;
use App\Support\BeeStatus;

final class WorktreeInspector
{
    public function __construct(
        private readonly ProcessRunner $process = new SymfonyProcessRunner,
    ) {}

    /**
     * Get detailed status for a worktree.
     */
    public function inspect(array $worktree): array
    {
        $path = $worktree['path'];
        $branch = $worktree['branch'] ?? '?';

        $status = $this->detectAgent($path);
        $commits = $status === BeeStatus::Done ? $this->getCommitCount($path) : 0;

        return [
            'branch' => $this->shortBranch($branch),
            'path' => $path,
            'status' => $status,
            'commits' => $commits,
            'agent' => $this->agentLabel($status, $commits),
            'changes' => $this->getChangeSummary($path),
            'last_commit' => $this->getLastCommit($path),
            'has_claude_md' => file_exists($path . '/CLAUDE.md'),
        ];
    }

    /**
     * Shorten branch ref (refs/heads/fix/cve -> fix/cve).
     */
    private function shortBranch(string $branch): string
    {
        return str_replace('refs/heads/', '', $branch);
    }

    /**
     * Detect if a Claude Code process is running in this worktree.
     */
    private function detectAgent(string $path): BeeStatus
    {
        $result = $this->process->run(['pgrep', '-f', "claude.*{$path}"]);

        if ($result->successful && trim($result->output) !== '') {
            return BeeStatus::Running;
        }

        // Check if there are uncommitted changes (agent might have worked and finished)
        $status = $this->getGitStatus($path);

        if ($status === null) {
            return BeeStatus::Unknown;
        }

        if (str_contains($status, 'nothing to commit')) {
            return $this->getCommitCount($path) > 0 ? BeeStatus::Done : BeeStatus::Idle;
        }

        return BeeStatus::ChangesPending;
    }

    /**
     * Terminal display label for a status (commit count folded in for "done").
     */
    private function agentLabel(BeeStatus $status, int $commits): string
    {
        if ($status === BeeStatus::Done) {
            return '✅ done (' . $commits . ' commit' . ($commits > 1 ? 's' : '') . ')';
        }

        return $status->label();
    }

    /**
     * Get a summary of changes in the worktree.
     */
    private function getChangeSummary(string $path): string
    {
        $staged = $this->countLines(
            $this->process->run(['git', 'diff', '--name-only', '--cached'], $path)->output
        );

        $unstaged = $this->countLines(
            $this->process->run(['git', 'diff', '--name-only'], $path)->output
        );

        $untracked = $this->countLines(
            $this->process->run(['git', 'ls-files', '--others', '--exclude-standard'], $path)->output
        );

        $parts = [];
        if ($staged > 0) {
            $parts[] = "{$staged} staged";
        }
        if ($unstaged > 0) {
            $parts[] = "{$unstaged} modified";
        }
        if ($untracked > 0) {
            $parts[] = "{$untracked} new";
        }

        return empty($parts) ? '—' : implode(', ', $parts);
    }

    /**
     * Count the number of non-empty lines in command output (one file per line).
     */
    private function countLines(string $output): int
    {
        $output = trim($output);

        return $output === '' ? 0 : substr_count($output, "\n") + 1;
    }

    /**
     * Get the last commit message and relative time.
     */
    private function getLastCommit(string $path): string
    {
        $output = trim($this->process->run(['git', 'log', '-1', '--format=%s (%cr)', '--no-merges'], $path)->output);

        if (empty($output)) {
            return '—';
        }

        // Truncate long commit messages
        if (strlen($output) > 60) {
            $output = substr($output, 0, 57) . '...';
        }

        return $output;
    }

    /**
     * Count commits ahead of main branch.
     */
    private function getCommitCount(string $path): int
    {
        // Try to count commits ahead of main
        foreach (['main', 'master', 'develop'] as $base) {
            $result = $this->process->run(['git', 'rev-list', '--count', "{$base}..HEAD"], $path);

            if ($result->successful) {
                return (int) trim($result->output);
            }
        }

        return 0;
    }

    /**
     * Get raw git status output.
     */
    private function getGitStatus(string $path): ?string
    {
        $result = $this->process->run(['git', 'status'], $path);

        return $result->successful ? $result->output : null;
    }
}
