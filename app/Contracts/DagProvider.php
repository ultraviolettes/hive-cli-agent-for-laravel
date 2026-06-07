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
     * @return array{tasks: array<int, array<string, mixed>>}
     */
    public function analyze(string $rawText, ?string $anthropicApiKey = null): array;
}
