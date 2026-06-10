<?php

use App\Process\ProcessResult;
use App\Services\GithubIngester;
use Tests\Support\FakeProcessRunner;

test('parses gh issue list output into structured array', function () {
    $ingester = new GithubIngester('owner/repo');

    $fakeOutput = json_encode([
        ['number' => 42, 'title' => 'Fix login bug', 'body' => 'Users cannot login', 'labels' => [['name' => 'bug']]],
        ['number' => 43, 'title' => 'Add dark mode', 'body' => 'Feature request', 'labels' => [['name' => 'enhancement']]],
    ]);

    $issues = $ingester->parseOutput($fakeOutput);

    expect($issues)->toHaveCount(2)
        ->and($issues[0]['number'])->toBe(42)
        ->and($issues[0]['title'])->toBe('Fix login bug')
        ->and($issues[0]['labels'])->toContain('bug');
});

test('formats issues as text for DagAnalyzerAgent', function () {
    $ingester = new GithubIngester('owner/repo');
    $issues = [
        ['number' => 42, 'title' => 'Fix login bug', 'body' => 'Details here', 'labels' => ['bug']],
        ['number' => 43, 'title' => 'Add dark mode', 'body' => 'Feature', 'labels' => ['enhancement']],
    ];

    $text = $ingester->formatForAnalysis($issues);

    expect($text)->toContain('#42')
        ->and($text)->toContain('Fix login bug')
        ->and($text)->toContain('[bug]');
});

test('throws when gh cli is not available', function () {
    $ingester = new GithubIngester('owner/repo', ghBinary: '/nonexistent/gh');
    expect(fn () => $ingester->fetch())->toThrow(\RuntimeException::class);
});

test('fetch builds the gh command from the constructor parameters', function () {
    $runner = (new FakeProcessRunner)
        ->queue(new ProcessResult(true, '/usr/bin/gh', '', 0))  // which gh
        ->queue(new ProcessResult(true, '[]', '', 0));          // gh issue list

    (new GithubIngester('owner/repo', 'v1.0', 10, 'gh', $runner))->fetch();

    $cmd = $runner->calls[1]['command'];
    expect($cmd)->toContain('owner/repo')
        ->and($cmd)->toContain('10')
        ->and(implode(' ', $cmd))->toContain('milestone:"v1.0"');
});
