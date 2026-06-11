<?php

namespace App\Services;

use App\Process\ProcessRunner;
use App\Process\SymfonyProcessRunner;
use App\Support\BeeStatus;

/**
 * Publishes a bee's work: commits whatever is pending, pushes the branch and
 * opens the pull request via gh. No terminal I/O — results come back as data
 * so the same flow can be driven by `hive pr`, an HTTP controller or the GUI.
 */
final class PrPublisher
{
    public function __construct(
        private readonly ProcessRunner $process = new SymfonyProcessRunner,
    ) {}

    /**
     * Worktrees worth publishing: pending changes, or commits on top of base.
     *
     * @param  array<int, array<string, mixed>>  $inspected
     * @return array<int, array<string, mixed>>
     */
    public function candidates(array $inspected): array
    {
        return array_values(array_filter($inspected, function ($i) {
            if ($i['changes'] !== '—') {
                return true;
            }

            return $i['status'] === BeeStatus::Done || $i['last_commit'] !== '—';
        }));
    }

    /**
     * Commit pending changes, push the branch and open a PR.
     *
     * @param  array<string, mixed>  $target  one entry from WorktreeInspector::inspect()
     * @return array{branch: string, url: ?string, committed: bool, already_exists: bool}
     */
    public function publish(array $target, string $base): array
    {
        $path = $target['path'];
        $branch = $target['branch'];
        $committed = false;

        if ($target['changes'] !== '—') {
            $this->runGit($path, ['git', 'add', '-A']);
            $this->runGit($path, ['git', 'commit', '-m', $this->commitMessage($branch)]);
            $committed = true;
        }

        // '--' ends option parsing so a branch name can never read as a flag.
        $this->runGit($path, ['git', 'push', '-u', 'origin', '--', $branch]);

        $result = $this->process->run([
            'gh', 'pr', 'create',
            '--repo', $this->repoName($path),
            '--head', $branch,
            '--base', $base,
            '--title', $this->prTitle($target),
            '--body', $this->prBody($target, $path),
        ], $path, 30);

        if (! $result->successful) {
            if (str_contains($result->errorOutput, 'already exists')) {
                return ['branch' => $branch, 'url' => null, 'committed' => $committed, 'already_exists' => true];
            }

            throw new \RuntimeException($result->errorOutput);
        }

        return ['branch' => $branch, 'url' => trim($result->output), 'committed' => $committed, 'already_exists' => false];
    }

    private function runGit(string $path, array $command): string
    {
        $result = $this->process->run($command, $path, 60);

        if (! $result->successful) {
            throw new \RuntimeException($result->errorOutput);
        }

        return trim($result->output);
    }

    private function commitMessage(string $branch): string
    {
        $prefix = 'feat';

        if (str_starts_with($branch, 'fix/')) {
            $prefix = 'fix';
        } elseif (str_starts_with($branch, 'chore/')) {
            $prefix = 'chore';
        } elseif (str_starts_with($branch, 'refactor/')) {
            $prefix = 'refactor';
        }

        $slug = preg_replace('/^(fix|feat|chore|refactor)\//', '', $branch);
        $description = str_replace('-', ' ', $slug);

        return "{$prefix}: {$description}";
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function prTitle(array $target): string
    {
        // Use the last commit message as PR title if available
        $lastCommit = $target['last_commit'];
        if ($lastCommit !== '—') {
            // Remove the relative time part "(X days ago)"
            return preg_replace('/\s*\([^)]*ago\)\s*$/', '', $lastCommit);
        }

        return $this->commitMessage($target['branch']);
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function prBody(array $target, string $path): string
    {
        // Get commit log for this branch
        $log = '';

        foreach (['main', 'master', 'develop'] as $base) {
            $result = $this->process->run(['git', 'log', '--oneline', "{$base}..HEAD"], $path);

            if ($result->successful && trim($result->output) !== '') {
                $log = trim($result->output);
                break;
            }
        }

        $commits = $log ? "\n\n## Commits\n\n```\n{$log}\n```" : '';

        $claudeMd = '';
        if (file_exists($path . '/CLAUDE.md')) {
            $context = file_get_contents($path . '/CLAUDE.md');
            // Extract the task description
            if (preg_match('/## Your Task\s*\n\s*(.+?)(?:\n\n|$)/s', $context, $matches)) {
                $claudeMd = "\n\n## Task Context\n\n" . trim($matches[1]);
            }
        }

        return "## Summary\n\nAutomated PR from Hive CLI agent on branch `{$target['branch']}`."
            . $claudeMd
            . $commits
            . "\n\n---\n🐝 Generated by [Hive CLI](https://github.com/ultraviolettes/hive-cli-agent-for-laravel)";
    }

    private function repoName(string $path): string
    {
        $result = $this->process->run(['gh', 'repo', 'view', '--json', 'nameWithOwner', '-q', '.nameWithOwner'], $path);

        if ($result->successful) {
            return trim($result->output);
        }

        // Fallback: parse from git remote
        $url = trim($this->process->run(['git', 'remote', 'get-url', 'origin'], $path)->output);

        // Extract owner/repo from URL
        if (preg_match('#(?:github\.com[:/])(.+?)(?:\.git)?$#', $url, $matches)) {
            return $matches[1];
        }

        throw new \RuntimeException('Could not determine GitHub repository name');
    }
}
