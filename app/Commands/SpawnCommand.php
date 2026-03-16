<?php

namespace App\Commands;

use App\Services\ContextBuilder;
use App\Services\WorktreeManager;
use App\Support\HiveConfig;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\spin;

class SpawnCommand extends Command
{
    protected $signature = 'spawn {branch : Branch name (e.g. feat/my-feature)}
                                  {--context= : Task context to inject in CLAUDE.md}';

    protected $description = 'Spawn a new Claude Code agent in an isolated worktree';

    public function handle(): int
    {
        $config = new HiveConfig(getcwd());

        if (! $config->exists()) {
            $this->error('No .hive.json found. Run hive init first.');

            return self::FAILURE;
        }

        $branch = $this->argument('branch');
        $context = $this->option('context');
        $manager = new WorktreeManager(getcwd());

        $this->info("🐝 Spawning bee for <comment>{$branch}</comment>...");

        $path = spin(
            fn () => $manager->spawn($branch),
            'Creating worktree...'
        );

        if ($context) {
            $builder = new ContextBuilder;
            $builder->writeContext($path, $branch, $context, [
                'stack' => $config->get('stack', []),
            ]);
            $this->line('✅ CLAUDE.md injected with task context');
        }

        $this->line('');
        $this->info("✅ Worktree ready at: <comment>{$path}</comment>");
        $this->line('');
        $this->line('Open in Superset or run:');
        $this->line("  <comment>cd {$path} && claude</comment>");

        return self::SUCCESS;
    }
}
