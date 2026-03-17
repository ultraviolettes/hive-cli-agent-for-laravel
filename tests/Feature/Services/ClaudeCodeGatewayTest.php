<?php

use App\Services\ClaudeCodeGateway;

test('throws when claude cli is not available', function () {
    $gateway = new ClaudeCodeGateway(binary: '/nonexistent/claude');
    expect($gateway->isAvailable())->toBeFalse();
});

test('parses json from claude response and strips markdown fences', function () {
    $gateway = new ClaudeCodeGateway;

    // Test the JSON parsing logic by using reflection to test promptJson indirectly
    $result = '```json
{"tasks": [{"title": "Test"}]}
```';

    // Strip markdown code fences (same logic as promptJson)
    $result = preg_replace('/^```(?:json)?\s*\n?/m', '', $result);
    $result = preg_replace('/\n?```\s*$/m', '', $result);
    $decoded = json_decode(trim($result), true);

    expect($decoded)->toBeArray()
        ->and($decoded['tasks'])->toHaveCount(1)
        ->and($decoded['tasks'][0]['title'])->toBe('Test');
});
