<?php

namespace App\Commands;

use App\Services\ContextBuilder;
use App\Services\DagAnalyzer;
use App\Services\NightwatchIngester;
use App\Services\WorktreeManager;
use App\Support\HiveConfig;
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
        $config = new HiveConfig(getcwd());
        if (! $config->exists()) {
            $this->error('Run hive init first.');

            return self::FAILURE;
        }

        if (! $this->option('nightwatch')) {
            $this->error('Specify a source: --nightwatch');

            return self::FAILURE;
        }

        $token = env($config->get('nightwatch_token_env', 'NIGHTWATCH_TOKEN'));
        $projectId = env('NIGHTWATCH_PROJECT_ID');

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
        $analyzer = app(DagAnalyzer::class);

        try {
            $response = spin(
                fn () => $analyzer->analyze($rawText),
                '🐝 QueenBee is building the fix plan...'
            );
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $tasks = $response['tasks'];

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

        $readyTasks = array_filter($tasks, fn ($t) => $t['status'] === 'ready');
        $this->line('');
        $this->line(count($readyTasks) . ' fix(es) ready to spawn.');

        if (! confirm('Spawn fix agents now?')) {
            return self::SUCCESS;
        }

        $manager = new WorktreeManager(getcwd());
        $builder = new ContextBuilder;

        foreach ($readyTasks as $task) {
            spin(function () use ($manager, $builder, $task, $config) {
                $path = $manager->spawn($task['branch_name']);
                $builder->writeContext($path, $task['branch_name'], $task['description'], [
                    'stack' => $config->get('stack', []),
                    'type' => 'bug',
                ]);
            }, "Spawning {$task['branch_name']}...");

            $this->line("  ✅ <comment>{$task['branch_name']}</comment>");
        }

        $this->line('');
        $this->info('Fix agents spawned. Open in Superset or your terminal.');

        return self::SUCCESS;
    }
}
