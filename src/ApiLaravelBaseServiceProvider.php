<?php

namespace Experteam\ApiLaravelBase;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class ApiLaravelBaseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {

        Auth::extend('api-guard-redis', function ($app, $name, array $config) {

            return new ApiSecurityAuthGuard(
                Auth::createUserProvider($config['provider']),
                $app->make('request')
            );

        });

    }
}
