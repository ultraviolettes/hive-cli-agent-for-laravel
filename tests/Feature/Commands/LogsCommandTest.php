<?php

use App\Support\HiveState;
use Illuminate\Support\Facades\Artisan;

function logsFixture(string $logContent): string
{
    $tmp = sys_get_temp_dir() . '/hive-logs-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    $log = $tmp . '/.hive/logs/feat-x.log';
    mkdir(dirname($log), 0755, true);
    file_put_contents($log, $logContent);

    (new HiveState($tmp))->markRunning('feat/x', 4242, $log, 'sess-abcdef12');

    return $tmp;
}

test('logs prints the tail and the bee metadata', function () {
    $tmp = logsFixture("line one\nline two\nline three\n");

    chdir($tmp);
    Artisan::call('logs', ['branch' => 'feat/x']);
    $output = Artisan::output();

    expect($output)->toContain('line three')
        ->and($output)->toContain('feat/x')
        ->and($output)->toContain('sess-abcdef12')
        ->and($output)->toContain('4242');

    exec("rm -rf {$tmp}");
});

test('logs --lines limits the output to the trailing lines', function () {
    $tmp = logsFixture("alpha\nbravo\ncharlie\ndelta\necho\n");

    chdir($tmp);
    Artisan::call('logs', ['branch' => 'feat/x', '--lines' => 2]);
    $output = Artisan::output();

    expect($output)->toContain('delta')
        ->and($output)->toContain('echo')
        ->and($output)->not->toContain('bravo');

    exec("rm -rf {$tmp}");
});

test('logs errors when there is no log for the branch', function () {
    $tmp = sys_get_temp_dir() . '/hive-logs-none-' . uniqid();
    mkdir($tmp);
    exec("git init {$tmp} -q");
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    chdir($tmp);

    $this->artisan('logs', ['branch' => 'nope'])
        ->assertExitCode(1)
        ->expectsOutputToContain('No log for nope');

    exec("rm -rf {$tmp}");
});
