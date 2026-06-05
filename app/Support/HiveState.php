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
                    'depends_on' => $task['depends_on'] ?? [],
                    'status' => $task['status'] ?? 'ready',
                ],
            );
        }

        $this->tasks = $next;
        $this->save();
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
