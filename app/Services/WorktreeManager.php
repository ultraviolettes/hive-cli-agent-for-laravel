<?php

namespace App\Services;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

final class WorktreeManager
{
    public function __construct(private readonly string $projectPath) {}

    public function spawn(string $branch): string
    {
        $path = $this->worktreePath($branch);
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $process = new Process(['git', 'worktree', 'add', $path, '-b', $branch], $this->projectPath);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Failed to create worktree: ' . $process->getErrorOutput());
        }

        return $path;
    }

    public function harvest(string $branch): void
    {
        $path = $this->worktreePath($branch);

        $process = new Process(['git', 'worktree', 'remove', $path, '--force'], $this->projectPath);
        $process->run();
    }

    public function list(): array
    {
        $process = new Process(['git', 'worktree', 'list', '--porcelain'], $this->projectPath);
        $process->run();

        $worktrees = [];
        $current = [];

        foreach (explode("\n", $process->getOutput()) as $line) {
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
