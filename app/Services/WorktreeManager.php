<?php

namespace App\Services;

use App\Process\ProcessRunner;
use App\Process\SymfonyProcessRunner;
use Illuminate\Support\Str;

final class WorktreeManager
{
    public function __construct(
        private readonly string $projectPath,
        private readonly ProcessRunner $process = new SymfonyProcessRunner,
    ) {}

    public function spawn(string $branch): string
    {
        $path = $this->worktreePath($branch);
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = $this->process->run(['git', 'worktree', 'add', $path, '-b', $branch], $this->projectPath);

        if (! $result->successful) {
            throw new \RuntimeException('Failed to create worktree: ' . $result->errorOutput);
        }

        return $path;
    }

    public function harvest(string $branch): void
    {
        $path = $this->worktreePath($branch);

        $result = $this->process->run(['git', 'worktree', 'remove', $path, '--force'], $this->projectPath);

        if (! $result->successful) {
            throw new \RuntimeException('Failed to remove worktree: ' . $result->errorOutput);
        }
    }

    public function list(): array
    {
        $result = $this->process->run(['git', 'worktree', 'list', '--porcelain'], $this->projectPath);

        if (! $result->successful) {
            throw new \RuntimeException('Failed to list worktrees: ' . $result->errorOutput);
        }

        $worktrees = [];
        $current = [];

        foreach (explode("\n", $result->output) as $line) {
            if (str_starts_with($line, 'worktree ')) {
                if ($current) {
                    $worktrees[] = $current;
                }
                $current = ['path' => substr($line, 9)];
            } elseif (str_starts_with($line, 'branch ')) {
                $current['branch'] = substr($line, 7);
            }
        }
        if ($current) {
            $worktrees[] = $current;
        }

        return array_values(array_filter($worktrees, fn ($w) => str_contains($w['path'] ?? '', '.hive/worktrees')));
    }

    public function worktreePath(string $branch): string
    {
        $slug = Str::slug(str_replace('/', '-', $branch));

        return $this->projectPath . '/.hive/worktrees/' . $slug;
    }
}
