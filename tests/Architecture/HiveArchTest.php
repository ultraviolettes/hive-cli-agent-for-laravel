<?php

arch('commands are in Commands namespace')
    ->expect('App\Commands')
    ->toExtend(\LaravelZero\Framework\Commands\Command::class)
    ->ignoring('App\Commands\Concerns');

arch('services are classes')
    ->expect('App\Services')
    ->toBeClasses();

arch('services are final')
    ->expect('App\Services')
    ->toBeFinal();

arch('commands run external processes only through the ProcessRunner seam')
    ->expect('App\Commands')
    ->not->toUse(\Symfony\Component\Process\Process::class);

arch('DagAnalyzerAgent implements laravel/ai contracts')
    ->expect('App\Ai\Agents\DagAnalyzerAgent')
    ->toImplement(\Laravel\Ai\Contracts\Agent::class)
    ->toImplement(\Laravel\Ai\Contracts\HasStructuredOutput::class);
