<?php

use App\Process\ProcessResult;
use App\Services\AgentLauncher;
use Tests\Support\FakeBackgroundRunner;
use Tests\Support\FakeProcessRunner;

test('run invokes claude headless in the worktree and parses the outcome', function () {
    $output = json_encode(['result' => 'Done. Committed.', 'session_id' => 'sess-abc', 'total_cost_usd' => 0.1234]);
    $runner = (new FakeProcessRunner)->queue(new ProcessResult(true, $output, '', 0));

    $outcome = (new AgentLauncher('claude', $runner))->run('/wt/x', 'do the task', 'bypassPermissions', 900);

    expect($outcome['successful'])->toBeTrue()
        ->and($outcome['session_id'])->toBe('sess-abc')
        ->and($outcome['result'])->toBe('Done. Committed.')
        ->and($outcome['cost_usd'])->toBe(0.1234);

    $call = $runner->calls[0];
    expect($call['cwd'])->toBe('/wt/x')
        ->and($call['timeout'])->toBe(900)
        ->and($call['command'])->toContain('claude')
        ->and($call['command'])->toContain('-p')
        ->and($call['command'])->toContain('do the task')
        ->and($call['command'])->toContain('--permission-mode')
        ->and($call['command'])->toContain('bypassPermissions');
});

test('run surfaces a failure when the agent process fails', function () {
    $runner = (new FakeProcessRunner)->queue(new ProcessResult(false, '', 'claude blew up', 1));

    $outcome = (new AgentLauncher('claude', $runner))->run('/wt/x', 'task');

    expect($outcome['successful'])->toBeFalse()
        ->and($outcome['error'])->toContain('claude blew up')
        ->and($outcome['session_id'])->toBeNull();
});

test('launchBackground starts a detached agent in the worktree and returns its pid', function () {
    $background = new FakeBackgroundRunner;
    $launcher = new AgentLauncher('claude', new FakeProcessRunner, $background);

    $pid = $launcher->launchBackground('/wt/x', 'do it', 'bypassPermissions', '/wt/x/.hive/logs/x.log');

    expect($pid)->toBe(4242);

    $started = $background->started[0];
    expect($started['cwd'])->toBe('/wt/x')
        ->and($started['logFile'])->toBe('/wt/x/.hive/logs/x.log')
        ->and($started['command'])->toContain('claude')
        ->and($started['command'])->toContain('-p')
        ->and($started['command'])->toContain('do it')
        ->and($started['command'])->toContain('--permission-mode')
        ->and($started['command'])->toContain('bypassPermissions');
});
