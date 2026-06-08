<?php

use App\Contracts\ClaudeCode;
use App\Contracts\DagProvider;
use App\Process\ProcessRunner;
use App\Process\SymfonyProcessRunner;
use App\Services\ClaudeCodeGateway;
use App\Services\DagAnalyzer;

test('container resolves the process runner contract', function () {
    expect(app(ProcessRunner::class))->toBeInstanceOf(SymfonyProcessRunner::class);
});

test('container resolves the claude code contract', function () {
    expect(app(ClaudeCode::class))->toBeInstanceOf(ClaudeCodeGateway::class);
});

test('container resolves the dag provider with its dependencies autowired', function () {
    expect(app(DagProvider::class))->toBeInstanceOf(DagAnalyzer::class);
});
