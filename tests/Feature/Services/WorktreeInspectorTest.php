<?php

use App\Services\WorktreeInspector;
use App\Support\BeeStatus;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir() . '/hive-wi-' . uniqid();
    mkdir($this->tmp);
    exec("git init {$this->tmp} -q");
    exec("git -C {$this->tmp} config user.email test@example.com");
    exec("git -C {$this->tmp} config user.name 'Test'");
    exec("git -C {$this->tmp} commit --allow-empty -m init -q");
    $this->inspector = new WorktreeInspector;
});

afterEach(fn () => exec("rm -rf {$this->tmp}"));

test('change summary counts staged and untracked files accurately', function () {
    // One staged file (two lines of content — must NOT inflate the count).
    file_put_contents($this->tmp . '/a.txt', "hello\nworld\n");
    exec("git -C {$this->tmp} add a.txt");

    // One untracked file.
    file_put_contents($this->tmp . '/b.txt', "new file\n");

    $result = $this->inspector->inspect(['path' => $this->tmp, 'branch' => 'refs/heads/main']);

    expect($result['changes'])->toContain('1 staged')
        ->and($result['changes'])->toContain('1 new')
        ->and($result['status'])->toBe(BeeStatus::ChangesPending);
});

test('change summary is a dash when there is nothing to report', function () {
    $result = $this->inspector->inspect(['path' => $this->tmp, 'branch' => 'refs/heads/main']);

    expect($result['changes'])->toBe('—')
        ->and($result['status'])->toBe(BeeStatus::Idle);
});

test('shortBranch strips the refs/heads prefix', function () {
    $result = $this->inspector->inspect(['path' => $this->tmp, 'branch' => 'refs/heads/feat/login']);

    expect($result['branch'])->toBe('feat/login');
});
