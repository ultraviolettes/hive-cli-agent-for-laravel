<?php

use App\Support\BranchName;

test('accepts conventional branch names', function (string $branch) {
    expect(BranchName::assert($branch))->toBe($branch)
        ->and(BranchName::isValid($branch))->toBeTrue();
})->with([
    'fix/login-bug',
    'feat/dark-mode',
    'chore/bump-deps-2.1',
    'refactor/extract_service',
    'main',
    'release/v1.0.0',
]);

test('rejects dangerous or malformed branch names', function (string $branch) {
    expect(fn () => BranchName::assert($branch))->toThrow(\InvalidArgumentException::class)
        ->and(BranchName::isValid($branch))->toBeFalse();
})->with([
    'leading dash (git option injection)' => '--force',
    'single dash' => '-b',
    'empty' => '',
    'path traversal' => 'fix/../../etc',
    'double slash' => 'fix//x',
    'leading slash' => '/fix/x',
    'leading dot' => '.hidden',
    'trailing slash' => 'fix/x/',
    'trailing dot' => 'fix/x.',
    'lock suffix' => 'fix/x.lock',
    'lock suffix on an inner segment' => 'fix/foo.lock/bar',
    'segment starting with a dot' => 'fix/.hidden',
    'segment ending with a dot' => 'fix/x./y',
    'space' => 'fix/log in',
    'shell metacharacters' => 'fix/$(rm -rf)',
    'control char' => "fix/x\ny",
    'too long' => 'fix/' . str_repeat('a', 300),
]);
