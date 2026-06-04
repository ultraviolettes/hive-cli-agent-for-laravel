<?php

use App\Services\WorktreeManager;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir() . '/hive-wt-' . uniqid();
    mkdir($this->tmp);
    exec("git init {$this->tmp} -q");
    exec("git -C {$this->tmp} commit --allow-empty -m 'init' -q");
    $this->manager = new WorktreeManager($this->tmp);
});

afterEach(fn () => exec("rm -rf {$this->tmp}"));

test('list returns empty array when no worktrees', function () {
    expect($this->manager->list())->toBeArray()->toBeEmpty();
});

test('worktree path uses .hive/worktrees prefix', function () {
    $path = $this->manager->worktreePath('feat/my-feature');
    expect($path)->toContain('.hive/worktrees')
        ->and($path)->toContain('feat-my-feature');
});

test('spawn creates worktree directory', function () {
    $path = $this->manager->spawn('feat/test-branch');
    expect(is_dir($path))->toBeTrue();
});

test('harvest removes worktree', function () {
    $path = $this->manager->spawn('feat/to-harvest');
    $this->manager->harvest('feat/to-harvest');
    expect(is_dir($path))->toBeFalse();
});

test('harvest throws when the worktree does not exist', function () {
    expect(fn () => $this->manager->harvest('feat/never-spawned'))
        ->toThrow(\RuntimeException::class);
});

test('list throws when the directory is not a git repository', function () {
    $nonGit = sys_get_temp_dir() . '/hive-nongit-' . uniqid();
    mkdir($nonGit);
    $manager = new WorktreeManager($nonGit);

    expect(fn () => $manager->list())->toThrow(\RuntimeException::class);

    exec("rm -rf {$nonGit}");
});
