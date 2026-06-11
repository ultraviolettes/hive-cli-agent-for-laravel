<?php

use App\Process\SymfonyProcessRunner;

test('run reports a timeout as a failed result instead of throwing', function () {
    $result = (new SymfonyProcessRunner)->run(['sleep', '2'], null, 1);

    expect($result->successful)->toBeFalse()
        ->and($result->timedOut)->toBeTrue();
});

test('run completes normally within the timeout', function () {
    $result = (new SymfonyProcessRunner)->run(['true'], null, 5);

    expect($result->successful)->toBeTrue()
        ->and($result->timedOut)->toBeFalse();
});

test('run reports a plain failure without flagging a timeout', function () {
    $result = (new SymfonyProcessRunner)->run(['false'], null, 5);

    expect($result->successful)->toBeFalse()
        ->and($result->timedOut)->toBeFalse();
});
