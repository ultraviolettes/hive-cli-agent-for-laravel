<?php

namespace App\Services;

use Symfony\Component\Process\Process;

final class WorktreeInspector
{
    /**
     * Get detailed status for a worktree.
     */
    public function inspect(array $worktree): array
    {
        $path = $worktree['path'];
        $branch = $worktree['branch'] ?? '?';

        return [
            'branch' => $this->shortBranch($branch),
            'path' => $path,
            'agent' => $this->detectAgent($path),
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
    private function detectAgent(string $path): string
    {
        $process = new Process(['pgrep', '-f', "claude.*{$path}"]);
        $process->run();

        if ($process->isSuccessful() && trim($process->getOutput()) !== '') {
            return '🐝 agent running';
        }

        // Check if there are uncommitted changes (agent might have worked and finished)
        $status = $this->getGitStatus($path);

        if ($status === null) {
            return '❓ unknown';
        }

        if (str_contains($status, 'nothing to commit')) {
            $commits = $this->getCommitCount($path);
            if ($commits > 0) {
                return '✅ done (' . $commits . ' commit' . ($commits > 1 ? 's' : '') . ')';
            }

            return '💤 idle';
        }

        return '🔧 changes pending';
    }

    /**
     * Get a summary of changes in the worktree.
     */
    private function getChangeSummary(string $path): string
    {
        $process = new Process(['git', 'diff', '--name-only', '--cached'], $path);
        $process->run();
        $staged = $this->countLines($process->getOutput());

        $process = new Process(['git', 'diff', '--name-only'], $path);
        $process->run();
        $unstaged = $this->countLines($process->getOutput());

        $process = new Process(['git', 'ls-files', '--others', '--exclude-standard'], $path);
        $process->run();
        $untracked = $this->countLines($process->getOutput());

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
        $process = new Process(['git', 'log', '-1', '--format=%s (%cr)', '--no-merges'], $path);
        $process->run();

        $output = trim($process->getOutput());

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
            $process = new Process(['git', 'rev-list', '--count', "{$base}..HEAD"], $path);
            $process->run();

            if ($process->isSuccessful()) {
                return (int) trim($process->getOutput());
            }
        }

        return 0;
    }

    /**
     * Get raw git status output.
     */
    private function getGitStatus(string $path): ?string
    {
        $process = new Process(['git', 'status'], $path);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }
}
