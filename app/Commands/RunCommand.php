<?php

namespace App\Commands;

use App\Services\AgentLauncher;
use App\Services\WorktreeManager;
use App\Support\HiveConfig;
use App\Support\HiveContext;
use App\Support\HiveState;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\spin;

class RunCommand extends Command
{
    protected $signature = 'run {branch : Branch/worktree to run the agent in}
                                {--permission-mode= : Override the agent permission mode}
                                {--timeout=1800 : Max seconds the agent may run}';

    protected $description = 'Launch an autonomous Claude Code agent inside a worktree';

    public function handle(): int
    {
        $context = HiveContext::fromPath(getcwd());
        $config = new HiveConfig($context->path);

        if (! $config->exists()) {
            $this->error('No .hive.json found. Run hive init first.');

            return self::FAILURE;
        }

        $branch = $this->argument('branch');
        $path = (new WorktreeManager($context->path))->worktreePath($branch);

        if (! is_dir($path)) {
            $this->error("No worktree for {$branch}. Run hive spawn {$branch} first.");

            return self::FAILURE;
        }

        $state = new HiveState($context->path);
        $mode = $this->option('permission-mode') ?? $config->get('agent_permission_mode', 'bypassPermissions');
        $prompt = $this->buildPrompt($state->get($branch));

        $this->warn("🐝 Launching agent in <comment>{$branch}</comment> (permission-mode: {$mode})");

        $outcome = spin(
            fn () => app(AgentLauncher::class)->run($path, $prompt, $mode, (int) $this->option('timeout')),
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
