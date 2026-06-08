<?php

namespace App\Support;

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
    public function putPlan(array $tasks): void
    {
        // Map the plan's positional indices to branch names so dependencies are
        // stored as resolvable branch references rather than fragile indices.
        $branchByIndex = array_map(fn ($task) => $task['branch_name'], array_values($tasks));

        $next = [];

        foreach ($tasks as $task) {
            $branch = $task['branch_name'];
            $next[$branch] = array_merge(
                $this->defaults($branch),
                $this->tasks[$branch] ?? [],
                [
                    'branch_name' => $branch,
                    'title' => $task['title'] ?? '',
                    'description' => $task['description'] ?? '',
                    'priority' => $task['priority'] ?? 0,
                    'type' => $task['type'] ?? 'feature',
                    'depends_on' => $this->resolveDependencies($task['depends_on'] ?? [], $branchByIndex),
                    'status' => $task['status'] ?? 'ready',
                ],
            );
        }

        $this->assertAcyclic($next);

        $this->tasks = $next;
        $this->save();
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
            'depends_on' => [],
            'status' => 'ready',
            'runtime' => 'planned',
            'worktree_path' => null,
            'session_id' => null,
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
