<?php

namespace App\Providers;

use App\Support\AtRestEncrypter;
use App\Support\Database\CachingSchemaBuilder;
use App\Support\Database\SchemaLookupCache;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\SchemaLoaded;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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

        // Schema facade answers hasTable / hasColumn from a memo (see SchemaLookupCache).
        $this->app->singleton(SchemaLookupCache::class);
        $this->app->bind('db.schema', function ($app): CachingSchemaBuilder {
            return new CachingSchemaBuilder(
                $app['db']->connection()->getSchemaBuilder(),
                $app->make(SchemaLookupCache::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
        $this->flushSchemaLookupsOnSchemaChange();

        // Staff name suggestions: per signed-in staff user (typing is debounced client-side).
        RateLimiter::for('lmlinga-name-suggest', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by('name-suggest|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Staff live duplicate check: same rate as name suggestions, per signed-in staff user.
        RateLimiter::for('lmlinga-duplicate-check', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by('duplicate-check|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Staff email verification (guest step after a correct password). Keyed by IP and the pending account
        // bound to this browser session, so one account cannot exhaust another's limit.
        $staffOtpKey = static function (Request $request): string {
            $binding = $request->hasSession()
                ? $request->session()->get(\App\Services\StaffEmailVerificationService::SESSION_KEY)
                : null;
            $userId = is_array($binding) ? (string) ($binding['user_id'] ?? '') : '';

            return 'staff-email-otp|'.$request->ip().'|'.$userId;
        };

        RateLimiter::for('lmlinga-staff-email-otp-send', static fn (Request $request): Limit => Limit::perMinutes(
            10,
            max(1, (int) config('lmlinga.staff_email_verification.send_per_10_minutes', 5))
        )->by($staffOtpKey($request)));

        RateLimiter::for('lmlinga-staff-email-otp-verify', static fn (Request $request): Limit => Limit::perMinutes(
            10,
            max(1, (int) config('lmlinga.staff_email_verification.verify_per_10_minutes', 10))
        )->by($staffOtpKey($request)));

        // Resident registration OTP: keyed by IP + pending email (session-bound, or the submitted email on the form POST).
        $residentOtpKey = static function (Request $request): string {
            $binding = $request->hasSession()
                ? $request->session()->get(\App\Services\ResidentRegistrationOtpService::SESSION_KEY)
                : null;
            $email = is_array($binding)
                ? (string) ($binding['email'] ?? '')
                : strtolower(trim((string) $request->input('email', '')));

            return 'resident-register-otp|'.$request->ip().'|'.$email;
        };

        RateLimiter::for('lmlinga-resident-register-otp-send', static fn (Request $request): Limit => Limit::perMinutes(
            10,
            max(1, (int) config('lmlinga.resident_registration_otp.send_per_10_minutes', 5))
        )->by($residentOtpKey($request)));

        RateLimiter::for('lmlinga-resident-register-otp-verify', static fn (Request $request): Limit => Limit::perMinutes(
            10,
            max(1, (int) config('lmlinga.resident_registration_otp.verify_per_10_minutes', 10))
        )->by($residentOtpKey($request)));

        if ($this->app->runningInConsole()) {
            $this->prependMysqlClientToPath();

            if (! $this->app->environment('testing')) {
                $this->ensureSuperAdminAfterMigrate();
            }
        }
    }

    /**
     * DDL, rolled-back transactions (SQLite DDL is transactional) and migration
     * runs can change which tables and columns exist.
     */
    private function flushSchemaLookupsOnSchemaChange(): void
    {
        $flush = fn () => $this->app->make(SchemaLookupCache::class)->flush();

        Event::listen(QueryExecuted::class, function (QueryExecuted $event) use ($flush): void {
            if (SchemaLookupCache::changesSchema($event->sql)) {
                $flush();
            }
        });
        Event::listen([
            TransactionRolledBack::class,
            MigrationsStarted::class,
            MigrationsEnded::class,
            SchemaLoaded::class,
            DatabaseRefreshed::class,
        ], $flush);
    }

    /**
     * Every successful migrate (even "Nothing to migrate") re-creates the super admin if it is missing.
     */
    private function ensureSuperAdminAfterMigrate(): void
    {
        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            if ($event->exitCode !== 0
                || ! in_array($event->command, ['migrate', 'migrate:fresh', 'migrate:refresh'], true)
                || ($event->input->hasOption('pretend') && $event->input->getOption('pretend'))) {
                return;
            }

            (new SuperAdminSeeder)->ensureExists();
            $event->output->writeln('<info>Super admin account ensured: maria.santos</info>');
        });
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
