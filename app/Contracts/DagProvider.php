<?php

namespace App\Contracts;

/**
 * Turns raw backlog text into a structured execution DAG. Implemented by
 * DagAnalyzer (Claude Code primary, laravel/ai fallback); consumers depend on
 * this contract so the provider can be swapped or mocked.
 */
interface DagProvider
{
    /**
     * @param  ?int  $timeout  Seconds before the AI call is aborted (provider default when null).
     * @return array{tasks: array<int, array<string, mixed>>}
     */
    public function analyze(string $rawText, ?string $anthropicApiKey = null, ?int $timeout = null): array;
}
