<?php

namespace App\Services;

final class ContextBuilder
{
    public function writeContext(string $worktreePath, string $branch, string $taskDescription, array $meta = []): void
    {
        $content = $this->buildContent($branch, $taskDescription, $meta);
        file_put_contents($worktreePath . '/CLAUDE.md', $content);
    }

    private function buildContent(string $branch, string $description, array $meta): string
    {
        $stack = implode(', ', $meta['stack'] ?? ['laravel']);
        $type = $meta['type'] ?? 'feature';
        $issueRef = isset($meta['issue']) ? "\n**GitHub Issue:** #{$meta['issue']}" : '';
        $excRef = isset($meta['exception']) ? "\n**Nightwatch Exception:** `{$meta['exception']}`" : '';
        $tdd = in_array('pest', $meta['stack'] ?? [])
            ? "\n\n## TDD Workflow\n\nWrite failing Pest test first → implement → refactor → commit."
            : '';

        return <<<MD
        # Hive — Task Context

        **Branch:** `{$branch}`
        **Type:** {$type}
        **Stack:** {$stack}{$issueRef}{$excRef}

        ## Your Task

        {$description}

        ## Rules

        - Stay focused on this task only — do not modify unrelated files
        - Follow existing code conventions in this codebase
        - Run `./vendor/bin/pest` before committing
        - Commit with conventional format: `{$this->commitPrefix($type)}: ...`
        - Open a PR when done{$tdd}
        MD;
    }

    private function commitPrefix(string $type): string
    {
        return match ($type) {
            'security' => 'fix',
            'bug' => 'fix',
            'dependency' => 'chore',
            'feature' => 'feat',
            'refactor' => 'refactor',
            default => 'feat',
        };
    }
}
