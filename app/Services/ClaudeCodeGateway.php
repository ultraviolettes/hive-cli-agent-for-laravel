<?php

namespace App\Services;

use App\Process\ProcessRunner;
use App\Process\SymfonyProcessRunner;

class ClaudeCodeGateway
{
    public function __construct(
        private readonly string $binary = 'claude',
        private readonly ProcessRunner $process = new SymfonyProcessRunner,
    ) {}

    /**
     * Send a prompt to Claude Code in headless mode and return the result.
     */
    public function prompt(string $prompt): string
    {
        $result = $this->process->run([$this->binary, '-p', $prompt, '--output-format', 'json'], null, 120);

        if (! $result->successful) {
            throw new \RuntimeException('Claude Code error: ' . $result->errorOutput);
        }

        $json = json_decode($result->output, true);

        if (! $json || ! isset($json['result'])) {
            throw new \RuntimeException('Unexpected Claude Code response: ' . $result->output);
        }

        return $json['result'];
    }

    /**
     * Send a prompt and parse the result as JSON.
     */
    public function promptJson(string $prompt): array
    {
        $result = $this->prompt($prompt);

        // Strip markdown code fences if present
        $result = preg_replace('/^```(?:json)?\s*\n?/m', '', $result);
        $result = preg_replace('/\n?```\s*$/m', '', $result);

        $decoded = json_decode(trim($result), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to parse Claude Code JSON response: ' . json_last_error_msg());
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException('Expected a JSON object from Claude Code, got: ' . gettype($decoded));
        }

        return $decoded;
    }

    /**
     * Check if Claude Code CLI is available.
     */
    public function isAvailable(): bool
    {
        return $this->process->run(['which', $this->binary])->successful;
    }
}
