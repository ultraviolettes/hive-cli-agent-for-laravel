<?php

use App\Support\HiveState;

function advanceFixture(): string
{
    $tmp = sys_get_temp_dir() . '/hive-advance-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    exec("git -C {$tmp} config user.email t@t.t");
    exec("git -C {$tmp} config user.name t");
    exec("git -C {$tmp} commit --allow-empty -m init -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    $state = new HiveState($tmp);
    $state->putPlan([
        ['branch_name' => 'fix/a', 'title' => 'A', 'description' => 'Fix A', 'priority' => 100, 'type' => 'security', 'depends_on' => [], 'status' => 'ready'],
        ['branch_name' => 'feat/b', 'title' => 'B', 'description' => 'Build B', 'priority' => 30, 'type' => 'feature', 'depends_on' => [0], 'status' => 'blocked'],
    ]);

    return $tmp;
}

test('advance spawns a blocked task once its dependency is merged', function () {
    $tmp = advanceFixture();
    (new HiveState($tmp))->markMerged('fix/a');

    chdir($tmp);

    $this->artisan('advance')->assertExitCode(0);

    $task = (new HiveState($tmp))->get('feat/b');
    expect($task['runtime'])->toBe('spawned')
        ->and($task['status'])->toBe('ready')
        ->and(is_dir($tmp . '/.hive/worktrees/feat-b'))->toBeTrue();

    exec("rm -rf {$tmp}");
});

test('advance does nothing while dependencies are unmet', function () {
    $tmp = advanceFixture();

    chdir($tmp);

    $this->artisan('advance')
        ->assertExitCode(0)
        ->expectsOutputToContain('Nothing to advance');

    expect((new HiveState($tmp))->get('feat/b')['runtime'])->toBe('planned');

    exec("rm -rf {$tmp}");
});

test('advance --dry-run lists but does not spawn', function () {
    $tmp = advanceFixture();
    (new HiveState($tmp))->markMerged('fix/a');

    chdir($tmp);

    $this->artisan('advance', ['--dry-run' => true])->assertExitCode(0);

    expect((new HiveState($tmp))->get('feat/b')['runtime'])->toBe('planned')
        ->and(is_dir($tmp . '/.hive/worktrees/feat-b'))->toBeFalse();

    exec("rm -rf {$tmp}");
});
