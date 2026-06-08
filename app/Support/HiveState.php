<?php

namespace App\Support;

use App\Services\BeeRoleInferer;

/**
 * Persistent, mutable task state for a Hive project, stored in
 * .hive/state.json (separate from the user-editable .hive.json config).
 *
 * This is what gives a front-end (CLI status, GUI dashboard) something
 * durable to read: the planned DAG plus per-task runtime info (which
 * worktree was spawned, its agent session, failures). Tasks are keyed by
 * branch name, which is also how worktrees are addressed.
 */
final class HiveState
{
    private const VERSION = 1;

    /** @var array<string, array<string, mixed>> keyed by branch_name */
    private array $tasks = [];

    private string $path;

    public function __construct(string $projectPath)
    {
        $this->path = $projectPath . '/.hive/state.json';

        if (is_file($this->path)) {
            $data = json_decode((string) file_get_contents($this->path), true);
            $this->tasks = is_array($data['tasks'] ?? null) ? $data['tasks'] : [];
        }
    }

    /**
     * Persist a freshly analyzed DAG. Plan fields are refreshed from the new
     * analysis; runtime fields (worktree, session, status) of tasks that
     * survive the re-plan are preserved.
     *
     * @param  array<int, array<string, mixed>>  $tasks
     */
    public function putPlan(array $tasks, ?BeeRoleInferer $roleInferer = null): void
    {
        // Map the plan's positional indices to branch names so dependencies are
        // stored as resolvable branch references rather than fragile indices.
        $branchByIndex = array_map(fn ($task) => $task['branch_name'], array_values($tasks));

        $next = [];

        foreach ($tasks as $task) {
            $branch = $task['branch_name'];
            $existing = $this->tasks[$branch] ?? [];

            $next[$branch] = array_merge(
                $this->defaults($branch),
                $existing,
                [
                    'branch_name' => $branch,
                    'title' => $task['title'] ?? '',
                    'description' => $task['description'] ?? '',
                    'priority' => $task['priority'] ?? 0,
                    'type' => $task['type'] ?? 'feature',
                    'depends_on' => $this->resolveDependencies($task['depends_on'] ?? [], $branchByIndex),
                    'status' => $task['status'] ?? 'ready',
                    'role' => $this->resolveRole($existing, $task, $roleInferer)->value,
                ],
            );
        }

        $next = $this->assignBeeIds($next);
        $this->assertAcyclic($next);

        $this->tasks = $next;
        $this->save();
    }

    /**
     * Resolve a task's role. An existing valid role is kept (stable: a re-plan
     * never silently changes it); otherwise it is inferred, or defaults to
     * Fullstack when no inferer is supplied.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $task
     */
    private function resolveRole(array $existing, array $task, ?BeeRoleInferer $inferer): BeeRole
    {
        $existingRole = BeeRole::tryFromName($existing['role'] ?? null);
        if ($existingRole !== null) {
            return $existingRole;
        }

        if ($inferer !== null) {
            return $inferer->infer($task);
        }

        return BeeRole::tryFromName($task['role'] ?? null) ?? BeeRole::Fullstack;
    }

    /**
     * Give every task a stable bee_id (`<role>-<n>`). Existing ids are kept;
     * new ones continue the per-role numbering without collisions.
     *
     * @param  array<string, array<string, mixed>>  $tasks
     * @return array<string, array<string, mixed>>
     */
    private function assignBeeIds(array $tasks): array
    {
        $used = [];
        foreach ($tasks as $task) {
            if (! empty($task['bee_id'])) {
                $used[$task['bee_id']] = true;
            }
        }

        foreach ($tasks as $branch => $task) {
            if (empty($task['bee_id'])) {
                $role = BeeRole::tryFromName($task['role'] ?? null) ?? BeeRole::Fullstack;
                $beeId = $this->nextBeeId($role, $used);
                $used[$beeId] = true;
                $tasks[$branch]['bee_id'] = $beeId;
            }
        }

        return $tasks;
    }

    /**
     * @param  array<string, bool>  $used
     */
    private function nextBeeId(BeeRole $role, array $used): string
    {
        $prefix = $role->value . '-';
        $max = 0;

        foreach (array_keys($used) as $id) {
            if (is_string($id) && str_starts_with($id, $prefix)) {
                $max = max($max, (int) substr($id, strlen($prefix)));
            }
        }

        return $prefix . ($max + 1);
    }

    /**
     * Resolve a task's dependencies (plan indices, or already-resolved branch
     * names) to a clean list of branch names.
     *
     * @param  array<int, int|string>  $dependsOn
     * @param  array<int, string>  $branchByIndex
     * @return array<int, string>
     */
    private function resolveDependencies(array $dependsOn, array $branchByIndex): array
    {
        $branches = [];

        foreach ($dependsOn as $dep) {
            if (is_int($dep)) {
                if (! isset($branchByIndex[$dep])) {
                    throw new \RuntimeException("Invalid dependency index {$dep} in the plan.");
                }
                $branches[] = $branchByIndex[$dep];
            } elseif ($dep !== null && $dep !== '') {
                $branches[] = $dep;
            }
        }

        return array_values(array_unique($branches));
    }

    /**
     * Reject a plan whose dependencies form a cycle — otherwise the involved
     * tasks would stay blocked forever with no error.
     *
     * @param  array<string, array<string, mixed>>  $tasks
     */
    private function assertAcyclic(array $tasks): void
    {
        $visiting = [];
        $visited = [];

        $visit = function (string $branch) use (&$visit, &$visiting, &$visited, $tasks): void {
            if (isset($visited[$branch])) {
                return;
            }
            if (isset($visiting[$branch])) {
                throw new \RuntimeException("Dependency cycle detected in the plan involving '{$branch}'.");
            }

            $visiting[$branch] = true;
            foreach ($tasks[$branch]['depends_on'] ?? [] as $dependency) {
                if (isset($tasks[$dependency])) {
                    $visit($dependency);
                }
            }
            unset($visiting[$branch]);
            $visited[$branch] = true;
        };

        foreach (array_keys($tasks) as $branch) {
            $visit($branch);
        }
    }

    public function markSpawned(string $branch, string $worktreePath, ?string $sessionId = null): void
    {
        $this->update($branch, [
            'runtime' => 'spawned',
            'worktree_path' => $worktreePath,
            'session_id' => $sessionId,
            'error' => null,
        ]);
    }

    public function markFailed(string $branch, string $error): void
    {
        $this->update($branch, [
            'runtime' => 'failed',
            'error' => $error,
        ]);
    }

    /**
     * Record that a task's work has been merged (signalled by `hive harvest`).
     * No-op for branches that were never part of a plan (e.g. manual spawns).
     */
    public function markMerged(string $branch): void
    {
        if (! isset($this->tasks[$branch])) {
            return;
        }

        $this->update($branch, ['runtime' => 'merged']);
    }

    /**
     * Record that an autonomous agent ran in this worktree, capturing its
     * Claude Code session id (resumable later via `claude --resume`).
     */
    public function markRun(string $branch, ?string $sessionId): void
    {
        $this->update($branch, [
            'runtime' => 'spawned',
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Record a detached agent running in the background (its PID + log file,
     * and the session id it was pinned to when known).
     */
    public function markRunning(string $branch, int $pid, string $logPath, ?string $sessionId = null): void
    {
        $changes = [
            'runtime' => 'running',
            'pid' => $pid,
            'log_path' => $logPath,
        ];

        if ($sessionId !== null) {
            $changes['session_id'] = $sessionId;
        }

        $this->update($branch, $changes);
    }

    /**
     * Flip a task's DAG status to ready (used when an unblocked task is spawned).
     */
    public function markReady(string $branch): void
    {
        if (! isset($this->tasks[$branch])) {
            return;
        }

        $this->update($branch, ['status' => 'ready']);
    }

    /**
     * Complete any tasks missing a role or bee_id (e.g. state written before
     * roles existed). Idempotent; only saves when something changed.
     */
    public function backfill(BeeRoleInferer $inferer): void
    {
        $changed = false;

        foreach ($this->tasks as $branch => $task) {
            if (BeeRole::tryFromName($task['role'] ?? null) === null) {
                $this->tasks[$branch]['role'] = $inferer->infer($task)->value;
                $changed = true;
            }
        }

        $assigned = $this->assignBeeIds($this->tasks);
        if ($changed || $assigned !== $this->tasks) {
            $this->tasks = $assigned;
            $this->save();
        }
    }

    /**
     * Ensure a single branch has a role and bee_id, creating the task entry if
     * needed (used by ad-hoc spawn/run on branches not produced by a plan). The
     * branch name seeds inference when there is no description.
     */
    public function ensureRole(string $branch, BeeRoleInferer $inferer): void
    {
        $task = $this->tasks[$branch] ?? null;
        $hasRole = $task !== null && BeeRole::tryFromName($task['role'] ?? null) !== null;
        $hasBee = $task !== null && ! empty($task['bee_id']);

        if ($hasRole && $hasBee) {
            return;
        }

        $task ??= $this->defaults($branch);
        $task['branch_name'] = $branch;

        if (BeeRole::tryFromName($task['role'] ?? null) === null) {
            $task['role'] = $inferer->infer([
                'title' => $task['title'] ?? '',
                'description' => trim(($task['description'] ?? '') . ' ' . str_replace(['/', '-'], ' ', $branch)),
            ])->value;
        }

        $this->tasks[$branch] = $task;
        $this->tasks = $this->assignBeeIds($this->tasks);
        $this->save();
    }

    /**
     * Blocked, not-yet-spawned tasks whose every dependency is merged — i.e.
     * the next wave the DAG can execute.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unblockable(): array
    {
        $merged = [];
        foreach ($this->tasks as $branch => $task) {
            if (($task['runtime'] ?? null) === 'merged') {
                $merged[$branch] = true;
            }
        }

        $ready = [];
        foreach ($this->tasks as $task) {
            $isBlocked = ($task['status'] ?? 'ready') === 'blocked'
                && ($task['runtime'] ?? 'planned') === 'planned';

            if (! $isBlocked) {
                continue;
            }

            $depsMet = true;
            foreach ($task['depends_on'] ?? [] as $dependency) {
                if (! isset($merged[$dependency])) {
                    $depsMet = false;
                    break;
                }
            }

            if ($depsMet) {
                $ready[] = $task;
            }
        }

        return $ready;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->tasks);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $branch): ?array
    {
        return $this->tasks[$branch] ?? null;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function update(string $branch, array $changes): void
    {
        $this->tasks[$branch] = array_merge(
            $this->defaults($branch),
            $this->tasks[$branch] ?? [],
            $changes,
        );

        $this->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(string $branch): array
    {
        return [
            'branch_name' => $branch,
            'title' => '',
            'description' => '',
            'priority' => 0,
            'type' => 'feature',
            'role' => null,
            'bee_id' => null,
            'depends_on' => [],
            'status' => 'ready',
            'runtime' => 'planned',
            'worktree_path' => null,
            'session_id' => null,
            'pid' => null,
            'log_path' => null,
            'error' => null,
        ];
    }

    private function save(): void
    {
        $dir = dirname($this->path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->path,
            json_encode(['version' => self::VERSION, 'tasks' => $this->tasks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
