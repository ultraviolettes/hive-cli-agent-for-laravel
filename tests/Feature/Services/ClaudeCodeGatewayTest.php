<?php

use App\Services\ClaudeCodeGateway;

/**
 * A gateway whose underlying `claude -p` call is replaced by a canned string,
 * so we can exercise the real parsing logic in promptJson() without a binary.
 */
function fakeGateway(string $cannedOutput): ClaudeCodeGateway
{
    return new class($cannedOutput) extends ClaudeCodeGateway
    {
        public function __construct(private readonly string $cannedOutput)
        {
            parent::__construct();
        }

        public function prompt(string $prompt): string
        {
            return $this->cannedOutput;
        }
    };
}

test('throws when claude cli is not available', function () {
    $gateway = new ClaudeCodeGateway(binary: '/nonexistent/claude');
    expect($gateway->isAvailable())->toBeFalse();
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
