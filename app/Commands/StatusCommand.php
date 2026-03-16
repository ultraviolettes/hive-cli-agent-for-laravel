<?php

namespace App\Commands;

use App\Services\WorktreeManager;
use App\Support\HiveConfig;
use LaravelZero\Framework\Commands\Command;

class StatusCommand extends Command
{
    protected $signature = 'status';

    protected $description = 'Show active Hive worktrees';

    public function handle(): int
    {
        $config = new HiveConfig(getcwd());

        if (! $config->exists()) {
            $this->error('No .hive.json found. Run hive init first.');

            return self::FAILURE;
        }

        $manager = new WorktreeManager(getcwd());
        $worktrees = $manager->list();

        if (empty($worktrees)) {
            $this->line('No active worktrees. Run <comment>hive spawn <branch></comment> to start.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line("🍯 <comment>{$config->get('project')}</comment> — Active Bees");
        $this->line('');

        $rows = array_map(fn ($w) => [
            $w['branch'] ?? '?',
            $w['path'],
            '🐝 running',
        ], $worktrees);

        $this->table(['Branch', 'Path', 'Status'], $rows);

        return self::SUCCESS;
    }
}
