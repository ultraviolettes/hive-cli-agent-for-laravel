<?php

use App\Support\HiveContext;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir() . '/hive-ctx-' . uniqid();
    mkdir($this->tmp);
});

afterEach(fn () => exec("rm -rf {$this->tmp}"));

test('reads allowlisted credentials from the project .env', function () {
    file_put_contents($this->tmp . '/.env', implode("\n", [
        'ANTHROPIC_API_KEY=sk-test',
        'NIGHTWATCH_TOKEN=nw-tok',
        'NIGHTWATCH_PROJECT_ID=42',
    ]));

    $ctx = HiveContext::fromPath($this->tmp);

    expect($ctx->path)->toBe($this->tmp)
        ->and($ctx->anthropicApiKey())->toBe('sk-test')
        ->and($ctx->nightwatchToken())->toBe('nw-tok')
        ->and($ctx->nightwatchProjectId())->toBe('42');
});

test('ignores keys that are not on the allowlist', function () {
    file_put_contents($this->tmp . '/.env', "DB_PASSWORD=secret\nAPP_KEY=base64:abc\n");

    $ctx = HiveContext::fromPath($this->tmp);

    expect($ctx->env('DB_PASSWORD'))->toBeNull()
        ->and($ctx->env('APP_KEY'))->toBeNull();
});

test('never mutates the global environment', function () {
    file_put_contents($this->tmp . '/.env', "ANTHROPIC_API_KEY=sk-leak\n");

    HiveContext::fromPath($this->tmp);

    expect(getenv('ANTHROPIC_API_KEY'))->not->toBe('sk-leak')
        ->and($_ENV['ANTHROPIC_API_KEY'] ?? null)->not->toBe('sk-leak')
        ->and($_SERVER['ANTHROPIC_API_KEY'] ?? null)->not->toBe('sk-leak');
});

test('returns null credentials when the project has no .env', function () {
    $ctx = HiveContext::fromPath($this->tmp);

    expect($ctx->anthropicApiKey())->toBeNull()
        ->and($ctx->nightwatchToken())->toBeNull()
        ->and($ctx->env('ANYTHING', 'fallback'))->toBe('fallback');
});
