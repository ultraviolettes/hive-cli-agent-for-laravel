<?php

use App\Process\NohupBackgroundRunner;

beforeEach(function () {
    if (! function_exists('posix_kill') || ! is_file('/bin/sh')) {
        $this->markTestSkipped('Requires the posix extension and /bin/sh (Unix only).');
    }

    $this->tmp = sys_get_temp_dir() . '/hive-nohup-' . uniqid();
    mkdir($this->tmp);
});

afterEach(fn () => exec("rm -rf {$this->tmp}"));

test('start detaches a real process: returns immediately with a live pid', function () {
    $runner = new NohupBackgroundRunner;
    $log = $this->tmp . '/agent.log';

    $start = microtime(true);
    $pid = $runner->start(['sleep', '30'], $this->tmp, $log);
    $elapsed = microtime(true) - $start;

    // Non-blocking: must return long before the 30s sleep finishes.
    expect($elapsed)->toBeLessThan(3.0)
        ->and($pid)->toBeGreaterThan(0)
        ->and($runner->isRunning($pid))->toBeTrue();

    // Cleanup the detached process.
    posix_kill($pid, 9);
});

test('isRunning is false for an invalid pid', function () {
    expect((new NohupBackgroundRunner)->isRunning(0))->toBeFalse();
});

test('start rejects an empty command', function () {
    expect(fn () => (new NohupBackgroundRunner)->start([], $this->tmp, $this->tmp . '/x.log'))
        ->toThrow(\InvalidArgumentException::class);
});
