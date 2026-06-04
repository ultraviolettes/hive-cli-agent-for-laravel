<?php

namespace App\Runners;

use App\Services\ContextBuilder;
use App\Services\DagAnalyzer;
use App\Services\WorktreeManager;

/**
 * Orchestrates the plan -> execute pipeline shared by `hive plan` and
 * `hive fix`, with no terminal I/O so the exact same logic can be driven
 * from a command, an HTTP controller or a queue job (the Hive GUI).
 *
 * plan() builds the DAG; spawnTask()/execute() create the worktrees. The two
 * phases are deliberately separate so a caller can present and approve the
 * plan before anything is spawned.
 */
final class PlanRunner
{
    public function __construct(
        private readonly DagAnalyzer $analyzer,
        private readonly WorktreeManager $manager,
        private readonly ContextBuilder $builder,
    ) {}

    /**
     * Build an execution DAG from raw backlog text.
     *
     * @return array<int, array<string, mixed>>
     */
    public function plan(string $rawText, ?string $anthropicApiKey = null): array
    {
        return $this->analyzer->analyze($rawText, $anthropicApiKey)['tasks'];
    }

    /**
     * Tasks that have no unmet dependencies and can be spawned right now.
     *
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array<int, array<string, mixed>>
     */
    public function readyTasks(array $tasks): array
    {
        return array_values(array_filter($tasks, fn ($t) => ($t['status'] ?? null) === 'ready'));
    }

    /**
     * Spawn a single task's worktree and inject its CLAUDE.md.
     *
     * Never throws: a failure (e.g. the branch already exists) is returned in
     * the `error` key so a batch run can continue with the other tasks.
     *
     * @param  array<string, mixed>  $task
     * @param  array<int, string>  $stack
     * @return array{branch: string, path: string|null, error: string|null}
     */
    public function spawnTask(array $task, array $stack, ?string $typeOverride = null): array
    {
        $branch = $task['branch_name'];

        try {
            $path = $this->manager->spawn($branch);
            $this->builder->writeContext($path, $branch, $task['description'] ?? '', $this->buildMeta($task, $stack, $typeOverride));

            return ['branch' => $branch, 'path' => $path, 'error' => null];
        } catch (\RuntimeException $e) {
            return ['branch' => $branch, 'path' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Spawn every ready task. Returns one result row per spawned task.
     *
     * @param  array<int, array<string, mixed>>  $tasks
     * @param  array<int, string>  $stack
     * @return array<int, array{branch: string, path: string|null, error: string|null}>
     */
    public function execute(array $tasks, array $stack, ?string $typeOverride = null): array
    {
        return array_map(
            fn ($task) => $this->spawnTask($task, $stack, $typeOverride),
            $this->readyTasks($tasks),
        );
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array<int, string>  $stack
     * @return array<string, mixed>
     */
    private function buildMeta(array $task, array $stack, ?string $typeOverride): array
    {
        $meta = [
            'stack' => $stack,
            'type' => $typeOverride ?? $task['type'] ?? 'feature',
        ];

        if (isset($task['issue_number'])) {
            $meta['issue'] = $task['issue_number'];
        }

        return $meta;
    }
}
