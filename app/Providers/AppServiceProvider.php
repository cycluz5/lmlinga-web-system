<?php

namespace App\Providers;

use App\Support\AtRestEncrypter;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Same wiring as RoutingServiceProvider, with a generator that emits opaque ids.
        $this->app->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();
            $app->instance('routes', $routes);

            return new \App\Routing\OpaqueUrlGenerator(
                $routes,
                $app->rebinding('request', static function ($app, $request): void {
                    $app['url']->setRequest($request);
                }),
                $app['config']['app.asset_url']
            );
        });

        $this->app->singleton(AtRestEncrypter::class, function ($app): AtRestEncrypter {
            return AtRestEncrypter::fromConfig(
                $app['config']->get('lmlinga.at_rest', []),
                (string) $app['config']->get('app.key', ''),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
    }
}
