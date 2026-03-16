<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class DagAnalyzerAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
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

        Return ONLY the JSON, no markdown, no preamble.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tasks' => $schema->array()->required(),
        ];
    }
}
