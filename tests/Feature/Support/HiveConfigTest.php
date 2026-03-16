<?php

use App\Support\HiveConfig;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/hive-test-' . uniqid();
    mkdir($this->tmpDir);
});

afterEach(function () {
    @unlink($this->tmpDir . '/.hive.json');
    @rmdir($this->tmpDir);
});

test('creates config file with detected stack', function () {
    $config = new HiveConfig($this->tmpDir);
    $config->init('my-project', ['laravel', 'pest', 'vite']);

    expect(file_exists($this->tmpDir . '/.hive.json'))->toBeTrue();
});

test('reads existing config', function () {
    file_put_contents($this->tmpDir . '/.hive.json', json_encode([
        'project' => 'my-app',
        'stack' => ['laravel', 'filament'],
    ]));

    $config = new HiveConfig($this->tmpDir);
    expect($config->get('project'))->toBe('my-app')
        ->and($config->get('stack'))->toContain('filament');
});

test('returns null when no config exists', function () {
    $config = new HiveConfig($this->tmpDir);
    expect($config->exists())->toBeFalse();
});
