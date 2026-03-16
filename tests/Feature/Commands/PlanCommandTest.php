<?php

use App\Ai\Agents\DagAnalyzerAgent;

test('plan command shows execution plan with --dry-run', function () {
    DagAnalyzerAgent::fake([
        [
            'tasks' => [
                ['title' => 'Fix CVE', 'description' => 'Update deps', 'priority' => 100,
                    'depends_on' => [], 'branch_name' => 'fix/cve', 'status' => 'ready', 'type' => 'security'],
                ['title' => 'Update deps', 'description' => 'Minor bump', 'priority' => 50,
                    'depends_on' => [0], 'branch_name' => 'chore/deps', 'status' => 'blocked', 'type' => 'dependency'],
            ],
        ],
    ]);

    $tmp = sys_get_temp_dir() . '/hive-plan-' . uniqid();
    mkdir($tmp);
    file_put_contents($tmp . '/.hive.json', json_encode(['project' => 'test', 'stack' => ['laravel']]));

    chdir($tmp);

    $this->artisan('plan', ['--text' => 'Fix CVE then update deps', '--dry-run' => true])
        ->assertExitCode(0);

    exec("rm -rf $tmp");
});

test('plan fails without hive init', function () {
    $tmp = sys_get_temp_dir() . '/hive-plan-noinit-' . uniqid();
    mkdir($tmp);
    chdir($tmp);

    $this->artisan('plan', ['--text' => 'something'])
        ->assertExitCode(1);

    exec("rm -rf $tmp");
});
