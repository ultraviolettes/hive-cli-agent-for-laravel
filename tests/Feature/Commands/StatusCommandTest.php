<?php

use App\Process\BackgroundRunner;
use App\Services\BeeRoleInferer;
use App\Services\WorktreeManager;
use App\Support\HiveState;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeBackgroundRunner;

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

test('status shows the role and bee_id of planned tasks', function () {
    $tmp = sys_get_temp_dir() . '/hive-status-role-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    (new HiveState($tmp))->putPlan([
        ['branch_name' => 'qa/checkout', 'title' => 'Add Pest tests', 'description' => '', 'priority' => 1, 'type' => 'feature', 'depends_on' => [], 'status' => 'ready'],
    ], new BeeRoleInferer);

    chdir($tmp);

    // Capture the full buffered output: expectsOutputToContain only reliably
    // matches the first table column, but role/bee_id live in later columns.
    Artisan::call('status');
    $output = Artisan::output();

    expect($output)->toContain('qa/checkout')
        ->and($output)->toContain('qa')
        ->and($output)->toContain('qa-1');

    exec("rm -rf {$tmp}");
});

test('status surfaces the pid and session id of a running bee', function () {
    $tmp = sys_get_temp_dir() . '/hive-status-pid-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    exec("git -C {$tmp} config user.email t@t.t");
    exec("git -C {$tmp} config user.name t");
    exec("git -C {$tmp} commit --allow-empty -m init -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    (new WorktreeManager($tmp))->spawn('feat/x');
    (new HiveState($tmp))->markRunning('feat/x', 12345, $tmp . '/.hive/logs/feat-x.log', 'abcdef0123456789');

    // Liveness comes from the stored pid via the BackgroundRunner (faked alive).
    app()->instance(BackgroundRunner::class, new FakeBackgroundRunner);

    chdir($tmp);
    Artisan::call('status');
    $output = Artisan::output();

    expect($output)->toContain('12345')      // pid
        ->and($output)->toContain('abcdef01') // truncated session id
        ->and($output)->toContain('running'); // pid-based liveness

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
