<?php

namespace App\Providers;

use Dotenv\Dotenv;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->loadProjectEnv();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Load .env from the current working directory (the Laravel project being orchestrated)
     * and refresh config values that depend on those env vars.
     */
    private function loadProjectEnv(): void
    {
        $cwd = getcwd();

        if (! $cwd || ! file_exists($cwd . '/.env') || $cwd === base_path()) {
            return;
        }

        Dotenv::createMutable($cwd)->safeLoad();

        // Refresh prism config with newly loaded env vars
        $this->app['config']->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', ''));
        $this->app['config']->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', ''));
    }
}
