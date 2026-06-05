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
        ->and($reloaded->get('feat/b')['depends_on'])->toBe([0]);
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
