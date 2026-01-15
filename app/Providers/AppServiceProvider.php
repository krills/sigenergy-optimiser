<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\PriceProviderInterface;
use App\Services\ElprisetjustNuApiService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind the price provider interface to a concrete implementation
        $this->app->bind(PriceProviderInterface::class, ElprisetjustNuApiService::class);
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
