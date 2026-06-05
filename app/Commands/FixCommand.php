<?php

namespace App\Commands;

use App\Runners\PlanRunner;
use App\Services\ContextBuilder;
use App\Services\DagAnalyzer;
use App\Services\NightwatchIngester;
use App\Services\WorktreeManager;
use App\Support\HiveConfig;
use App\Support\HiveContext;
use App\Support\HiveState;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class FixCommand extends Command
{
    protected $signature = 'fix
                            {--nightwatch : Fetch unresolved exceptions from Nightwatch}
                            {--limit=10 : Max exceptions to process}
                            {--dry-run : Show the plan without spawning}';

    protected $description = 'Spawn agents to fix Nightwatch exceptions';

    public function handle(): int
    {
        $context = HiveContext::fromPath(getcwd());
        $config = new HiveConfig($context->path);
        if (! $config->exists()) {
            $this->error('Run hive init first.');

            return self::FAILURE;
        }

        if (! $this->option('nightwatch')) {
            $this->error('Specify a source: --nightwatch');

            return self::FAILURE;
        }

        $token = $context->nightwatchToken();
        $projectId = $context->nightwatchProjectId();

        if (! $token || ! $projectId) {
            $this->error('Set NIGHTWATCH_TOKEN and NIGHTWATCH_PROJECT_ID in your .env');

            return self::FAILURE;
        }

        $ingester = new NightwatchIngester($token, $projectId);

        $exceptions = spin(
            fn () => $ingester->fetch((int) $this->option('limit')),
            '🔭 Fetching Nightwatch exceptions...'
        );

        if (empty($exceptions)) {
            $this->info('🎉 No unresolved exceptions. Your app is clean!');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line(count($exceptions) . ' unresolved exception(s) found.');

        $rawText = $ingester->formatForAnalysis($exceptions);
        $runner = new PlanRunner(
            app(DagAnalyzer::class),
            new WorktreeManager($context->path),
            new ContextBuilder,
            new HiveState($context->path),
        );

        try {
            $tasks = spin(
                fn () => $runner->plan($rawText, $context->anthropicApiKey()),
                '🐝 QueenBee is building the fix plan...'
            );
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->line("🔥 <comment>Fix plan — {$config->get('project')}</comment>");
        $this->line('');

        table(
            ['Branch', 'Exception', 'Priority', 'Status'],
            array_map(fn ($t) => [
                $t['branch_name'],
                substr($t['title'], 0, 50),
                $t['priority'],
                $t['status'] === 'ready' ? '🟡 ready' : '🔒 blocked',
            ], $tasks)
        );

        if ($this->option('dry-run')) {
            $this->line('');
            $this->line('<comment>--dry-run</comment> — no worktrees created.');

            return self::SUCCESS;
        }

        $readyTasks = $runner->readyTasks($tasks);
        $this->line('');
        $this->line(count($readyTasks) . ' fix(es) ready to spawn.');

        if (! confirm('Spawn fix agents now?')) {
            return self::SUCCESS;
        }

        $stack = $config->get('stack', []);

        foreach ($readyTasks as $task) {
            $result = spin(
                fn () => $runner->spawnTask($task, $stack, 'bug'),
                "Spawning {$task['branch_name']}..."
            );

            if ($result['error']) {
                $this->line("  ❌ <comment>{$result['branch']}</comment>: {$result['error']}");
            } else {
                $this->line("  ✅ <comment>{$result['branch']}</comment>");
            }
        }

        $this->line('');
        $this->info('Fix agents spawned. Open in Superset or your terminal.');

        return self::SUCCESS;
    }
}
