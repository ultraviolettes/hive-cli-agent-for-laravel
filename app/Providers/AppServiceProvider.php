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

        // Parse the target project's .env file directly
        $envValues = Dotenv::parse(file_get_contents($cwd . '/.env'));

        // Put values into $_ENV and $_SERVER so env() picks them up
        foreach ($envValues as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("$key=$value");
        }

        // Refresh prism provider configs with the loaded API keys
        $this->app['config']->set('prism.providers.anthropic.api_key', $envValues['ANTHROPIC_API_KEY'] ?? '');
        $this->app['config']->set('prism.providers.openai.api_key', $envValues['OPENAI_API_KEY'] ?? '');

        // Also refresh Nightwatch-related env vars if present
        if (isset($envValues['NIGHTWATCH_TOKEN'])) {
            $this->app['config']->set('prism.providers.nightwatch_token', $envValues['NIGHTWATCH_TOKEN']);
        }
    }
}
