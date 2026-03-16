<?php

namespace App\Support;

final class HiveConfig
{
    private array $data = [];

    private string $path;

    public function __construct(private readonly string $projectPath)
    {
        $this->path = $projectPath . '/.hive.json';
        if (file_exists($this->path)) {
            $this->data = json_decode(file_get_contents($this->path), true) ?? [];
        }
    }

    public function init(string $project, array $stack): void
    {
        $this->data = [
            'project' => $project,
            'stack' => $stack,
            'main_branch' => 'main',
            'worktrees_path' => '.hive/worktrees',
        ];
        $this->save();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function exists(): bool
    {
        return file_exists($this->path);
    }

    private function save(): void
    {
        file_put_contents($this->path, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
