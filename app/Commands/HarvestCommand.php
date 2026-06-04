<?php

namespace App\Commands;

use App\Services\WorktreeManager;
use App\Support\HiveConfig;
use App\Support\HiveContext;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;

class HarvestCommand extends Command
{
    protected $signature = 'harvest {branch : Branch to harvest}';

    protected $description = 'Harvest (remove) a worktree after merge';

    public function handle(): int
    {
        $context = HiveContext::fromPath(getcwd());
        $config = new HiveConfig($context->path);

        if (! $config->exists()) {
            $this->error('No .hive.json found. Run hive init first.');

            return self::FAILURE;
        }

        $branch = $this->argument('branch');
        $manager = new WorktreeManager($context->path);

        if (! confirm("Harvest worktree for {$branch}?")) {
            return self::SUCCESS;
        }

        try {
            spin(fn () => $manager->harvest($branch), 'Harvesting...');
        } catch (\RuntimeException $e) {
            $this->error("Failed to harvest {$branch}: " . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("✅ <comment>{$branch}</comment> harvested.");

        return self::SUCCESS;
    }
}
