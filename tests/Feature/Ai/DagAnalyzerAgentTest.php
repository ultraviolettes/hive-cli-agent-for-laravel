<?php

use App\Ai\Agents\DagAnalyzerAgent;
use Laravel\Ai\Prompts\AgentPrompt;

test('returns structured tasks array', function () {
    DagAnalyzerAgent::fake([
        [
            'tasks' => [
                ['title' => 'Fix SQL injection CVE', 'description' => 'Update composer deps',
                    'priority' => 100, 'depends_on' => [], 'branch_name' => 'fix/sql-cve',
                    'status' => 'ready', 'type' => 'security'],
                ['title' => 'Update Laravel to 12', 'description' => 'Major version bump',
                    'priority' => 30, 'depends_on' => [0], 'branch_name' => 'chore/laravel-12',
                    'status' => 'blocked', 'type' => 'dependency'],
            ],
        ],
    ]);

    $response = (new DagAnalyzerAgent)->prompt('Fix CVE then update Laravel');

    expect($response['tasks'])->toHaveCount(2)
        ->and($response['tasks'][0]['status'])->toBe('ready')
        ->and($response['tasks'][1]['status'])->toBe('blocked');
});

test('security tasks always have no dependencies', function () {
    DagAnalyzerAgent::fake([
        [
            'tasks' => [
                ['title' => 'CVE fix', 'description' => '...', 'priority' => 100,
                    'depends_on' => [], 'branch_name' => 'fix/cve', 'status' => 'ready', 'type' => 'security'],
            ],
        ],
    ]);

    $response = (new DagAnalyzerAgent)->prompt('CVE critical');

    expect($response['tasks'][0]['depends_on'])->toBeEmpty()
        ->and($response['tasks'][0]['priority'])->toBe(100);
});

test('agent was prompted with the raw input', function () {
    DagAnalyzerAgent::fake([
        ['tasks' => []],
    ]);

    (new DagAnalyzerAgent)->prompt('mon backlog de sprint');

    DagAnalyzerAgent::assertPrompted(function (AgentPrompt $prompt) {
        return $prompt->contains('mon backlog de sprint');
    });
});
