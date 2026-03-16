<?php

use App\Services\GithubIngester;

test('parses gh issue list output into structured array', function () {
    $ingester = new GithubIngester;

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
    $ingester = new GithubIngester;
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
    $ingester = new GithubIngester(ghBinary: '/nonexistent/gh');
    expect(fn () => $ingester->fetch('owner/repo'))->toThrow(\RuntimeException::class);
});
