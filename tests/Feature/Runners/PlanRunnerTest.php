<?php

use App\Contracts\DagProvider;
use App\Runners\PlanRunner;
use App\Services\ContextBuilder;
use App\Services\WorktreeManager;
use App\Support\HiveState;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir() . '/hive-runner-' . uniqid();
    mkdir($this->tmp);
    exec("git init {$this->tmp} -q");
    exec("git -C {$this->tmp} commit --allow-empty -m init -q");
    $this->manager = new WorktreeManager($this->tmp);
});

afterEach(fn () => exec("rm -rf {$this->tmp}"));

function planRunner(WorktreeManager $manager, ?DagProvider $analyzer = null): PlanRunner
{
    return new PlanRunner(
        $analyzer ?? Mockery::mock(DagProvider::class),
        $manager,
        new ContextBuilder,
    );
}

test('plan delegates to the analyzer and returns the task list', function () {
    $analyzer = Mockery::mock(DagProvider::class);
    $analyzer->shouldReceive('analyze')->once()->with('backlog', 'sk-key')->andReturn([
        'tasks' => [['branch_name' => 'fix/a', 'status' => 'ready']],
    ]);

    $tasks = planRunner($this->manager, $analyzer)->plan('backlog', 'sk-key');

    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]['branch_name'])->toBe('fix/a');
});

test('readyTasks keeps only tasks with ready status', function () {
    $runner = planRunner($this->manager);

    $ready = $runner->readyTasks([
        ['branch_name' => 'fix/a', 'status' => 'ready'],
        ['branch_name' => 'feat/b', 'status' => 'blocked'],
    ]);

    expect($ready)->toHaveCount(1)
        ->and($ready[0]['branch_name'])->toBe('fix/a');
});

test('spawnTask creates the worktree and injects CLAUDE.md', function () {
    $task = ['branch_name' => 'feat/login', 'description' => 'Build login', 'status' => 'ready', 'type' => 'feature'];

    $result = planRunner($this->manager)->spawnTask($task, ['laravel', 'pest']);

    expect($result['error'])->toBeNull()
        ->and(is_dir($result['path']))->toBeTrue()
        ->and(file_exists($result['path'] . '/CLAUDE.md'))->toBeTrue();

    $md = file_get_contents($result['path'] . '/CLAUDE.md');
    expect($md)->toContain('Build login')
        ->and($md)->toContain('feat/login');
});

test('spawnTask honours the type override in the injected context', function () {
    $task = ['branch_name' => 'fix/crash', 'description' => 'fix crash', 'status' => 'ready', 'type' => 'feature'];

    $result = planRunner($this->manager)->spawnTask($task, ['laravel'], 'bug');

    expect(file_get_contents($result['path'] . '/CLAUDE.md'))->toContain('**Type:** bug');
});

test('spawnTask preserves a committed project CLAUDE.md and keeps the Hive context out of git status', function () {
    // The target project ships its own CLAUDE.md (committed), like the portfolio repo.
    file_put_contents($this->tmp . '/CLAUDE.md', "# Portfolio\n\nProject conventions: use Volt.\n");
    exec("git -C {$this->tmp} add CLAUDE.md");
    exec("git -C {$this->tmp} commit -m 'add project CLAUDE.md' -q");

    $task = ['branch_name' => 'fix/keep', 'description' => 'Fix the grid animation', 'status' => 'ready', 'type' => 'bug'];
    $result = planRunner($this->manager)->spawnTask($task, ['laravel', 'pest']);

    $md = file_get_contents($result['path'] . '/CLAUDE.md');
    expect($md)->toContain('Project conventions: use Volt.')   // project conventions preserved
        ->and($md)->toContain('Fix the grid animation');       // Hive task injected

    // The injected modification must not surface as a pending change (skip-worktree).
    $status = [];
    exec("git -C {$result['path']} status --porcelain", $status);
    expect(implode("\n", $status))->not->toContain('CLAUDE.md');
});

test('spawnTask returns an error instead of throwing when the branch already exists', function () {
    $runner = planRunner($this->manager);
    $task = ['branch_name' => 'feat/dup', 'description' => 'x', 'status' => 'ready', 'type' => 'feature'];

    $runner->spawnTask($task, ['laravel']);            // first spawn succeeds
    $result = $runner->spawnTask($task, ['laravel']);  // second must fail gracefully

    expect($result['error'])->not->toBeNull()
        ->and($result['path'])->toBeNull();
});

test('execute spawns only the ready tasks', function () {
    $results = planRunner($this->manager)->execute([
        ['branch_name' => 'fix/ready', 'description' => 'a', 'status' => 'ready', 'type' => 'bug'],
        ['branch_name' => 'feat/blocked', 'description' => 'b', 'status' => 'blocked', 'type' => 'feature'],
    ], ['laravel']);

    expect($results)->toHaveCount(1)
        ->and($results[0]['branch'])->toBe('fix/ready')
        ->and($results[0]['error'])->toBeNull();
});

test('plan persists the DAG to the state store when one is attached', function () {
    $analyzer = Mockery::mock(DagProvider::class);
    $analyzer->shouldReceive('analyze')->andReturn([
        'tasks' => [['branch_name' => 'fix/a', 'title' => 'A', 'description' => 'd', 'priority' => 1, 'type' => 'bug', 'depends_on' => [], 'status' => 'ready']],
    ]);
    $runner = new PlanRunner($analyzer, $this->manager, new ContextBuilder, new HiveState($this->tmp));

    $runner->plan('backlog');

    expect((new HiveState($this->tmp))->get('fix/a'))->not->toBeNull();
});

test('spawnTask records the spawned worktree in the state store', function () {
    $runner = new PlanRunner(Mockery::mock(DagProvider::class), $this->manager, new ContextBuilder, new HiveState($this->tmp));
    $task = ['branch_name' => 'feat/login', 'description' => 'x', 'status' => 'ready', 'type' => 'feature'];

    $runner->spawnTask($task, ['laravel']);

    $persisted = (new HiveState($this->tmp))->get('feat/login');
    expect($persisted['runtime'])->toBe('spawned')
        ->and($persisted['worktree_path'])->not->toBeNull();
});
