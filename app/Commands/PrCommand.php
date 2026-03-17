<?php

namespace App\Commands;

use App\Services\WorktreeInspector;
use App\Services\WorktreeManager;
use App\Support\HiveConfig;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class PrCommand extends Command
{
    protected $signature = 'pr
                            {branch? : Branch to create PR for}
                            {--all : Create PRs for all branches with changes or commits}
                            {--base=main : Base branch for the PR}';

    protected $description = 'Commit, push and create pull requests for worktree branches';

    public function handle(): int
    {
        $config = new HiveConfig(getcwd());
        if (! $config->exists()) {
            $this->error('No .hive.json found. Run hive init first.');

            return self::FAILURE;
        }

        $cwd = getcwd();
        $manager = new WorktreeManager($cwd);
        $inspector = new WorktreeInspector;
        $worktrees = $manager->list();

        if (empty($worktrees)) {
            $this->line('No active worktrees.');

            return self::SUCCESS;
        }

        // Filter worktrees based on arguments
        $targets = $this->resolveTargets($worktrees, $inspector);

        if (empty($targets)) {
            $this->line('No branches ready for PR. All worktrees are idle with no new commits.');

            return self::SUCCESS;
        }

        // Show what will be done
        $this->line('');
        $this->line('📋 Branches to PR:');
        $this->line('');

        table(
            ['Branch', 'Status', 'Changes'],
            array_map(fn ($t) => [
                $t['branch'],
                $t['agent'],
                $t['changes'],
            ], $targets)
        );

        if (! confirm('Create PRs for these branches?')) {
            return self::SUCCESS;
        }

        $base = $this->option('base') ?? $config->get('main_branch', 'main');
        $errors = [];

        foreach ($targets as $target) {
            $this->line('');
            $this->line("🐝 Processing <comment>{$target['branch']}</comment>...");

            try {
                $this->processBranch($target, $base);
                $this->line("  ✅ PR created for <comment>{$target['branch']}</comment>");
            } catch (\RuntimeException $e) {
                $this->error("  ❌ Failed: {$e->getMessage()}");
                $errors[] = $target['branch'];
            }
        }

        $this->line('');
        if (empty($errors)) {
            $this->info('All PRs created successfully.');
        } else {
            $this->warn(count($errors) . ' PR(s) failed: ' . implode(', ', $errors));
        }

        return empty($errors) ? self::SUCCESS : self::FAILURE;
    }

    private function resolveTargets(array $worktrees, WorktreeInspector $inspector): array
    {
        $branch = $this->argument('branch');
        $all = $this->option('all');

        $inspected = array_map(fn ($w) => $inspector->inspect($w), $worktrees);

        if ($branch) {
            // Find specific branch
            $match = array_filter($inspected, fn ($i) => $i['branch'] === $branch || str_ends_with($i['branch'], '/' . $branch));

            if (empty($match)) {
                $this->error("Branch '{$branch}' not found in active worktrees.");

                return [];
            }

            return array_values($match);
        }

        if ($all) {
            // All branches that have changes, pending work, or commits (done/idle with commits)
            return array_values(array_filter($inspected, function ($i) {
                // Include if has pending changes
                if ($i['changes'] !== '—') {
                    return true;
                }
                // Include if has commits (done or idle with work)
                if (str_contains($i['agent'], 'done') || $i['last_commit'] !== '—') {
                    return true;
                }

                return false;
            }));
        }

        $this->error('Specify a branch or use --all');

        return [];
    }

    private function processBranch(array $target, string $base): void
    {
        $path = $target['path'];

        // 1. Stage and commit any uncommitted changes
        if ($target['changes'] !== '—') {
            $this->runGit($path, ['git', 'add', '-A']);

            $commitMessage = $this->generateCommitMessage($target);
            $this->runGit($path, ['git', 'commit', '-m', $commitMessage]);
            $this->line('  📝 Changes committed');
        }

        // 2. Push the branch
        $branchName = $target['branch'];
        spin(
            fn () => $this->runGit($path, ['git', 'push', '-u', 'origin', $branchName]),
            "  Pushing {$branchName}..."
        );
        $this->line('  🚀 Pushed to remote');

        // 3. Create PR via gh CLI
        $title = $this->generatePrTitle($target);
        $body = $this->generatePrBody($target, $path);

        $process = new Process([
            'gh', 'pr', 'create',
            '--repo', $this->getRepoName($path),
            '--head', $branchName,
            '--base', $base,
            '--title', $title,
            '--body', $body,
        ], $path);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = $process->getErrorOutput();
            // If PR already exists, that's OK
            if (str_contains($error, 'already exists')) {
                $this->line('  ℹ️  PR already exists');

                return;
            }
            throw new \RuntimeException($error);
        }

        $prUrl = trim($process->getOutput());
        $this->line("  🔗 {$prUrl}");
    }

    private function runGit(string $path, array $command): string
    {
        $process = new Process($command, $path);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    private function generateCommitMessage(array $target): string
    {
        $branch = $target['branch'];
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

    private function generatePrTitle(array $target): string
    {
        // Use the last commit message as PR title if available
        $lastCommit = $target['last_commit'];
        if ($lastCommit !== '—') {
            // Remove the relative time part "(X days ago)"
            return preg_replace('/\s*\([^)]*ago\)\s*$/', '', $lastCommit);
        }

        return $this->generateCommitMessage($target);
    }

    private function generatePrBody(array $target, string $path): string
    {
        // Get commit log for this branch
        $log = '';

        foreach (['main', 'master', 'develop'] as $base) {
            $process = new Process(['git', 'log', '--oneline', "{$base}..HEAD"], $path);
            $process->run();

            if ($process->isSuccessful() && trim($process->getOutput()) !== '') {
                $log = trim($process->getOutput());
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

    private function getRepoName(string $path): string
    {
        $process = new Process(['gh', 'repo', 'view', '--json', 'nameWithOwner', '-q', '.nameWithOwner'], $path);
        $process->run();

        if ($process->isSuccessful()) {
            return trim($process->getOutput());
        }

        // Fallback: parse from git remote
        $process = new Process(['git', 'remote', 'get-url', 'origin'], $path);
        $process->run();
        $url = trim($process->getOutput());

        // Extract owner/repo from URL
        if (preg_match('#(?:github\.com[:/])(.+?)(?:\.git)?$#', $url, $matches)) {
            return $matches[1];
        }

        throw new \RuntimeException('Could not determine GitHub repository name');
    }
}
