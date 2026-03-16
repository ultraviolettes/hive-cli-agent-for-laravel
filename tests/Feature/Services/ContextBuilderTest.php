<?php

use App\Services\ContextBuilder;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir() . '/hive-ctx-' . uniqid();
    mkdir($this->tmp);
    $this->builder = new ContextBuilder;
});

afterEach(fn () => exec("rm -rf {$this->tmp}"));

test('writes CLAUDE.md in worktree', function () {
    $this->builder->writeContext($this->tmp, 'fix/cve', 'Fix SQL injection CVE in composer deps');
    expect(file_exists($this->tmp . '/CLAUDE.md'))->toBeTrue();
});

test('CLAUDE.md contains task context', function () {
    $this->builder->writeContext($this->tmp, 'fix/cve', 'Fix SQL injection CVE', [
        'stack' => ['laravel', 'pest', 'filament'],
        'type' => 'security',
    ]);
    $content = file_get_contents($this->tmp . '/CLAUDE.md');
    expect($content)->toContain('fix/cve')
        ->and($content)->toContain('Fix SQL injection CVE')
        ->and($content)->toContain('security');
});

test('CLAUDE.md contains TDD instructions for pest projects', function () {
    $this->builder->writeContext($this->tmp, 'feat/dashboard', 'Build admin dashboard', [
        'stack' => ['laravel', 'pest', 'filament'],
    ]);
    $content = file_get_contents($this->tmp . '/CLAUDE.md');
    expect($content)->toContain('Pest')
        ->and($content)->toContain('TDD');
});
