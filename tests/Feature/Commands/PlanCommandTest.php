<?php

use App\Contracts\DagProvider;
use App\Process\BackgroundRunner;
use App\Services\WorktreeManager;
use App\Support\HiveState;
use Tests\Support\FakeBackgroundRunner;

test('plan command shows execution plan with --dry-run', function () {
    $fakeDag = Mockery::mock(DagProvider::class);
    $fakeDag->shouldReceive('analyze')->once()->andReturn([
        'tasks' => [
            ['title' => 'Fix CVE', 'description' => 'Update deps', 'priority' => 100,
                'depends_on' => [], 'branch_name' => 'fix/cve', 'status' => 'ready', 'type' => 'security'],
            ['title' => 'Update deps', 'description' => 'Minor bump', 'priority' => 50,
                'depends_on' => [0], 'branch_name' => 'chore/deps', 'status' => 'blocked', 'type' => 'dependency'],
        ],
    ]);
    app()->instance(DagProvider::class, $fakeDag);

    $tmp = sys_get_temp_dir() . '/hive-plan-' . uniqid();
    mkdir($tmp);
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    chdir($tmp);

    $this->artisan('plan', ['--text' => 'Fix CVE then update deps', '--dry-run' => true])
        ->assertExitCode(0);

    // The command persisted the DAG to the state store, even in --dry-run.
    expect(file_exists($tmp . '/.hive/state.json'))->toBeTrue();
    $state = new HiveState($tmp);
    expect($state->get('fix/cve'))->not->toBeNull()
        ->and($state->get('chore/deps')['status'])->toBe('blocked');

    exec("rm -rf $tmp");
});

test('plan --run spawns and launches a background agent', function () {
    $fakeDag = Mockery::mock(DagProvider::class);
    $fakeDag->shouldReceive('analyze')->andReturn([
        'tasks' => [
            ['title' => 'Fix', 'description' => 'do it', 'priority' => 100, 'depends_on' => [], 'branch_name' => 'fix/a', 'status' => 'ready', 'type' => 'security'],
        ],
    ]);
    app()->instance(DagProvider::class, $fakeDag);

    $bg = new FakeBackgroundRunner;
    app()->instance(BackgroundRunner::class, $bg);

    $tmp = sys_get_temp_dir() . '/hive-planrun-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    exec("git -C {$tmp} config user.email t@t.t");
    exec("git -C {$tmp} config user.name t");
    exec("git -C {$tmp} commit --allow-empty -m init -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    chdir($tmp);

    $this->artisan('plan', ['--text' => 'x', '--run' => true, '--yes' => true])->assertExitCode(0);

    $task = (new HiveState($tmp))->get('fix/a');
    expect($task['runtime'])->toBe('running')
        ->and($task['session_id'])->not->toBeNull()
        ->and($bg->started)->toHaveCount(1)
        ->and(is_dir($tmp . '/.hive/worktrees/fix-a'))->toBeTrue();

    exec("rm -rf {$tmp}");
});

test('plan --run does not launch an agent when the spawn fails', function () {
    $fakeDag = Mockery::mock(DagProvider::class);
    $fakeDag->shouldReceive('analyze')->andReturn([
        'tasks' => [
            ['title' => 'Dup', 'description' => 'd', 'priority' => 1, 'depends_on' => [], 'branch_name' => 'fix/dup', 'status' => 'ready', 'type' => 'bug'],
        ],
    ]);
    app()->instance(DagProvider::class, $fakeDag);

    $bg = new FakeBackgroundRunner;
    app()->instance(BackgroundRunner::class, $bg);

    $tmp = sys_get_temp_dir() . '/hive-planrunfail-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    exec("git -C {$tmp} config user.email t@t.t");
    exec("git -C {$tmp} config user.name t");
    exec("git -C {$tmp} commit --allow-empty -m init -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    // Pre-create the worktree so the plan's spawn of the same branch fails.
    (new WorktreeManager($tmp))->spawn('fix/dup');

    chdir($tmp);

    $this->artisan('plan', ['--text' => 'x', '--run' => true, '--yes' => true])->assertExitCode(0);

    expect($bg->started)->toBeEmpty()
        ->and((new HiveState($tmp))->get('fix/dup')['runtime'])->toBe('failed');

    exec("rm -rf {$tmp}");
});

test('plan fails without hive init', function () {
    $tmp = sys_get_temp_dir() . '/hive-plan-noinit-' . uniqid();
    mkdir($tmp);
    chdir($tmp);

    $this->artisan('plan', ['--text' => 'something'])
        ->assertExitCode(1);

    exec("rm -rf $tmp");
});
