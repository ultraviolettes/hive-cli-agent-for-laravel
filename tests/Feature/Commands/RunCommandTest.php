<?php

use App\Process\ProcessResult;
use App\Process\ProcessRunner;
use App\Services\WorktreeManager;
use App\Support\HiveState;
use Tests\Support\FakeProcessRunner;

test('run launches an agent in the worktree and records the session id', function () {
    $tmp = sys_get_temp_dir() . '/hive-run-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    exec("git -C {$tmp} config user.email t@t.t");
    exec("git -C {$tmp} config user.name t");
    exec("git -C {$tmp} commit --allow-empty -m init -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    // Real worktree to run in (uses git for real).
    (new WorktreeManager($tmp))->spawn('feat/x');
    (new HiveState($tmp))->putPlan([
        ['branch_name' => 'feat/x', 'title' => 'X', 'description' => 'Build X', 'priority' => 1, 'type' => 'feature', 'depends_on' => [], 'status' => 'ready'],
    ]);

    // Fake the process layer so AgentLauncher never calls the real claude binary.
    $fake = (new FakeProcessRunner)->queue(
        new ProcessResult(true, json_encode(['result' => 'done', 'session_id' => 'sess-1', 'total_cost_usd' => 0.02]), '', 0)
    );
    app()->instance(ProcessRunner::class, $fake);

    chdir($tmp);

    $this->artisan('run', ['branch' => 'feat/x', '--yes' => true])->assertExitCode(0);

    expect((new HiveState($tmp))->get('feat/x')['session_id'])->toBe('sess-1');

    $agentCall = collect($fake->calls)->first(fn ($c) => in_array('-p', $c['command'], true));
    expect($agentCall)->not->toBeNull()
        ->and($agentCall['cwd'])->toContain('feat-x')
        ->and($agentCall['command'])->toContain('--permission-mode')
        ->and($agentCall['command'])->toContain('bypassPermissions');

    exec("rm -rf {$tmp}");
});

test('run aborts without launching when bypassPermissions is not confirmed', function () {
    $tmp = sys_get_temp_dir() . '/hive-run-noyes-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    exec("git -C {$tmp} config user.email t@t.t");
    exec("git -C {$tmp} config user.name t");
    exec("git -C {$tmp} commit --allow-empty -m init -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));
    (new WorktreeManager($tmp))->spawn('feat/x');

    $fake = new FakeProcessRunner;
    app()->instance(ProcessRunner::class, $fake);

    chdir($tmp);

    // No --yes: the bypassPermissions confirmation defaults to "no" in the
    // non-interactive test harness, so the agent must never be launched.
    $this->artisan('run', ['branch' => 'feat/x'])->assertExitCode(0);

    expect(collect($fake->calls)->contains(fn ($c) => in_array('-p', $c['command'], true)))->toBeFalse();

    exec("rm -rf {$tmp}");
});

test('run fails clearly when the worktree does not exist', function () {
    $tmp = sys_get_temp_dir() . '/hive-run-missing-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    chdir($tmp);

    $this->artisan('run', ['branch' => 'feat/nope'])
        ->assertExitCode(1)
        ->expectsOutputToContain('No worktree for feat/nope');

    exec("rm -rf {$tmp}");
});
