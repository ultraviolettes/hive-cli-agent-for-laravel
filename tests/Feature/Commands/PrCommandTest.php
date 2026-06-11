<?php

test('pr fails without hive init', function () {
    $tmp = sys_get_temp_dir() . '/hive-pr-noinit-' . uniqid();
    mkdir($tmp);
    chdir($tmp);

    $this->artisan('pr', ['--yes' => true])->assertExitCode(1);

    exec("rm -rf {$tmp}");
});

test('pr reports cleanly when there are no active worktrees', function () {
    $tmp = sys_get_temp_dir() . '/hive-pr-empty-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    exec("git -C {$tmp} commit --allow-empty -m init -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));
    chdir($tmp);

    $this->artisan('pr', ['--yes' => true])
        ->expectsOutputToContain('No active worktrees')
        ->assertExitCode(0);

    exec("rm -rf {$tmp}");
});
