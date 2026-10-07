<?php

namespace App\Providers;

use App\Services\PayWay\PayWaySignature;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PayWaySignature::class, function () {
            return new PayWaySignature((string) config('payway.api_key'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
