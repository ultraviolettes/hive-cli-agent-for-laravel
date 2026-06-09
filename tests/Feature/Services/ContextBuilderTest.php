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

test('preserves an existing project CLAUDE.md instead of overwriting it', function () {
    file_put_contents($this->tmp . '/CLAUDE.md', "# Portfolio\n\nProject conventions: use Volt.\n");

    $this->builder->writeContext($this->tmp, 'fix/bug', 'Fix the grid animation');

    $content = file_get_contents($this->tmp . '/CLAUDE.md');
    expect($content)->toContain('Project conventions: use Volt.')   // project content kept
        ->and($content)->toContain('Fix the grid animation')        // task appended
        ->and($content)->toContain('<!-- HIVE:CONTEXT START -->');  // wrapped in markers
});

test('wraps the injected context in Hive markers', function () {
    $this->builder->writeContext($this->tmp, 'fix/x', 'Do a thing');

    $content = file_get_contents($this->tmp . '/CLAUDE.md');
    expect($content)->toContain('<!-- HIVE:CONTEXT START -->')
        ->and($content)->toContain('<!-- HIVE:CONTEXT END -->');
});

test('is idempotent — re-injecting replaces the Hive block instead of stacking it', function () {
    file_put_contents($this->tmp . '/CLAUDE.md', "# Portfolio\n\nKeep me.\n");

    $this->builder->writeContext($this->tmp, 'fix/x', 'First task');
    $this->builder->writeContext($this->tmp, 'fix/x', 'Second task');

    $content = file_get_contents($this->tmp . '/CLAUDE.md');
    expect(substr_count($content, '<!-- HIVE:CONTEXT START -->'))->toBe(1)
        ->and($content)->toContain('Keep me.')        // project content survives re-injection
        ->and($content)->toContain('Second task')     // latest task present
        ->and($content)->not->toContain('First task'); // stale task removed
});
