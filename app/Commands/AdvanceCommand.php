<?php

namespace App\Commands;

use App\Contracts\DagProvider;
use App\Runners\PlanRunner;
use App\Services\ContextBuilder;
use App\Services\WorktreeManager;
use App\Support\HiveConfig;
use App\Support\HiveContext;
use App\Support\HiveState;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\spin;

class AdvanceCommand extends Command
{
    protected $signature = 'advance {--dry-run : Show what would be spawned without spawning}';

    protected $description = 'Advance the DAG: spawn tasks whose dependencies are now merged';

    public function handle(): int
    {
        $context = HiveContext::fromPath(getcwd());
        $config = new HiveConfig($context->path);

        if (! $config->exists()) {
            $this->error('No .hive.json found. Run hive init first.');

            return self::FAILURE;
        }

        $state = new HiveState($context->path);
        $unblockable = $state->unblockable();

        if (empty($unblockable)) {
            $this->info('Nothing to advance — no blocked task has all its dependencies merged.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line(count($unblockable) . ' task(s) unblocked by merged dependencies:');
        foreach ($unblockable as $task) {
            $this->line("  🔓 <comment>{$task['branch_name']}</comment>");
        }

        if ($this->option('dry-run')) {
            $this->line('');
            $this->line('<comment>--dry-run</comment> — nothing spawned.');

            return self::SUCCESS;
        }

        $runner = new PlanRunner(
            app(DagProvider::class),
            new WorktreeManager($context->path),
            new ContextBuilder,
            $state,
        );
        $stack = $config->get('stack', []);

        $this->line('');
        foreach ($unblockable as $task) {
            $result = spin(
                fn () => $runner->spawnTask($task, $stack),
                "Spawning {$task['branch_name']}..."
            );

            if ($result['error']) {
                $this->line("  ❌ <comment>{$result['branch']}</comment>: {$result['error']}");
            } else {
                $state->markReady($task['branch_name']);
                $this->line("  ✅ <comment>{$result['branch']}</comment>");
            }
        }

        $this->line('');
        $this->info('DAG advanced. Run <comment>hive status</comment> to see the new wave.');

        return self::SUCCESS;
    }
}
