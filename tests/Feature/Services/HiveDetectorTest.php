<?php

use App\Services\HiveDetector;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir() . '/hive-detect-' . uniqid();
    mkdir($this->tmp);
});

afterEach(fn () => exec("rm -rf {$this->tmp}"));

test('detects laravel from artisan file', function () {
    touch($this->tmp . '/artisan');
    expect((new HiveDetector)->detect($this->tmp))->toContain('laravel');
});

test('detects filament from app/Filament directory', function () {
    mkdir($this->tmp . '/app/Filament', 0755, true);
    expect((new HiveDetector)->detect($this->tmp))->toContain('filament');
});

test('detects pest from vendor/bin/pest', function () {
    mkdir($this->tmp . '/vendor/bin', 0755, true);
    touch($this->tmp . '/vendor/bin/pest');
    expect((new HiveDetector)->detect($this->tmp))->toContain('pest');
});

test('detects nightwatch from composer.json', function () {
    file_put_contents($this->tmp . '/composer.json', json_encode([
        'require-dev' => ['laravel/nightwatch' => '^1.0'],
    ]));
    expect((new HiveDetector)->detect($this->tmp))->toContain('nightwatch');
});

test('detects vite from vite.config.js', function () {
    touch($this->tmp . '/vite.config.js');
    expect((new HiveDetector)->detect($this->tmp))->toContain('vite');
});
