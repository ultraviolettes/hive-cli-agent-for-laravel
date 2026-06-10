<?php

namespace App\Services;

use App\Contracts\TaskSource;
use App\Process\ProcessRunner;
use App\Process\SymfonyProcessRunner;

final class GithubIngester implements TaskSource
{
    public function __construct(
        private readonly string $repo,
        private readonly ?string $milestone = null,
        private readonly int $limit = 50,
        private readonly string $ghBinary = 'gh',
        private readonly ProcessRunner $process = new SymfonyProcessRunner,
    ) {}

    public function fetch(): array
    {
        if (! $this->ghAvailable()) {
            throw new \RuntimeException('gh CLI not found. Install it: https://cli.github.com');
        }

        $args = [$this->ghBinary, 'issue', 'list', '--repo', $this->repo, '--limit', (string) $this->limit, '--json', 'number,title,body,labels'];

        if ($this->milestone) {
            $args[] = '--search';
            $args[] = "milestone:\"{$this->milestone}\"";
        }

        $result = $this->process->run($args);

        if (! $result->successful) {
            throw new \RuntimeException('gh CLI error: ' . $result->errorOutput);
        }

        return $this->parseOutput($result->output);
    }

    public function parseOutput(string $json): array
    {
        $raw = json_decode($json, true) ?? [];

        return array_map(fn ($issue) => [
            'number' => $issue['number'],
            'title' => $issue['title'],
            'body' => $issue['body'] ?? '',
            'labels' => array_map(fn ($l) => $l['name'], $issue['labels'] ?? []),
        ], $raw);
    }

    public function formatForAnalysis(array $issues): string
    {
        return implode("\n\n", array_map(function ($issue) {
            $labels = empty($issue['labels']) ? '' : ' [' . implode(', ', $issue['labels']) . ']';

            return "#{$issue['number']}: {$issue['title']}{$labels}\n{$issue['body']}";
        }, $issues));
    }

    private function ghAvailable(): bool
    {
        return $this->process->run(['which', $this->ghBinary])->successful;
    }
}
