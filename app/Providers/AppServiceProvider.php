<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
