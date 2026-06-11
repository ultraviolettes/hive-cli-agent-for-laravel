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
     * @param  ?int  $timeout  Seconds before the call is aborted (implementation default when null).
     * @return array<string, mixed>
     *
     * @throws \App\Exceptions\ClaudeCodeTimeoutException
     */
    public function promptJson(string $prompt, ?int $timeout = null): array;
}
