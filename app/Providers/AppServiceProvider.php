<?php

namespace App\Providers;

use Dotenv\Dotenv;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadProjectEnv();
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Load .env from the current working directory (the Laravel project being orchestrated).
     */
    private function loadProjectEnv(): void
    {
        $cwd = getcwd();

        if ($cwd && file_exists($cwd . '/.env') && $cwd !== base_path()) {
            Dotenv::createMutable($cwd)->safeLoad();
        }
    }
}
