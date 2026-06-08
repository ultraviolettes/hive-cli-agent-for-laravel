<?php

use App\Support\HiveState;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir() . '/hive-state-' . uniqid();
    mkdir($this->tmp);
});

afterEach(fn () => exec("rm -rf {$this->tmp}"));

test('persists a planned DAG and reloads it from disk', function () {
    $state = new HiveState($this->tmp);
    $state->putPlan([
        ['branch_name' => 'fix/a', 'title' => 'Fix A', 'description' => 'd', 'priority' => 100, 'type' => 'security', 'depends_on' => [], 'status' => 'ready'],
        ['branch_name' => 'feat/b', 'title' => 'Feat B', 'description' => 'd2', 'priority' => 30, 'type' => 'feature', 'depends_on' => [0], 'status' => 'blocked'],
    ]);

    $reloaded = new HiveState($this->tmp);

    expect($reloaded->all())->toHaveCount(2)
        ->and($reloaded->get('fix/a')['status'])->toBe('ready')
        ->and($reloaded->get('fix/a')['runtime'])->toBe('planned')
        ->and($reloaded->get('feat/b')['status'])->toBe('blocked')
        ->and($reloaded->get('feat/b')['depends_on'])->toBe(['fix/a']);
});

test('stores dependencies as resolved branch names, not plan indices', function () {
    $state = new HiveState($this->tmp);
    $state->putPlan([
        ['branch_name' => 'fix/a', 'title' => 'A', 'description' => 'd', 'priority' => 1, 'type' => 'bug', 'depends_on' => [], 'status' => 'ready'],
        ['branch_name' => 'feat/b', 'title' => 'B', 'description' => 'd', 'priority' => 1, 'type' => 'feature', 'depends_on' => [0], 'status' => 'blocked'],
    ]);

    expect($state->get('feat/b')['depends_on'])->toBe(['fix/a']);
});

test('putPlan rejects a dependency cycle', function () {
    $state = new HiveState($this->tmp);

    expect(fn () => $state->putPlan([
        ['branch_name' => 'a', 'title' => 'A', 'description' => 'd', 'priority' => 1, 'type' => 'feature', 'depends_on' => [1], 'status' => 'blocked'],
        ['branch_name' => 'b', 'title' => 'B', 'description' => 'd', 'priority' => 1, 'type' => 'feature', 'depends_on' => [0], 'status' => 'blocked'],
    ]))->toThrow(\RuntimeException::class);
});

test('putPlan rejects an out-of-range dependency index', function () {
    $state = new HiveState($this->tmp);

    expect(fn () => $state->putPlan([
        ['branch_name' => 'a', 'title' => 'A', 'description' => 'd', 'priority' => 1, 'type' => 'feature', 'depends_on' => [99], 'status' => 'blocked'],
    ]))->toThrow(\RuntimeException::class);
});

test('unblockable returns blocked tasks once every dependency is merged', function () {
    $state = new HiveState($this->tmp);
    $state->putPlan([
        ['branch_name' => 'fix/a', 'title' => 'A', 'description' => 'd', 'priority' => 100, 'type' => 'security', 'depends_on' => [], 'status' => 'ready'],
        ['branch_name' => 'feat/b', 'title' => 'B', 'description' => 'd', 'priority' => 30, 'type' => 'feature', 'depends_on' => [0], 'status' => 'blocked'],
    ]);

    expect($state->unblockable())->toBeEmpty();

    $state->markMerged('fix/a');

    $unblockable = $state->unblockable();
    expect($unblockable)->toHaveCount(1)
        ->and($unblockable[0]['branch_name'])->toBe('feat/b');
});

test('a task stays blocked until all of its dependencies are merged', function () {
    $state = new HiveState($this->tmp);
    $state->putPlan([
        ['branch_name' => 'a', 'title' => 'A', 'description' => 'd', 'priority' => 1, 'type' => 'bug', 'depends_on' => [], 'status' => 'ready'],
        ['branch_name' => 'b', 'title' => 'B', 'description' => 'd', 'priority' => 1, 'type' => 'bug', 'depends_on' => [], 'status' => 'ready'],
        ['branch_name' => 'c', 'title' => 'C', 'description' => 'd', 'priority' => 1, 'type' => 'feature', 'depends_on' => [0, 1], 'status' => 'blocked'],
    ]);

    $state->markMerged('a');
    expect($state->unblockable())->toBeEmpty();

    $state->markMerged('b');
    expect($state->unblockable())->toHaveCount(1)
        ->and($state->unblockable()[0]['branch_name'])->toBe('c');
});

test('markMerged is a no-op for an unknown (manual) branch', function () {
    $state = new HiveState($this->tmp);
    $state->markMerged('feat/never-planned');

    expect($state->get('feat/never-planned'))->toBeNull();
});

test('an already-spawned blocked task is not re-offered by unblockable', function () {
    $state = new HiveState($this->tmp);
    $state->putPlan([
        ['branch_name' => 'fix/a', 'title' => 'A', 'description' => 'd', 'priority' => 1, 'type' => 'bug', 'depends_on' => [], 'status' => 'ready'],
        ['branch_name' => 'feat/b', 'title' => 'B', 'description' => 'd', 'priority' => 1, 'type' => 'feature', 'depends_on' => [0], 'status' => 'blocked'],
    ]);
    $state->markMerged('fix/a');
    $state->markSpawned('feat/b', '/wt/b');

    expect($state->unblockable())->toBeEmpty();
});

test('records a spawned worktree without losing plan fields', function () {
    $state = new HiveState($this->tmp);
    $state->putPlan([
        ['branch_name' => 'fix/a', 'title' => 'Fix A', 'description' => 'd', 'priority' => 100, 'type' => 'security', 'depends_on' => [], 'status' => 'ready'],
    ]);

    $state->markSpawned('fix/a', '/tmp/wt/fix-a', 'sess-123');

    $task = (new HiveState($this->tmp))->get('fix/a');
    expect($task['runtime'])->toBe('spawned')
        ->and($task['worktree_path'])->toBe('/tmp/wt/fix-a')
        ->and($task['session_id'])->toBe('sess-123')
        ->and($task['title'])->toBe('Fix A');
});

test('markSpawned creates an entry for a manual spawn not in any plan', function () {
    $state = new HiveState($this->tmp);

    $state->markSpawned('feat/manual', '/tmp/wt/manual');

    expect($state->get('feat/manual')['runtime'])->toBe('spawned')
        ->and($state->get('feat/manual')['worktree_path'])->toBe('/tmp/wt/manual')
        ->and($state->get('feat/manual')['session_id'])->toBeNull();
});

test('markRun records the agent session id and keeps the task spawned', function () {
    $state = new HiveState($this->tmp);
    $state->putPlan([
        ['branch_name' => 'feat/x', 'title' => 'X', 'description' => 'd', 'priority' => 1, 'type' => 'feature', 'depends_on' => [], 'status' => 'ready'],
    ]);

    $state->markRun('feat/x', 'sess-99');

    $task = (new HiveState($this->tmp))->get('feat/x');
    expect($task['session_id'])->toBe('sess-99')
        ->and($task['runtime'])->toBe('spawned');
});

test('markRunning records the background pid and log path', function () {
    $state = new HiveState($this->tmp);
    $state->putPlan([
        ['branch_name' => 'feat/x', 'title' => 'X', 'description' => 'd', 'priority' => 1, 'type' => 'feature', 'depends_on' => [], 'status' => 'ready'],
    ]);

    $state->markRunning('feat/x', 4242, '/p/.hive/logs/x.log');

    $task = (new HiveState($this->tmp))->get('feat/x');
    expect($task['runtime'])->toBe('running')
        ->and($task['pid'])->toBe(4242)
        ->and($task['log_path'])->toBe('/p/.hive/logs/x.log');
});

test('markRunning can also record the session id', function () {
    $state = new HiveState($this->tmp);
    $state->putPlan([
        ['branch_name' => 'feat/x', 'title' => 'X', 'description' => 'd', 'priority' => 1, 'type' => 'feature', 'depends_on' => [], 'status' => 'ready'],
    ]);

    $state->markRunning('feat/x', 100, '/log', 'sess-9');

    expect((new HiveState($this->tmp))->get('feat/x')['session_id'])->toBe('sess-9');
});

test('markRunning without a session id preserves an existing one', function () {
    $state = new HiveState($this->tmp);
    $state->putPlan([
        ['branch_name' => 'feat/x', 'title' => 'X', 'description' => 'd', 'priority' => 1, 'type' => 'feature', 'depends_on' => [], 'status' => 'ready'],
    ]);

    $state->markRunning('feat/x', 100, '/log', 'sess-keep');
    $state->markRunning('feat/x', 100, '/log'); // no session id this time

    expect((new HiveState($this->tmp))->get('feat/x')['session_id'])->toBe('sess-keep');
});

test('markFailed records the error against the task', function () {
    $state = new HiveState($this->tmp);
    $state->putPlan([
        ['branch_name' => 'fix/a', 'title' => 'A', 'description' => 'd', 'priority' => 1, 'type' => 'bug', 'depends_on' => [], 'status' => 'ready'],
    ]);

    $state->markFailed('fix/a', 'boom');

    $task = $state->get('fix/a');
    expect($task['runtime'])->toBe('failed')
        ->and($task['error'])->toBe('boom');
});

test('re-planning refreshes plan fields but preserves runtime state', function () {
    $state = new HiveState($this->tmp);
    $state->putPlan([
        ['branch_name' => 'fix/a', 'title' => 'A', 'description' => 'd', 'priority' => 1, 'type' => 'bug', 'depends_on' => [], 'status' => 'ready'],
    ]);
    $state->markSpawned('fix/a', '/wt/a', 'sess-1');

    $state->putPlan([
        ['branch_name' => 'fix/a', 'title' => 'A v2', 'description' => 'd2', 'priority' => 2, 'type' => 'bug', 'depends_on' => [], 'status' => 'ready'],
    ]);

    $task = $state->get('fix/a');
    expect($task['title'])->toBe('A v2')
        ->and($task['runtime'])->toBe('spawned')
        ->and($task['worktree_path'])->toBe('/wt/a')
        ->and($task['session_id'])->toBe('sess-1');
});

test('exists reflects whether the state file is present', function () {
    expect((new HiveState($this->tmp))->exists())->toBeFalse();

    (new HiveState($this->tmp))->putPlan([]);

    expect((new HiveState($this->tmp))->exists())->toBeTrue();
});
