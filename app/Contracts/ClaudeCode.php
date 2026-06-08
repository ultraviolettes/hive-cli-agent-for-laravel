<?php

namespace App\Contracts;

/**
 * Headless Claude Code gateway, as consumed by DagAnalyzer. Kept narrow to
 * what callers need so it can be mocked without the real `claude` binary.
 */
interface ClaudeCode
{
    public function isAvailable(): bool;

    /**
     * @return array<string, mixed>
     */
    public function promptJson(string $prompt): array;
}
