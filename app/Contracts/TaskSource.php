<?php

namespace App\Contracts;

/**
 * A source of work items for the QueenBee (GitHub issues, Nightwatch
 * exceptions, ...). Sources are configured at construction time so the
 * engine — a command today, the GUI tomorrow — consumes any of them
 * through the same two calls: fetch the raw items, then format them as
 * text for the DAG analysis.
 */
interface TaskSource
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(): array;

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function formatForAnalysis(array $items): string;
}
