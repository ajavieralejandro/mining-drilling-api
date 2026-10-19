<?php

namespace App\Providers;

use App\Services\Source\DispatchingSourceGateway;
use App\Services\Source\SourceCommandGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SourceCommandGateway::class, DispatchingSourceGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
