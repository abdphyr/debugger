<?php

namespace Abd\Debugger;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DebuggerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerRoutes();
        $this->registerResources();
        $this->registerPublishing();
        $this->registerCommands();
    }

    protected function registerRoutes()
    {
        Route::group([
            'as' => 'debugger.',
            'prefix' => '/debugger',
            'namespace' => 'Abd\Debugger\Http\Controllers',
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        });
    }

    protected function registerResources()
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'debugger');
        // if ($this->app->runningInConsole()) {
            // $this->publishes([
            //     __DIR__ . '/../resources/views' => $this->app->resourcePath('views/vendor/debugger'),
            // ], 'debugger');
        // }
    }

    /**
     * Register the package's publishable resources.
     *
     * @return void
     */
    protected function registerPublishing()
    {
        // if ($this->app->runningInConsole()) {           
        //     $this->publishes([
        //         __DIR__.'/../resources/views' => base_path('resources/views/vendor/passport'),
        //     ], 'passport-views');

        //     $this->publishes([
        //         __DIR__.'/../config/passport.php' => config_path('passport.php'),
        //     ], 'passport-config');
        // }
    }

    /**
     * Register the Passport Artisan commands.
     *
     * @return void
     */
    protected function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\AbdDebug::class,
                Console\Commands\AbdApiDoc::class,
            ]);
        }
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
    }
}
