<?php

use App\Services\WorktreeManager;
use App\Support\HiveState;

test('harvest marks the task merged and flags newly unblocked dependents', function () {
    $tmp = sys_get_temp_dir() . '/hive-harvest-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    exec("git -C {$tmp} config user.email t@t.t");
    exec("git -C {$tmp} config user.name t");
    exec("git -C {$tmp} commit --allow-empty -m init -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    // A real worktree to harvest, plus a dependent blocked task in the plan.
    (new WorktreeManager($tmp))->spawn('fix/a');
    $state = new HiveState($tmp);
    $state->putPlan([
        ['branch_name' => 'fix/a', 'title' => 'A', 'description' => 'd', 'priority' => 100, 'type' => 'security', 'depends_on' => [], 'status' => 'ready'],
        ['branch_name' => 'feat/b', 'title' => 'B', 'description' => 'd', 'priority' => 30, 'type' => 'feature', 'depends_on' => [0], 'status' => 'blocked'],
    ]);
    $state->markSpawned('fix/a', $tmp . '/.hive/worktrees/fix-a');

    chdir($tmp);

    $this->artisan('harvest', ['branch' => 'fix/a', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('now unblocked');

    expect((new HiveState($tmp))->get('fix/a')['runtime'])->toBe('merged');

    exec("rm -rf {$tmp}");
});

test('harvest refuses a branch name that looks like a git option', function () {
    $tmp = sys_get_temp_dir() . '/hive-harvest-evil-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    exec("git -C {$tmp} commit --allow-empty -m init -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    chdir($tmp);

    $this->artisan('harvest', ['branch' => '--force', '--force' => true])
        ->expectsOutputToContain('Invalid branch name')
        ->assertExitCode(1);

    exec("rm -rf {$tmp}");
});
