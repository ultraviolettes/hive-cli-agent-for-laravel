<?php

namespace App\Services;

use App\Contracts\TaskSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class NightwatchIngester implements TaskSource
{
    public function __construct(
        private readonly string $token,
        private readonly string $projectId,
        private readonly int $limit = 20,
    ) {}

    public function fetch(): array
    {
        $response = Http::withToken($this->token)
            ->get("https://nightwatch.laravel.com/api/projects/{$this->projectId}/exceptions", [
                'resolved' => false,
                'limit' => $this->limit,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Nightwatch API error: ' . $response->status());
        }

        $exceptions = $response->json('data', []);

        return $this->sortByImpact($exceptions);
    }

    public function sortByImpact(array $exceptions): array
    {
        usort($exceptions, fn ($a, $b) => ($b['occurrences'] ?? 0) <=> ($a['occurrences'] ?? 0));

        return $exceptions;
    }

    public function formatForAnalysis(array $exceptions): string
    {
        return implode("\n\n", array_map(function ($e) {
            return "[EXCEPTION] {$e['message']}\n"
                . "File: {$e['file']} (line {$e['line']})\n"
                . "{$e['occurrences']} occurrences — HIGH PRIORITY security/bug fix needed\n"
                . 'Branch: fix/' . Str::slug(substr($e['message'], 0, 40));
        }, $exceptions));
    }
}
