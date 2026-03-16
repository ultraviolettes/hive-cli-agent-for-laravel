<?php

use App\Services\NightwatchIngester;
use Illuminate\Support\Facades\Http;

test('fetches exceptions from Nightwatch API', function () {
    Http::fake([
        'nightwatch.laravel.com/*' => Http::response([
            'data' => [
                ['id' => 'exc_1', 'message' => 'Undefined variable $user', 'file' => 'app/Http/Controllers/UserController.php', 'line' => 42, 'occurrences' => 150, 'resolved' => false],
                ['id' => 'exc_2', 'message' => 'SQLSTATE: Column not found', 'file' => 'app/Models/Order.php', 'line' => 78, 'occurrences' => 23, 'resolved' => false],
            ],
        ]),
    ]);

    $ingester = new NightwatchIngester('fake-token', 'fake-project');
    $exceptions = $ingester->fetch();

    expect($exceptions)->toHaveCount(2)
        ->and($exceptions[0]['message'])->toBe('Undefined variable $user')
        ->and($exceptions[0]['occurrences'])->toBe(150);
});

test('sorts exceptions by occurrences descending', function () {
    $ingester = new NightwatchIngester('token', 'project');
    $exceptions = [
        ['message' => 'Error B', 'occurrences' => 10, 'file' => 'B.php', 'line' => 1],
        ['message' => 'Error A', 'occurrences' => 150, 'file' => 'A.php', 'line' => 1],
    ];

    $sorted = $ingester->sortByImpact($exceptions);
    expect($sorted[0]['message'])->toBe('Error A');
});

test('formats exceptions as text for DagAnalyzerAgent', function () {
    $ingester = new NightwatchIngester('token', 'project');
    $exceptions = [
        ['message' => 'Undefined variable $user', 'file' => 'app/Controllers/UserController.php', 'line' => 42, 'occurrences' => 150],
    ];

    $text = $ingester->formatForAnalysis($exceptions);

    expect($text)->toContain('Undefined variable $user')
        ->and($text)->toContain('UserController.php')
        ->and($text)->toContain('150 occurrences');
});
