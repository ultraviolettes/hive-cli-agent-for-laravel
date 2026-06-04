<?php

use App\Services\ClaudeCodeGateway;
use App\Services\DagAnalyzer;

test('throws clear error when no AI provider is available', function () {
    $mockClaude = Mockery::mock(ClaudeCodeGateway::class);
    $mockClaude->shouldReceive('isAvailable')->andReturn(false);

    $analyzer = new DagAnalyzer($mockClaude);

    // No Claude Code CLI available and no API key supplied -> no provider.
    expect(fn () => $analyzer->analyze('some backlog', null))
        ->toThrow(\RuntimeException::class, 'No AI provider available');
});

test('uses claude code gateway when available', function () {
    $mockClaude = Mockery::mock(ClaudeCodeGateway::class);
    $mockClaude->shouldReceive('isAvailable')->andReturn(true);
    $mockClaude->shouldReceive('promptJson')->once()->andReturn([
        'tasks' => [
            ['title' => 'Test task', 'description' => 'desc', 'priority' => 60,
                'depends_on' => [], 'branch_name' => 'fix/test', 'status' => 'ready', 'type' => 'bug'],
        ],
    ]);

    $analyzer = new DagAnalyzer($mockClaude);
    $result = $analyzer->analyze('Fix the login bug');

    expect($result['tasks'])->toHaveCount(1)
        ->and($result['tasks'][0]['title'])->toBe('Test task');
});
