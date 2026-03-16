<?php

namespace App\Services;

use Symfony\Component\Process\Process;

final class GithubIngester
{
    public function __construct(private readonly string $ghBinary = 'gh') {}

    public function fetch(string $repo, ?string $milestone = null, int $limit = 50): array
    {
        if (! $this->ghAvailable()) {
            throw new \RuntimeException('gh CLI not found. Install it: https://cli.github.com');
        }

        $args = [$this->ghBinary, 'issue', 'list', '--repo', $repo, '--limit', (string) $limit, '--json', 'number,title,body,labels'];

        if ($milestone) {
            $args[] = '--search';
            $args[] = "milestone:\"{$milestone}\"";
        }

        $process = new Process($args);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('gh CLI error: ' . $process->getErrorOutput());
        }

        return $this->parseOutput($process->getOutput());
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
        $process = new Process(['which', $this->ghBinary]);
        $process->run();

        return $process->isSuccessful();
    }
}
