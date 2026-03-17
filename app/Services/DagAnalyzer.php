<?php

namespace App\Services;

use App\Ai\Agents\DagAnalyzerAgent;

class DagAnalyzer
{
    public function __construct(
        private readonly ClaudeCodeGateway $claude = new ClaudeCodeGateway,
    ) {}

    /**
     * Analyze a backlog and return a structured DAG of tasks.
     *
     * Uses Claude Code CLI (headless) by default. Falls back to laravel/ai
     * if ANTHROPIC_API_KEY is configured and Claude Code is not available.
     */
    public function analyze(string $rawText): array
    {
        if ($this->claude->isAvailable()) {
            return $this->viaClaudeCode($rawText);
        }

        if (! empty(config('prism.providers.anthropic.api_key'))) {
            return $this->viaLaravelAi($rawText);
        }

        throw new \RuntimeException(
            "No AI provider available.\n"
            . "Either install Claude Code (https://docs.anthropic.com/en/docs/claude-code)\n"
            . "or set ANTHROPIC_API_KEY in your project's .env file."
        );
    }

    private function viaClaudeCode(string $rawText): array
    {
        $prompt = $this->buildPrompt($rawText);

        return $this->claude->promptJson($prompt);
    }

    private function viaLaravelAi(string $rawText): array
    {
        $response = (new DagAnalyzerAgent)->prompt($rawText);

        return ['tasks' => $response['tasks']];
    }

    private function buildPrompt(string $rawText): string
    {
        return <<<PROMPT
        You are a Laravel project planning expert.
        Given a list of tasks (GitHub issues, audit report, backlog, Nightwatch exceptions),
        analyze logical dependencies and return an execution DAG.

        Prioritization rules:
        - Security (CVE, vulnerability, exception): priority 100, depends_on = [] always
        - Dependency update (minor/patch): priority 70, depends on security tasks if any
        - Bug fix: priority 60, independent unless obvious dependency
        - Feature / major update: priority 30, depends on bug fixes if related
        - Independent tasks should be parallelized (empty depends_on)

        Branch naming: kebab-case with prefix (fix/, chore/, feat/)
        depends_on: array of indexes in the returned tasks array
        status: "ready" if depends_on is empty, "blocked" otherwise
        type: "security" | "bug" | "dependency" | "feature" | "refactor"

        Return ONLY a JSON object with a "tasks" array. Each task has:
        title, description, priority (0-100), depends_on (array of int), branch_name, status ("ready"|"blocked"), type.

        No markdown, no preamble, no explanation. Only valid JSON.

        Here is the input to analyze:

        {$rawText}
        PROMPT;
    }
}
