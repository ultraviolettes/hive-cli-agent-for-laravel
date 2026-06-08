<?php

namespace App\Commands;

use App\Commands\Concerns\LaunchesAgents;
use App\Contracts\DagProvider;
use App\Runners\PlanRunner;
use App\Services\ContextBuilder;
use App\Services\GithubIngester;
use App\Services\WorktreeManager;
use App\Support\HiveConfig;
use App\Support\HiveContext;
use App\Support\HiveState;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class PlanCommand extends Command
{
    use LaunchesAgents;

    protected $signature = 'plan
                            {--github= : GitHub repo (owner/repo)}
                            {--milestone= : GitHub milestone filter}
                            {--text= : Raw text input (backlog, audit...)}
                            {--dry-run : Show the plan without spawning}
                            {--run : Launch an autonomous agent in each spawned worktree}
                            {--permission-mode= : Agent permission mode for --run}
                            {--yes : Skip confirmations (for scripts/GUI)}';

    protected $description = 'Analyze a backlog and orchestrate parallel agents';

    public function handle(): int
    {
        $context = HiveContext::fromPath(getcwd());
        $config = new HiveConfig($context->path);
        if (! $config->exists()) {
            $this->error('Run hive init first.');

            return self::FAILURE;
        }

        $rawText = $this->getRawInput();
        if (! $rawText) {
            return self::FAILURE;
        }

        $this->line('');
        $state = new HiveState($context->path);
        $runner = new PlanRunner(
            app(DagProvider::class),
            new WorktreeManager($context->path),
            new ContextBuilder,
            $state,
        );

        try {
            $tasks = spin(
                fn () => $runner->plan($rawText, $context->anthropicApiKey()),
                '🐝 QueenBee is analyzing your backlog...'
            );
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->line("📋 <comment>Execution plan — {$config->get('project')}</comment>");
        $this->line('');

        table(
            ['#', 'Branch', 'Title', 'Priority', 'Status', 'Depends on'],
            array_map(fn ($t, $i) => [
                $i + 1,
                $t['branch_name'],
                substr($t['title'], 0, 45),
                $t['priority'],
                $t['status'] === 'ready' ? '🟡 ready' : '🔒 blocked',
                empty($t['depends_on']) ? '—' : implode(', ', array_map(fn ($d) => '#' . ($d + 1), $t['depends_on'])),
            ], $tasks, array_keys($tasks))
        );

        if ($this->option('dry-run')) {
            $this->line('');
            $this->line('<comment>--dry-run</comment> mode — no worktrees created.');

            return self::SUCCESS;
        }

        $readyTasks = $runner->readyTasks($tasks);
        $this->line('');
        $this->line(count($readyTasks) . ' task(s) ready to spawn in parallel.');

        if (! $this->option('yes') && ! confirm('Spawn these agents now?')) {
            return self::SUCCESS;
        }

        $stack = $config->get('stack', []);
        $mode = $this->permissionMode($config);
        $autoRun = $this->option('run') && $this->confirmBypass($mode);

        if ($this->option('run') && ! $autoRun) {
            $this->warn('--run ignored: bypassPermissions not confirmed. Spawning worktrees only.');
        }

        foreach ($readyTasks as $task) {
            $result = spin(
                fn () => $runner->spawnTask($task, $stack),
                "Spawning {$task['branch_name']}..."
            );

            if ($result['error']) {
                $this->line("  ❌ <comment>{$result['branch']}</comment>: {$result['error']}");

                continue;
            }

            $this->line("  ✅ <comment>{$result['branch']}</comment>");

            if ($autoRun) {
                $bee = $this->launchBee($context, $state, $result['branch'], $result['path'], $mode);
                $this->line("     🐝 agent running (pid {$bee['pid']}, session {$bee['session_id']})");
            }
        }

        $this->line('');
        $this->info($autoRun ? 'All agents spawned and running.' : 'All agents spawned. Open in Superset or your terminal.');
        $this->line('Run <comment>hive status</comment> to see active worktrees.');

        return self::SUCCESS;
    }

    private function getRawInput(): ?string
    {
        if ($text = $this->option('text')) {
            return $text;
        }

        if ($repo = $this->option('github')) {
            $ingester = app(GithubIngester::class);

            try {
                $issues = spin(
                    fn () => $ingester->fetch($repo, $this->option('milestone')),
                    "Fetching issues from {$repo}..."
                );
                if (empty($issues)) {
                    warning('No issues found.');

                    return null;
                }
                $this->line(count($issues) . ' issue(s) fetched from ' . $repo);

                return $ingester->formatForAnalysis($issues);
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());

                return null;
            }
        }

        $this->error('Provide --github owner/repo or --text "your backlog"');

        return null;
    }
}
