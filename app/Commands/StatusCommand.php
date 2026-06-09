<?php

namespace App\Commands;

use App\Process\BackgroundRunner;
use App\Services\BeeRoleInferer;
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
        // Complete roles/bee_ids for any task written before roles existed.
        $state->backfill(app(BeeRoleInferer::class));

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
            $rows = [];
            $counts = ['running' => 0, 'done' => 0, 'pending' => 0, 'idle' => 0];

            foreach ($worktrees as $worktree) {
                $info = $inspector->inspect($worktree);
                $task = $state->get($info['branch']);
                $live = $this->liveStatus($info, $task);
                $counts[$live['key']]++;

                $rows[] = [
                    $info['branch'],
                    $task['role'] ?? '—',
                    $task['bee_id'] ?? '—',
                    $live['label'],
                    $task['pid'] ?? '—',
                    $this->shortSession($task),
                    $info['changes'],
                ];
            }

            $this->line('');
            $this->line("🍯 <comment>{$config->get('project')}</comment> — Active Bees ({$this->summarize($counts)})");
            $this->line('');

            $this->table(['Branch', 'Role', 'Bee', 'Status', 'PID', 'Session', 'Changes'], $rows);
        }

        if (! empty($pending)) {
            $this->line('');
            $this->line('📋 <comment>Plan</comment> — not yet spawned (' . count($pending) . ')');
            $this->line('');

            $rows = [];
            foreach ($pending as $task) {
                $rows[] = [
                    $task['branch_name'],
                    $task['role'] ?? '—',
                    $task['bee_id'] ?? '—',
                    $this->planStatusLabel($task),
                    $task['priority'] ?? 0,
                ];
            }

            $this->table(['Branch', 'Role', 'Bee', 'Status', 'Priority'], $rows);
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

    /**
     * Live status of a worktree. A background bee's liveness comes from its
     * stored PID (reliable); otherwise fall back to the git-derived status.
     *
     * @param  array<string, mixed>  $info
     * @param  array<string, mixed>|null  $task
     * @return array{label: string, key: string}
     */
    private function liveStatus(array $info, ?array $task): array
    {
        if ($task !== null && ($task['runtime'] ?? null) === 'running' && ! empty($task['pid'])) {
            return app(BackgroundRunner::class)->isRunning((int) $task['pid'])
                ? ['label' => '🐝 running', 'key' => 'running']
                : ['label' => '✅ finished', 'key' => 'done'];
        }

        return match ($info['status']) {
            BeeStatus::Running => ['label' => $info['agent'], 'key' => 'running'],
            BeeStatus::Done => ['label' => $info['agent'], 'key' => 'done'],
            BeeStatus::ChangesPending => ['label' => $info['agent'], 'key' => 'pending'],
            default => ['label' => $info['agent'], 'key' => 'idle'],
        };
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function summarize(array $counts): string
    {
        $parts = [];
        foreach (['running', 'done', 'pending', 'idle'] as $key) {
            if ($counts[$key] > 0) {
                $parts[] = "{$counts[$key]} {$key}";
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>|null  $task
     */
    private function shortSession(?array $task): string
    {
        $sessionId = $task['session_id'] ?? null;

        return is_string($sessionId) && $sessionId !== '' ? substr($sessionId, 0, 8) : '—';
    }
}
