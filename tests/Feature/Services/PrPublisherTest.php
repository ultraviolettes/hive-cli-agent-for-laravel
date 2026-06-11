<?php

use App\Process\ProcessResult;
use App\Services\PrPublisher;
use App\Support\BeeStatus;
use Tests\Support\FakeProcessRunner;

test('candidates keeps worktrees with pending changes or commits', function () {
    $inspected = [
        ['branch' => 'feat/idle', 'changes' => '—', 'status' => BeeStatus::Idle, 'last_commit' => '—'],
        ['branch' => 'fix/pending', 'changes' => '1 modified', 'status' => BeeStatus::ChangesPending, 'last_commit' => '—'],
        ['branch' => 'feat/done', 'changes' => '—', 'status' => BeeStatus::Done, 'last_commit' => 'feat: x (1 day ago)'],
    ];

    $targets = (new PrPublisher)->candidates($inspected);

    expect(array_column($targets, 'branch'))->toBe(['fix/pending', 'feat/done']);
});

test('publish commits pending changes with a conventional message before pushing', function () {
    $runner = (new FakeProcessRunner)
        ->queue(new ProcessResult(true, '', '', 0))                                    // git add
        ->queue(new ProcessResult(true, '', '', 0))                                    // git commit
        ->queue(new ProcessResult(true, '', '', 0))                                    // git push
        ->queue(new ProcessResult(true, 'owner/repo', '', 0))                          // gh repo view
        ->queue(new ProcessResult(true, "abc123 fix: login bug", '', 0))               // git log main..HEAD
        ->queue(new ProcessResult(true, 'https://github.com/owner/repo/pull/99', '', 0)); // gh pr create

    $target = ['branch' => 'fix/login-bug', 'path' => '/wt', 'changes' => '1 modified', 'status' => BeeStatus::ChangesPending, 'last_commit' => '—'];

    $result = (new PrPublisher($runner))->publish($target, 'main');

    expect($result['committed'])->toBeTrue()
        ->and($result['already_exists'])->toBeFalse()
        ->and($result['url'])->toBe('https://github.com/owner/repo/pull/99')
        ->and($runner->calls[0]['command'])->toBe(['git', 'add', '-A'])
        ->and($runner->calls[1]['command'])->toBe(['git', 'commit', '-m', 'fix: login bug'])
        ->and($runner->calls[2]['command'])->toBe(['git', 'push', '-u', 'origin', '--', 'fix/login-bug']);

    $prCreate = $runner->calls[5]['command'];
    expect($prCreate)->toContain('gh')
        ->and($prCreate)->toContain('owner/repo')
        ->and($prCreate)->toContain('fix/login-bug')
        ->and($prCreate)->toContain('main');

    // The PR body embeds the branch commit log.
    $body = $prCreate[array_search('--body', $prCreate) + 1];
    expect($body)->toContain('abc123 fix: login bug');
});

test('publish derives the commit prefix from the branch name', function (string $branch, string $message) {
    $runner = new FakeProcessRunner;  // default: every call succeeds

    $target = ['branch' => $branch, 'path' => '/wt', 'changes' => '1 new', 'status' => BeeStatus::ChangesPending, 'last_commit' => '—'];
    (new PrPublisher($runner))->publish($target, 'main');

    expect($runner->calls[1]['command'])->toBe(['git', 'commit', '-m', $message]);
})->with([
    'fix' => ['fix/broken-thing', 'fix: broken thing'],
    'chore' => ['chore/bump-deps', 'chore: bump deps'],
    'refactor' => ['refactor/extract-service', 'refactor: extract service'],
    'feat by default' => ['feat/new-thing', 'feat: new thing'],
]);

test('publish skips the commit when there is nothing pending and titles the PR from the last commit', function () {
    $runner = (new FakeProcessRunner)
        ->queue(new ProcessResult(true, '', '', 0))                                    // git push
        ->queue(new ProcessResult(true, 'owner/repo', '', 0))                          // gh repo view
        ->queue(new ProcessResult(true, 'abc feat: do the thing', '', 0))              // git log main..HEAD
        ->queue(new ProcessResult(true, 'https://github.com/owner/repo/pull/7', '', 0)); // gh pr create

    $target = ['branch' => 'feat/thing', 'path' => '/wt', 'changes' => '—', 'status' => BeeStatus::Done, 'last_commit' => 'feat: do the thing (2 days ago)'];

    $result = (new PrPublisher($runner))->publish($target, 'main');

    expect($result['committed'])->toBeFalse()
        ->and($runner->calls[0]['command'][0])->toBe('git')
        ->and($runner->calls[0]['command'][1])->toBe('push');

    // Relative-time suffix stripped from the title.
    $prCreate = $runner->calls[3]['command'];
    $title = $prCreate[array_search('--title', $prCreate) + 1];
    expect($title)->toBe('feat: do the thing');
});

test('publish embeds the CLAUDE.md task context in the PR body', function () {
    $tmp = sys_get_temp_dir() . '/hive-prpub-' . uniqid();
    mkdir($tmp);
    file_put_contents($tmp . '/CLAUDE.md', "# Bee\n\n## Your Task\n\nFix the login flow end to end\n\n## Rules\n\nTDD.\n");

    $runner = new FakeProcessRunner;
    $target = ['branch' => 'fix/login', 'path' => $tmp, 'changes' => '—', 'status' => BeeStatus::Done, 'last_commit' => 'fix: login (1 hour ago)'];

    (new PrPublisher($runner))->publish($target, 'main');

    $prCreate = end($runner->calls)['command'];
    $body = $prCreate[array_search('--body', $prCreate) + 1];
    expect($body)->toContain('Fix the login flow end to end');

    exec("rm -rf {$tmp}");
});

test('publish tolerates an already existing PR', function () {
    $runner = (new FakeProcessRunner)
        ->queue(new ProcessResult(true, '', '', 0))                                    // git push
        ->queue(new ProcessResult(true, 'owner/repo', '', 0))                          // gh repo view
        ->queue(new ProcessResult(true, '', '', 0))                                    // git log main..HEAD (empty)
        ->queue(new ProcessResult(true, '', '', 0))                                    // git log master..HEAD (empty)
        ->queue(new ProcessResult(true, '', '', 0))                                    // git log develop..HEAD (empty)
        ->queue(new ProcessResult(false, '', 'a pull request for branch "fix/x" already exists', 1)); // gh pr create

    $target = ['branch' => 'fix/x', 'path' => '/wt', 'changes' => '—', 'status' => BeeStatus::Done, 'last_commit' => 'fix: x (now)'];

    $result = (new PrPublisher($runner))->publish($target, 'main');

    expect($result['already_exists'])->toBeTrue()
        ->and($result['url'])->toBeNull();
});

test('publish throws when a git step fails', function () {
    $runner = (new FakeProcessRunner)
        ->queue(new ProcessResult(false, '', 'remote rejected', 1)); // git push fails

    $target = ['branch' => 'fix/x', 'path' => '/wt', 'changes' => '—', 'status' => BeeStatus::Done, 'last_commit' => 'fix: x (now)'];

    expect(fn () => (new PrPublisher($runner))->publish($target, 'main'))
        ->toThrow(\RuntimeException::class, 'remote rejected');
});
