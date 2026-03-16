<?php

namespace App\Commands;

use App\Services\WorktreeManager;
use App\Support\HiveConfig;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;

class HarvestCommand extends Command
{
    protected $signature = 'harvest {branch : Branch to harvest}';

    protected $description = 'Harvest (remove) a worktree after merge';

    public function handle(): int
    {
        $config = new HiveConfig(getcwd());

        if (! $config->exists()) {
            $this->error('No .hive.json found. Run hive init first.');

            return self::FAILURE;
        }

        $branch = $this->argument('branch');
        $manager = new WorktreeManager(getcwd());

        if (! confirm("Harvest worktree for {$branch}?")) {
            return self::SUCCESS;
        }

        spin(fn () => $manager->harvest($branch), 'Harvesting...');

        $this->info("✅ <comment>{$branch}</comment> harvested.");

        return self::SUCCESS;
    }
}
