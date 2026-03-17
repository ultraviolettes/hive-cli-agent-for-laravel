<?php

namespace App\Commands;

use App\Services\WorktreeInspector;
use App\Services\WorktreeManager;
use App\Support\HiveConfig;
use LaravelZero\Framework\Commands\Command;

class StatusCommand extends Command
{
    protected $signature = 'status';

    protected $description = 'Show active Hive worktrees with detailed status';

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

        $inspector = new WorktreeInspector;

        $this->line('');
        $this->line("🍯 <comment>{$config->get('project')}</comment> — Active Bees ({$this->countByStatus($worktrees, $inspector)})");
        $this->line('');

        $rows = [];
        foreach ($worktrees as $worktree) {
            $info = $inspector->inspect($worktree);
            $rows[] = [
                $info['branch'],
                $info['agent'],
                $info['changes'],
                $info['last_commit'],
            ];
        }

        $this->table(['Branch', 'Status', 'Changes', 'Last Commit'], $rows);

        $this->line('');
        $this->line('Commands:');
        $this->line('  <comment>hive harvest <branch></comment>  — remove a worktree after merge');
        $this->line('  <comment>cd <path> && claude</comment>    — open an agent in a worktree');

        return self::SUCCESS;
    }

    private function countByStatus(array $worktrees, WorktreeInspector $inspector): string
    {
        $counts = ['running' => 0, 'done' => 0, 'pending' => 0, 'idle' => 0];

        foreach ($worktrees as $worktree) {
            $info = $inspector->inspect($worktree);
            $agent = $info['agent'];

            if (str_contains($agent, 'running')) {
                $counts['running']++;
            } elseif (str_contains($agent, 'done')) {
                $counts['done']++;
            } elseif (str_contains($agent, 'pending')) {
                $counts['pending']++;
            } else {
                $counts['idle']++;
            }
        }

        $parts = [];
        if ($counts['running'] > 0) {
            $parts[] = "{$counts['running']} running";
        }
        if ($counts['done'] > 0) {
            $parts[] = "{$counts['done']} done";
        }
        if ($counts['pending'] > 0) {
            $parts[] = "{$counts['pending']} pending";
        }
        if ($counts['idle'] > 0) {
            $parts[] = "{$counts['idle']} idle";
        }

        return implode(', ', $parts);
    }
}
