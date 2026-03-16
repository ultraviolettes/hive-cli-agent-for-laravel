<?php

test('init creates .hive.json in current directory', function () {
    $tmp = sys_get_temp_dir() . '/hive-init-' . uniqid();
    mkdir($tmp);
    touch($tmp . '/artisan');

    $this->artisan('init', ['--path' => $tmp])
        ->assertExitCode(0);

    expect(file_exists($tmp . '/.hive.json'))->toBeTrue();

    exec("rm -rf $tmp");
});

test('init fails when not a laravel project', function () {
    $tmp = sys_get_temp_dir() . '/hive-empty-' . uniqid();
    mkdir($tmp);

    $this->artisan('init', ['--path' => $tmp])
        ->assertExitCode(1);

    exec("rm -rf $tmp");
});
