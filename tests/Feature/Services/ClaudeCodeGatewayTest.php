<?php

use App\Exceptions\ClaudeCodeTimeoutException;
use App\Process\ProcessResult;
use App\Services\ClaudeCodeGateway;
use Tests\Support\FakeProcessRunner;

/**
 * A gateway whose underlying `claude -p` call is replaced by a canned string,
 * so we can exercise the real parsing logic in promptJson() without a binary.
 */
function fakeGateway(string $cannedOutput): ClaudeCodeGateway
{
    // Claude Code returns {"result": "..."}; queue that so promptJson() runs
    // its real fence-stripping + decoding on the inner payload.
    $runner = (new FakeProcessRunner)->queue(
        new ProcessResult(true, json_encode(['result' => $cannedOutput]), '', 0)
    );

    return new ClaudeCodeGateway('claude', $runner);
}

test('throws when claude cli is not available', function () {
    $gateway = new ClaudeCodeGateway(binary: '/nonexistent/claude');
    expect($gateway->isAvailable())->toBeFalse();
});

test('isAvailable reflects the injected runner result', function () {
    $available = new ClaudeCodeGateway('claude', (new FakeProcessRunner)->queue(new ProcessResult(true, '/usr/bin/claude', '', 0)));
    $missing = new ClaudeCodeGateway('claude', (new FakeProcessRunner)->queue(new ProcessResult(false, '', '', 1)));

    expect($available->isAvailable())->toBeTrue()
        ->and($missing->isAvailable())->toBeFalse();
});

test('prompt returns the result field from the runner output (no real claude)', function () {
    $runner = (new FakeProcessRunner)->queue(new ProcessResult(true, json_encode(['result' => 'hello']), '', 0));

    expect((new ClaudeCodeGateway('claude', $runner))->prompt('hi'))->toBe('hello');
});

test('prompt throws when the runner reports a failure', function () {
    $runner = (new FakeProcessRunner)->queue(new ProcessResult(false, '', 'claude crashed', 1));

    expect(fn () => (new ClaudeCodeGateway('claude', $runner))->prompt('hi'))
        ->toThrow(\RuntimeException::class, 'claude crashed');
});

test('promptJson strips markdown fences and decodes JSON', function () {
    $gateway = fakeGateway("```json\n{\"tasks\": [{\"title\": \"Test\"}]}\n```");

    $decoded = $gateway->promptJson('analyze this');

    expect($decoded)->toBeArray()
        ->and($decoded['tasks'])->toHaveCount(1)
        ->and($decoded['tasks'][0]['title'])->toBe('Test');
});

test('promptJson decodes plain JSON without fences', function () {
    $gateway = fakeGateway('{"tasks": []}');

    expect($gateway->promptJson('x'))->toBe(['tasks' => []]);
});

test('promptJson throws on invalid JSON', function () {
    $gateway = fakeGateway('not valid json at all');

    expect(fn () => $gateway->promptJson('x'))->toThrow(\RuntimeException::class);
});

test('promptJson throws when the response decodes to a scalar', function () {
    $gateway = fakeGateway('42');

    expect(fn () => $gateway->promptJson('x'))->toThrow(\RuntimeException::class);
});

test('prompt throws a dedicated timeout exception when the runner times out', function () {
    $runner = (new FakeProcessRunner)->queue(new ProcessResult(false, '', '', 1, timedOut: true));

    expect(fn () => (new ClaudeCodeGateway('claude', $runner))->prompt('hi'))
        ->toThrow(ClaudeCodeTimeoutException::class);
});

test('the timeout exception carries the effective timeout', function () {
    $runner = (new FakeProcessRunner)->queue(new ProcessResult(false, '', '', 1, timedOut: true));

    try {
        (new ClaudeCodeGateway('claude', $runner))->promptJson('x', 45);
        $this->fail('Expected ClaudeCodeTimeoutException');
    } catch (ClaudeCodeTimeoutException $e) {
        expect($e->timeoutSeconds)->toBe(45)
            ->and($e->getMessage())->toContain('45');
    }
});

test('prompt forwards the custom timeout to the runner', function () {
    $runner = (new FakeProcessRunner)->queue(new ProcessResult(true, json_encode(['result' => 'ok']), '', 0));

    (new ClaudeCodeGateway('claude', $runner))->prompt('hi', 45);

    expect($runner->calls[0]['timeout'])->toBe(45);
});

test('prompt defaults to 300 seconds when no timeout is given', function () {
    $runner = (new FakeProcessRunner)->queue(new ProcessResult(true, json_encode(['result' => 'ok']), '', 0));

    (new ClaudeCodeGateway('claude', $runner))->prompt('hi');

    expect($runner->calls[0]['timeout'])->toBe(300);
});
