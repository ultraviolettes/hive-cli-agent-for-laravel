<?php

namespace App\Commands;

use App\Services\PrPublisher;
use App\Services\WorktreeInspector;
use App\Services\WorktreeManager;
use App\Support\HiveConfig;
use App\Support\HiveContext;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class PrCommand extends Command
{
    protected $signature = 'pr
                            {branch? : Branch to create PR for}
                            {--all : Create PRs for all branches with changes or commits}
                            {--base=main : Base branch for the PR}
                            {--yes : Skip confirmations (for scripts/GUI)}';

    protected $description = 'Commit, push and create pull requests for worktree branches';

    public function __construct(private readonly PrPublisher $publisher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $context = HiveContext::fromPath(getcwd());
        $config = new HiveConfig($context->path);
        if (! $config->exists()) {
            $this->error('No .hive.json found. Run hive init first.');

            return self::FAILURE;
        }

        $manager = new WorktreeManager($context->path);
        $inspector = app(WorktreeInspector::class);
        $worktrees = $manager->list();

        if (empty($worktrees)) {
            $this->line('No active worktrees.');

            return self::SUCCESS;
        }

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

        if (! $this->option('yes') && ! confirm('Create PRs for these branches?')) {
            return self::SUCCESS;
        }

        $base = $this->option('base') ?? $config->get('main_branch', 'main');
        $errors = [];

        foreach ($targets as $target) {
            $this->line('');
            $this->line("🐝 Processing <comment>{$target['branch']}</comment>...");

            try {
                $result = spin(
                    fn () => $this->publisher->publish($target, $base),
                    "  Publishing {$target['branch']}..."
                );

                if ($result['committed']) {
                    $this->line('  📝 Changes committed');
                }

                if ($result['already_exists']) {
                    $this->line('  ℹ️  PR already exists');
                } else {
                    $this->line("  🔗 {$result['url']}");
                }

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
            return $this->publisher->candidates($inspected);
        }

        $this->error('Specify a branch or use --all');

        return [];
    }
}
