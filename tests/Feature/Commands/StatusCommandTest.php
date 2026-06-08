<?php

use App\Support\HiveState;

test('status surfaces planned-but-not-spawned tasks (including blocked) from the state store', function () {
    $tmp = sys_get_temp_dir() . '/hive-status-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    (new HiveState($tmp))->putPlan([
        ['branch_name' => 'fix/cve', 'title' => 'Fix', 'description' => 'd', 'priority' => 100, 'type' => 'security', 'depends_on' => [], 'status' => 'ready'],
        ['branch_name' => 'feat/dep', 'title' => 'Feat', 'description' => 'd', 'priority' => 30, 'type' => 'feature', 'depends_on' => [0], 'status' => 'blocked'],
    ]);

    chdir($tmp);

    // feat/dep is blocked and was never spawned, so it has no worktree; it can
    // only show up because status now reads the persisted plan. fix/cve too.
    $this->artisan('status')
        ->assertExitCode(0)
        ->expectsOutputToContain('feat/dep')
        ->expectsOutputToContain('fix/cve');

    exec("rm -rf {$tmp}");
});

test('status reports nothing when there are no worktrees and no plan', function () {
    $tmp = sys_get_temp_dir() . '/hive-status-empty-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    chdir($tmp);

    $this->artisan('status')
        ->assertExitCode(0)
        ->expectsOutputToContain('No active worktrees or planned tasks');

    exec("rm -rf {$tmp}");
});
