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
        // Elastic Search Log
        app()->bind('ESLog', function () {
            return new \Experteam\ApiLaravelBase\ESLog();
        });

        // Access Permisson
        app()->bind('AccessPermission', function () {
            return new \Experteam\ApiLaravelBase\AccessPermission();
        });

        // Business Days
        app()->bind('BusinessDays', function () {
            return new \Experteam\ApiLaravelBase\BusinessDays();
        });
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
