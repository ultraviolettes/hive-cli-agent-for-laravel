<?php

namespace App\Services;

use App\Support\BeeRole;

/**
 * Assigns a BeeRole to a task: an explicit valid role wins, otherwise the role
 * is inferred from keywords in the title/description, otherwise Fullstack.
 */
final class BeeRoleInferer
{
    /**
     * Keyword rules, evaluated in order — the first matching role wins.
     *
     * @var array<string, array<int, string>>
     */
    private const RULES = [
        'qa' => ['test', 'regression', 'pest', 'phpunit', 'coverage', 'assertion'],
        'devops' => ['deploy', 'queue', 'horizon', 'env', 'docker', 'forge', 'vapor', 'server', 'worker'],
        'security' => ['auth', 'policy', 'permission', 'secret', 'token', 'csrf', 'xss', 'sql injection'],
        'architect' => ['architecture', 'refactor large', 'migration complexe', 'dependency graph', 'design decision'],
        'frontend' => ['blade', 'livewire', 'css', 'ui', 'ux', 'frontend', 'component'],
        'backend' => ['model', 'controller', 'api', 'job', 'listener', 'migration', 'eloquent'],
    ];

    /**
     * @param  array<string, mixed>  $task
     */
    public function infer(array $task): BeeRole
    {
        $explicit = BeeRole::tryFromName($task['role'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        $text = strtolower(trim(($task['title'] ?? '') . ' ' . ($task['description'] ?? '')));

        if ($text !== '') {
            foreach (self::RULES as $role => $keywords) {
                foreach ($keywords as $keyword) {
                    // Leading word boundary: matches "test"/"tests"/"testing" but
                    // not "latest" — robust without being pure substring.
                    if (preg_match('/\b' . preg_quote($keyword, '/') . '/i', $text) === 1) {
                        return BeeRole::from($role);
                    }
                }
            }
        }

        return BeeRole::Fullstack;
    }
}
