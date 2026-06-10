<?php

use App\Contracts\DagProvider;
use App\Support\HiveState;
use Illuminate\Support\Facades\Http;

function fixTmpProject(bool $withCredentials = true): string
{
    $tmp = sys_get_temp_dir() . '/hive-fix-' . uniqid();
    mkdir($tmp);
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    if ($withCredentials) {
        file_put_contents($tmp . '/.env', "NIGHTWATCH_TOKEN=tok\nNIGHTWATCH_PROJECT_ID=proj\n");
    }

    return $tmp;
}

function fakeNightwatchApi(array $exceptions): void
{
    Http::fake([
        'nightwatch.laravel.com/*' => Http::response(['data' => $exceptions]),
    ]);
}

test('fix --nightwatch --dry-run builds the fix plan from the fetched exceptions', function () {
    fakeNightwatchApi([
        ['id' => 'exc_1', 'message' => 'Undefined variable $user', 'file' => 'app/Http/Controllers/UserController.php', 'line' => 42, 'occurrences' => 150, 'resolved' => false],
    ]);

    $fakeDag = Mockery::mock(DagProvider::class);
    $fakeDag->shouldReceive('analyze')->once()
        ->with(Mockery::on(fn ($raw) => str_contains($raw, 'Undefined variable $user')), Mockery::any(), Mockery::any())
        ->andReturn(['tasks' => [
            ['title' => 'Fix undefined variable', 'description' => 'd', 'priority' => 100,
                'depends_on' => [], 'branch_name' => 'fix/undefined-variable', 'status' => 'ready', 'type' => 'bug'],
        ]]);
    app()->instance(DagProvider::class, $fakeDag);

    $tmp = fixTmpProject();
    chdir($tmp);

    $this->artisan('fix', ['--nightwatch' => true, '--dry-run' => true])->assertExitCode(0);

    // The plan is persisted even in --dry-run, like `hive plan`.
    expect((new HiveState($tmp))->get('fix/undefined-variable'))->not->toBeNull();

    exec("rm -rf {$tmp}");
});

test('fix --nightwatch --yes spawns the fix worktree with the bug type override', function () {
    fakeNightwatchApi([
        ['id' => 'exc_1', 'message' => 'Undefined variable $user', 'file' => 'app/U.php', 'line' => 42, 'occurrences' => 150, 'resolved' => false],
    ]);

    $fakeDag = Mockery::mock(DagProvider::class);
    $fakeDag->shouldReceive('analyze')->andReturn(['tasks' => [
        ['title' => 'Fix undefined variable', 'description' => 'Fix the undefined $user variable', 'priority' => 100,
            'depends_on' => [], 'branch_name' => 'fix/undefined-variable', 'status' => 'ready', 'type' => 'security'],
    ]]);
    app()->instance(DagProvider::class, $fakeDag);

    $tmp = fixTmpProject();
    exec("git init {$tmp} -q");
    exec("git -C {$tmp} config user.email t@t.t");
    exec("git -C {$tmp} config user.name t");
    exec("git -C {$tmp} commit --allow-empty -m init -q");
    chdir($tmp);

    $this->artisan('fix', ['--nightwatch' => true, '--yes' => true])->assertExitCode(0);

    $worktree = $tmp . '/.hive/worktrees/fix-undefined-variable';
    expect(is_dir($worktree))->toBeTrue()
        ->and((new HiveState($tmp))->get('fix/undefined-variable')['runtime'])->toBe('spawned')
        // fix always injects the bug type, whatever the analyzer returned
        ->and(file_get_contents($worktree . '/CLAUDE.md'))->toContain('**Type:** bug');

    exec("rm -rf {$tmp}");
});

test('fix reports a clean app when there are no unresolved exceptions', function () {
    fakeNightwatchApi([]);

    $tmp = fixTmpProject();
    chdir($tmp);

    $this->artisan('fix', ['--nightwatch' => true])
        ->expectsOutputToContain('No unresolved exceptions')
        ->assertExitCode(0);

    exec("rm -rf {$tmp}");
});

test('fix fails without the --nightwatch source flag', function () {
    $tmp = fixTmpProject();
    chdir($tmp);

    $this->artisan('fix')->assertExitCode(1);

    exec("rm -rf {$tmp}");
});

test('fix fails when the nightwatch credentials are missing from the target .env', function () {
    $tmp = fixTmpProject(withCredentials: false);
    chdir($tmp);

    $this->artisan('fix', ['--nightwatch' => true])->assertExitCode(1);

    exec("rm -rf {$tmp}");
});

test('fix fails without hive init', function () {
    $tmp = sys_get_temp_dir() . '/hive-fix-noinit-' . uniqid();
    mkdir($tmp);
    chdir($tmp);

    $this->artisan('fix', ['--nightwatch' => true])->assertExitCode(1);

    exec("rm -rf {$tmp}");
});
