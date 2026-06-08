<?php

namespace App\Commands;

use App\Services\WorktreeInspector;
use App\Services\WorktreeManager;
use App\Support\BeeStatus;
use App\Support\HiveConfig;
use App\Support\HiveContext;
use App\Support\HiveState;
use LaravelZero\Framework\Commands\Command;

class StatusCommand extends Command
{
    protected $signature = 'status';

    protected $description = 'Show active Hive worktrees with detailed status';

    public function handle(): int
    {
        $context = HiveContext::fromPath(getcwd());
        $config = new HiveConfig($context->path);

        if (! $config->exists()) {
            $this->error('No .hive.json found. Run hive init first.');

            return self::FAILURE;
        }

        $manager = new WorktreeManager($context->path);
        $inspector = new WorktreeInspector;
        $state = new HiveState($context->path);

        $worktrees = $manager->list();

        // Planned tasks that have no worktree yet (blocked, awaiting spawn, or
        // failed) — invisible in the live worktree view, read from the store.
        // Spawned tasks show as Active Bees; merged ones are done.
        $pending = array_values(array_filter(
            $state->all(),
            fn ($task) => ! in_array($task['runtime'] ?? 'planned', ['spawned', 'running', 'merged'], true),
        ));

        if (empty($worktrees) && empty($pending)) {
            $this->line('No active worktrees or planned tasks. Run <comment>hive plan</comment> or <comment>hive spawn <branch></comment> to start.');

            return self::SUCCESS;
        }

        if (! empty($worktrees)) {
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
        }

        if (! empty($pending)) {
            $this->line('');
            $this->line('📋 <comment>Plan</comment> — not yet spawned (' . count($pending) . ')');
            $this->line('');

            $rows = [];
            foreach ($pending as $task) {
                $rows[] = [
                    $task['branch_name'],
                    $this->planStatusLabel($task),
                    $task['priority'] ?? 0,
                    $task['type'] ?? 'feature',
                ];
            }

            $this->table(['Branch', 'Status', 'Priority', 'Type'], $rows);
        }

        $this->line('');
        $this->line('Commands:');
        $this->line('  <comment>hive harvest <branch></comment>  — remove a worktree after merge');
        $this->line('  <comment>cd <path> && claude</comment>    — open an agent in a worktree');

        return self::SUCCESS;
    }

    private function planStatusLabel(array $task): string
    {
        if (($task['runtime'] ?? null) === 'failed') {
            return '❌ failed';
        }

        return ($task['status'] ?? 'ready') === 'blocked' ? '🔒 blocked' : '🟡 ready';
    }

    private function countByStatus(array $worktrees, WorktreeInspector $inspector): string
    {
        $counts = ['running' => 0, 'done' => 0, 'pending' => 0, 'idle' => 0];

        foreach ($worktrees as $worktree) {
            $key = match ($inspector->inspect($worktree)['status']) {
                BeeStatus::Running => 'running',
                BeeStatus::Done => 'done',
                BeeStatus::ChangesPending => 'pending',
                default => 'idle',
            };
            $counts[$key]++;
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
