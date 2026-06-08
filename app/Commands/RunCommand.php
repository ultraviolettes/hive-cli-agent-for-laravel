<?php

namespace App\Commands;

use App\Process\BackgroundRunner;
use App\Services\AgentLauncher;
use App\Services\WorktreeManager;
use App\Support\HiveConfig;
use App\Support\HiveContext;
use App\Support\HiveState;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;

class RunCommand extends Command
{
    protected $signature = 'run {branch? : Branch/worktree to run the agent in}
                                {--all : Run an agent in every active worktree, in the background}
                                {--background : Detach the agent and return immediately}
                                {--permission-mode= : Override the agent permission mode}
                                {--timeout=1800 : Max seconds a foreground agent may run}
                                {--yes : Skip the bypassPermissions confirmation (for scripts/GUI)}';

    protected $description = 'Launch autonomous Claude Code agent(s) inside worktrees';

    public function handle(): int
    {
        $context = HiveContext::fromPath(getcwd());
        $config = new HiveConfig($context->path);

        if (! $config->exists()) {
            $this->error('No .hive.json found. Run hive init first.');

            return self::FAILURE;
        }

        $branch = $this->argument('branch');

        if (! $this->option('all') && ! $branch) {
            $this->error('Specify a branch or use --all.');

            return self::FAILURE;
        }

        $state = new HiveState($context->path);
        $manager = new WorktreeManager($context->path);
        $mode = $this->option('permission-mode') ?? $config->get('agent_permission_mode', 'bypassPermissions');

        if ($this->option('all')) {
            if (! $this->confirmMode($mode)) {
                $this->line('Aborted.');

                return self::SUCCESS;
            }

            return $this->runAll($context, $manager, $state, $mode);
        }

        $path = $manager->worktreePath($branch);

        if (! is_dir($path)) {
            $this->error("No worktree for {$branch}. Run hive spawn {$branch} first.");

            return self::FAILURE;
        }

        if (! $this->confirmMode($mode)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        return $this->option('background')
            ? $this->runBackground($context, $state, $branch, $path, $mode)
            : $this->runForeground($state, $branch, $path, $mode);
    }

    private function runForeground(HiveState $state, string $branch, string $path, string $mode): int
    {
        $this->warn("🐝 Launching agent in <comment>{$branch}</comment> (permission-mode: {$mode})");

        $outcome = spin(
            fn () => app(AgentLauncher::class)->run($path, $this->buildPrompt($state->get($branch)), $mode, (int) $this->option('timeout')),
            "Agent working on {$branch}..."
        );

        if (! $outcome['successful']) {
            $state->markFailed($branch, $outcome['error'] ?? 'agent error');
            $this->error("Agent failed on {$branch}: " . $outcome['error']);

            return self::FAILURE;
        }

        $state->markRun($branch, $outcome['session_id']);

        $this->line('');
        $this->info("✅ Agent finished <comment>{$branch}</comment>"
            . ($outcome['session_id'] ? " (session {$outcome['session_id']})" : ''));

        if ($outcome['cost_usd'] !== null) {
            $this->line(sprintf('   cost: $%.4f', $outcome['cost_usd']));
        }

        if ($outcome['result'] !== '') {
            $this->line('');
            $this->line($outcome['result']);
        }

        return self::SUCCESS;
    }

    private function runBackground(HiveContext $context, HiveState $state, string $branch, string $path, string $mode): int
    {
        $log = $this->logPath($context, $branch);
        $pid = app(AgentLauncher::class)->launchBackground($path, $this->buildPrompt($state->get($branch)), $mode, $log);
        $state->markRunning($branch, $pid, $log);

        $this->info("🐝 Agent detached for <comment>{$branch}</comment> (pid {$pid})");
        $this->line("   log: {$log}");

        return self::SUCCESS;
    }

    private function runAll(HiveContext $context, WorktreeManager $manager, HiveState $state, string $mode): int
    {
        $worktrees = $manager->list();

        if (empty($worktrees)) {
            $this->line('No active worktrees to run.');

            return self::SUCCESS;
        }

        $launcher = app(AgentLauncher::class);
        $background = app(BackgroundRunner::class);
        $launched = 0;

        foreach ($worktrees as $worktree) {
            $branch = str_replace('refs/heads/', '', $worktree['branch'] ?? '');
            $existing = $state->get($branch);

            if ($existing && ($existing['runtime'] ?? null) === 'running'
                && $existing['pid'] && $background->isRunning((int) $existing['pid'])) {
                $this->line("  ⏭  {$branch} already running (pid {$existing['pid']})");

                continue;
            }

            $log = $this->logPath($context, $branch);
            $pid = $launcher->launchBackground($worktree['path'], $this->buildPrompt($existing), $mode, $log);
            $state->markRunning($branch, $pid, $log);

            $this->line("  🐝 <comment>{$branch}</comment> → pid {$pid}");
            $launched++;
        }

        $this->line('');
        $this->info("{$launched} agent(s) launched in parallel. Track them with hive status.");

        return self::SUCCESS;
    }

    private function confirmMode(string $mode): bool
    {
        if ($mode !== 'bypassPermissions' || $this->option('yes')) {
            return true;
        }

        return confirm('⚠️  bypassPermissions lets the agent run ANY command without asking. Continue?', default: false);
    }

    private function logPath(HiveContext $context, string $branch): string
    {
        return $context->path . '/.hive/logs/' . Str::slug(str_replace('/', '-', $branch)) . '.log';
    }

    /**
     * @param  array<string, mixed>|null  $task
     */
    private function buildPrompt(?array $task): string
    {
        $description = $task['description'] ?? 'the task described in CLAUDE.md';

        return 'You are an autonomous agent in an isolated git worktree. '
            . "Read CLAUDE.md for the full context and rules, then complete this task:\n\n"
            . $description
            . "\n\nWhen done: make sure the test suite passes if one exists, then commit your "
            . 'work with a conventional commit message. Do not open a pull request.';
    }
}
