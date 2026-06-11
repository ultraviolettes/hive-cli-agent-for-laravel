<?php

use App\Ai\Agents\DagAnalyzerAgent;
use App\Contracts\ClaudeCode;
use App\Exceptions\ClaudeCodeTimeoutException;
use App\Services\DagAnalyzer;

test('throws clear error when no AI provider is available', function () {
    $mockClaude = Mockery::mock(ClaudeCode::class);
    $mockClaude->shouldReceive('isAvailable')->andReturn(false);

    $analyzer = new DagAnalyzer($mockClaude);

    // No Claude Code CLI available and no API key supplied -> no provider.
    expect(fn () => $analyzer->analyze('some backlog', null))
        ->toThrow(\RuntimeException::class, 'No AI provider available');
});

test('uses claude code gateway when available', function () {
    $mockClaude = Mockery::mock(ClaudeCode::class);
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

test('forwards the timeout to the claude code gateway', function () {
    $mockClaude = Mockery::mock(ClaudeCode::class);
    $mockClaude->shouldReceive('isAvailable')->andReturn(true);
    $mockClaude->shouldReceive('promptJson')
        ->once()->with(Mockery::type('string'), 120)
        ->andReturn(['tasks' => []]);

    (new DagAnalyzer($mockClaude))->analyze('backlog', null, 120);
});

test('falls back to laravel/ai when claude code times out and an api key is supplied', function () {
    $mockClaude = Mockery::mock(ClaudeCode::class);
    $mockClaude->shouldReceive('isAvailable')->andReturn(true);
    $mockClaude->shouldReceive('promptJson')->andThrow(new ClaudeCodeTimeoutException(300));

    DagAnalyzerAgent::fake([
        ['tasks' => [['title' => 'Fallback task', 'description' => 'd', 'priority' => 60,
            'depends_on' => [], 'branch_name' => 'fix/fallback', 'status' => 'ready', 'type' => 'bug']]],
    ]);

    $result = (new DagAnalyzer($mockClaude))->analyze('some backlog', 'sk-key');

    expect($result['tasks'])->toHaveCount(1)
        ->and($result['tasks'][0]['branch_name'])->toBe('fix/fallback');
});

function analyzerReturning(array $payload): DagAnalyzer
{
    $mockClaude = Mockery::mock(ClaudeCode::class);
    $mockClaude->shouldReceive('isAvailable')->andReturn(true);
    $mockClaude->shouldReceive('promptJson')->andReturn($payload);

    return new DagAnalyzer($mockClaude);
}

test('rejects an AI plan whose task has no branch name', function () {
    $analyzer = analyzerReturning(['tasks' => [['title' => 'No branch', 'status' => 'ready', 'depends_on' => []]]]);

    expect(fn () => $analyzer->analyze('backlog'))
        ->toThrow(\RuntimeException::class, 'task #1');
});

test('rejects an AI plan whose branch name smells like an argument injection', function () {
    $analyzer = analyzerReturning(['tasks' => [
        ['title' => 'Evil', 'branch_name' => '--force', 'status' => 'ready', 'depends_on' => []],
    ]]);

    expect(fn () => $analyzer->analyze('backlog'))
        ->toThrow(\RuntimeException::class, 'task #1');
});

test('rejects an AI plan with an unknown task status', function () {
    $analyzer = analyzerReturning(['tasks' => [
        ['title' => 'X', 'branch_name' => 'fix/x', 'status' => 'maybe', 'depends_on' => []],
    ]]);

    expect(fn () => $analyzer->analyze('backlog'))
        ->toThrow(\RuntimeException::class, 'status');
});

test('rejects an AI plan with non-integer dependencies', function () {
    $analyzer = analyzerReturning(['tasks' => [
        ['title' => 'X', 'branch_name' => 'fix/x', 'status' => 'ready', 'depends_on' => ['fix/other']],
    ]]);

    expect(fn () => $analyzer->analyze('backlog'))
        ->toThrow(\RuntimeException::class, 'depends_on');
});

test('normalizes loose plan fields instead of rejecting them', function () {
    $analyzer = analyzerReturning(['tasks' => [
        ['branch_name' => 'fix/x', 'status' => 'ready', 'depends_on' => [], 'priority' => 250, 'type' => 'alien'],
    ]]);

    $tasks = $analyzer->analyze('backlog')['tasks'];

    expect($tasks[0]['priority'])->toBe(100)        // clamped
        ->and($tasks[0]['type'])->toBe('feature')   // unknown type falls back
        ->and($tasks[0]['title'])->toBe('');        // missing title becomes empty string
});

test('a timeout without api key surfaces a clear error mentioning the remedies', function () {
    $mockClaude = Mockery::mock(ClaudeCode::class);
    $mockClaude->shouldReceive('isAvailable')->andReturn(true);
    $mockClaude->shouldReceive('promptJson')->andThrow(new ClaudeCodeTimeoutException(300));

    expect(fn () => (new DagAnalyzer($mockClaude))->analyze('some backlog', null))
        ->toThrow(\RuntimeException::class, 'timed out after 300s');
});
