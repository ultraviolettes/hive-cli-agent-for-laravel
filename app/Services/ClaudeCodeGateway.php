<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class ClaudeCodeGateway
{
    public function __construct(private readonly string $binary = 'claude') {}

    /**
     * Send a prompt to Claude Code in headless mode and return the result.
     */
    public function prompt(string $prompt): string
    {
        $process = new Process([$this->binary, '-p', $prompt, '--output-format', 'json']);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Claude Code error: ' . $process->getErrorOutput());
        }

        $json = json_decode($process->getOutput(), true);

        if (! $json || ! isset($json['result'])) {
            throw new \RuntimeException('Unexpected Claude Code response: ' . $process->getOutput());
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

        return $decoded;
    }

    /**
     * Check if Claude Code CLI is available.
     */
    public function isAvailable(): bool
    {
        $process = new Process(['which', $this->binary]);
        $process->run();

        return $process->isSuccessful();
    }
}
