<?php

arch('commands are in Commands namespace')
    ->expect('App\Commands')
    ->toExtend(\LaravelZero\Framework\Commands\Command::class);

arch('services are final')
    ->expect('App\Services')
    ->toBeClasses()->toBeFinal();

arch('DagAnalyzerAgent implements laravel/ai contracts')
    ->expect('App\Ai\Agents\DagAnalyzerAgent')
    ->toImplement(\Laravel\Ai\Contracts\Agent::class)
    ->toImplement(\Laravel\Ai\Contracts\HasStructuredOutput::class);
