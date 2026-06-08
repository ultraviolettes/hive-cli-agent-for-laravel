<?php

use App\Support\HiveState;

test('spawn records a bee with an inferred role and a bee_id', function () {
    $tmp = sys_get_temp_dir() . '/hive-spawn-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    exec("git -C {$tmp} config user.email t@t.t");
    exec("git -C {$tmp} config user.name t");
    exec("git -C {$tmp} commit --allow-empty -m init -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    chdir($tmp);

    $this->artisan('spawn', ['branch' => 'fix/auth-token'])->assertExitCode(0);

    $task = (new HiveState($tmp))->get('fix/auth-token');
    expect($task['role'])->toBe('security')        // inferred from the branch name
        ->and($task['bee_id'])->toBe('security-1')
        ->and($task['runtime'])->toBe('spawned')
        ->and($task['worktree_path'])->toContain('fix-auth-token');

    exec("rm -rf {$tmp}");
});
