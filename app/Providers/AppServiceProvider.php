<?php

namespace App\Providers;

use App\Contracts\ClaudeCode;
use App\Contracts\DagProvider;
use App\Process\ProcessRunner;
use App\Process\SymfonyProcessRunner;
use App\Services\ClaudeCodeGateway;
use App\Services\DagAnalyzer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProcessRunner::class, SymfonyProcessRunner::class);
        $this->app->bind(ClaudeCode::class, ClaudeCodeGateway::class);
        $this->app->bind(DagProvider::class, DagAnalyzer::class);
    }

    /**
     * Bootstrap any application services.
     *
     * The target project's credentials are no longer hoisted into the global
     * environment here. They are read explicitly per project via HiveContext
     * (see App\Support\HiveContext) so multiple projects can be driven from a
     * single process without leaking secrets across them.
     */
    public function boot(): void
    {
        //
    }
}
