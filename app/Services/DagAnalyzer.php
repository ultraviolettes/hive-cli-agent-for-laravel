<?php

namespace App\Services;

use App\Ai\Agents\DagAnalyzerAgent;
use App\Contracts\ClaudeCode;
use App\Contracts\DagProvider;
use App\Exceptions\ClaudeCodeTimeoutException;
use App\Support\BranchName;

final class DagAnalyzer implements DagProvider
{
    public function __construct(
        private readonly ClaudeCode $claude = new ClaudeCodeGateway,
    ) {}

    /**
     * Analyze a backlog and return a structured DAG of tasks.
     *
     * Uses Claude Code CLI (headless) by default. Falls back to laravel/ai
     * when an Anthropic API key is supplied and Claude Code is not available.
     */
    public function analyze(string $rawText, ?string $anthropicApiKey = null, ?int $timeout = null): array
    {
        if ($this->claude->isAvailable()) {
            try {
                return $this->validateTasks($this->viaClaudeCode($rawText, $timeout));
            } catch (ClaudeCodeTimeoutException $e) {
                if (! empty($anthropicApiKey)) {
                    return $this->validateTasks($this->viaLaravelAi($rawText, $anthropicApiKey));
                }

                throw new \RuntimeException(
                    $e->getMessage() . "\n"
                    . "Increase the timeout (--timeout flag or \"ai_timeout\" in .hive.json)\n"
                    . "or set ANTHROPIC_API_KEY in your project's .env to enable the laravel/ai fallback."
                );
            }
        }

        if (! empty($anthropicApiKey)) {
            return $this->validateTasks($this->viaLaravelAi($rawText, $anthropicApiKey));
        }

        throw new \RuntimeException(
            "No AI provider available.\n"
            . "Either install Claude Code (https://docs.anthropic.com/en/docs/claude-code)\n"
            . "or set ANTHROPIC_API_KEY in your project's .env file."
        );
    }

    private function viaClaudeCode(string $rawText, ?int $timeout = null): array
    {
        $prompt = $this->buildPrompt($rawText);

        return $this->claude->promptJson($prompt, $timeout);
    }

    private function viaLaravelAi(string $rawText, string $anthropicApiKey): array
    {
        // Inject the key at call time instead of mutating the global env at boot.
        // Both config trees are set because laravel/ai reads `ai.*` while the
        // underlying prism driver reads `prism.*`.
        config()->set('ai.providers.anthropic.key', $anthropicApiKey);
        config()->set('prism.providers.anthropic.api_key', $anthropicApiKey);

        $response = (new DagAnalyzerAgent)->prompt($rawText);

        return ['tasks' => $response['tasks']];
    }

    /**
     * Validate the AI-produced plan before anything downstream trusts it.
     *
     * Fields that reach git/gh or drive the DAG (branch_name, status,
     * depends_on) are rejected outright when unsafe — re-running a plan is
     * cheap, executing a corrupted one is not. Cosmetic fields (title,
     * description, priority, type) are normalized instead.
     *
     * @return array{tasks: array<int, array<string, mixed>>}
     */
    private function validateTasks(array $result): array
    {
        $tasks = $result['tasks'] ?? null;

        if (! is_array($tasks)) {
            throw new \RuntimeException('AI returned an invalid plan: missing "tasks" array.');
        }

        $tasks = array_values($tasks);

        foreach ($tasks as $i => $task) {
            $n = $i + 1;

            if (! is_array($task)) {
                throw new \RuntimeException("AI returned an invalid plan: task #{$n} is not an object.");
            }

            $branch = $task['branch_name'] ?? null;
            if (! is_string($branch)) {
                throw new \RuntimeException("AI returned an invalid plan: task #{$n} has no branch_name.");
            }

            try {
                BranchName::assert($branch);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException("AI returned an invalid plan: task #{$n}: " . $e->getMessage());
            }

            if (! in_array($task['status'] ?? null, ['ready', 'blocked'], true)) {
                throw new \RuntimeException("AI returned an invalid plan: task #{$n} ({$branch}) has an invalid status (expected \"ready\" or \"blocked\").");
            }

            $deps = $task['depends_on'] ?? [];
            if (! is_array($deps)) {
                throw new \RuntimeException("AI returned an invalid plan: task #{$n} ({$branch}) depends_on is not an array.");
            }
            foreach ($deps as $dep) {
                if (! is_int($dep)) {
                    throw new \RuntimeException("AI returned an invalid plan: task #{$n} ({$branch}) has non-integer depends_on entries.");
                }
            }

            // The spawn gate is `status === ready`, so never trust the AI's
            // status: derive it from the dependencies. An adversarial plan
            // cannot claim "ready" to jump ahead of its prerequisites.
            $tasks[$i]['status'] = $deps === [] ? 'ready' : 'blocked';

            $tasks[$i]['title'] = is_string($task['title'] ?? null) ? $task['title'] : '';
            $tasks[$i]['description'] = is_string($task['description'] ?? null) ? $task['description'] : '';
            $tasks[$i]['priority'] = max(0, min(100, (int) ($task['priority'] ?? 0)));
            $tasks[$i]['type'] = in_array($task['type'] ?? null, ['security', 'bug', 'dependency', 'feature', 'refactor'], true)
                ? $task['type']
                : 'feature';
        }

        return ['tasks' => $tasks];
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
