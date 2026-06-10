<?php

namespace App\Services;

final class ContextBuilder
{
    private const BLOCK_START = '<!-- HIVE:CONTEXT START -->';

    private const BLOCK_END = '<!-- HIVE:CONTEXT END -->';

    /**
     * Inject the task context into the worktree's CLAUDE.md.
     *
     * The target project may ship its own CLAUDE.md (conventions, stack rules),
     * so we never overwrite it: the Hive context is appended inside dedicated
     * markers, and any previous Hive block is stripped first so re-spawning a
     * task replaces the block instead of stacking duplicates.
     */
    public function writeContext(string $worktreePath, string $branch, string $taskDescription, array $meta = []): void
    {
        $file = $worktreePath . '/CLAUDE.md';

        $existing = is_file($file)
            ? rtrim($this->stripHiveBlock((string) file_get_contents($file)))
            : '';

        $block = self::BLOCK_START . "\n"
            . $this->buildContent($branch, $taskDescription, $meta) . "\n"
            . self::BLOCK_END;

        $content = $existing === '' ? $block : $existing . "\n\n" . $block;

        file_put_contents($file, $content . "\n");
    }

    /**
     * Remove a previously injected Hive block (and its surrounding blank lines)
     * so re-injection stays idempotent.
     */
    private function stripHiveBlock(string $content): string
    {
        $pattern = '/\n*' . preg_quote(self::BLOCK_START, '/') . '.*?' . preg_quote(self::BLOCK_END, '/') . '\n*/s';

        return (string) preg_replace($pattern, '', $content);
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
