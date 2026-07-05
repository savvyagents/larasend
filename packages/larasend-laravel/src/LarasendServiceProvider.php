<?php

namespace Larasend\Laravel;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class LarasendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/larasend.php', 'larasend');

        $this->app->singleton(LarasendClient::class, function () {
            return new LarasendClient(
                apiKey: config('larasend.api_key'),
                endpoint: config('larasend.endpoint'),
                timeout: (int) config('larasend.timeout', 15),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/larasend.php' => config_path('larasend.php'),
        ], 'larasend-config');

        Mail::extend('larasend', function () {
            return new LarasendTransport($this->app->make(LarasendClient::class));
        });
    }
}
