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

        if ($this->app->runningInConsole()) {
            $this->prependMysqlClientToPath();
        }
    }

    /**
     * `migrate` on an empty database loads database/schema/mysql-schema.sql by
     * shelling out to `mysql`; let DB_CLIENT_BIN_PATH supply it when it is not
     * on PATH (e.g. XAMPP on Windows). Only affects this process and its children.
     */
    private function prependMysqlClientToPath(): void
    {
        $binPath = config('database.connections.mysql.client_bin_path');
        if (! is_string($binPath) || $binPath === '' || ! is_dir($binPath)) {
            return;
        }
        // Native separators: cmd.exe does not reliably search "C:/…" PATH entries.
        $binPath = realpath($binPath) ?: $binPath;

        $key = 'PATH';
        foreach (array_keys(getenv()) as $name) {
            if (strcasecmp($name, 'PATH') === 0) {
                $key = $name;
                break;
            }
        }

        $current = (string) getenv($key);
        $normalize = static fn (string $dir): string => rtrim(str_replace('\\', '/', strtolower($dir)), '/');
        foreach (explode(PATH_SEPARATOR, $current) as $dir) {
            if ($normalize($dir) === $normalize($binPath)) {
                return;
            }
        }

        $value = $current === '' ? $binPath : $binPath.PATH_SEPARATOR.$current;
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
