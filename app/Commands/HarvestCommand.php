<?php

namespace App\Commands;

use App\Commands\Concerns\ValidatesBranch;
use App\Services\WorktreeManager;
use App\Support\HiveConfig;
use App\Support\HiveContext;
use App\Support\HiveState;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;

class HarvestCommand extends Command
{
    use ValidatesBranch;

    protected $signature = 'harvest {branch : Branch to harvest}
                                    {--force : Skip the confirmation prompt (for scripts/GUI)}';

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
        if (! $this->validBranch($branch)) {
            return self::FAILURE;
        }

        $manager = new WorktreeManager($context->path);

        if (! $this->option('force') && ! confirm("Harvest worktree for {$branch}?")) {
            return self::SUCCESS;
        }

        try {
            spin(fn () => $manager->harvest($branch), 'Harvesting...');
        } catch (\RuntimeException $e) {
            $this->error("Failed to harvest {$branch}: " . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("✅ <comment>{$branch}</comment> harvested.");

        // Harvest means the work was merged: record it and advance the DAG.
        $state = new HiveState($context->path);
        $state->markMerged($branch);

        $unblocked = $state->unblockable();
        if (! empty($unblocked)) {
            $this->line(count($unblocked) . ' task(s) now unblocked — run <comment>hive advance</comment> to spawn them.');
        }

        return self::SUCCESS;
    }
}
