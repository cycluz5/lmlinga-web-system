<?php

namespace App\Providers;

use App\Models\User;
use App\Support\AtRestEncrypter;
use App\Support\ResidentAuthenticator;
use App\Support\StaffRole;
use App\Support\Database\CachingSchemaBuilder;
use App\Support\Database\SchemaLookupCache;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\SchemaLoaded;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        $this->registerResidentRateLimiters();

        // SR-06: only an active Linguist may approve/reject chatbot terms or datasets (never Admin — D-7).
        // Used by the SR-05 / SR-07 review actions.
        Gate::define('review-language-content', static fn (User $user): bool => $user->isActive()
            && StaffRole::isLinguist($user->role));

        if ($this->app->runningInConsole()) {
            $this->prependMysqlClientToPath();

            if (! $this->app->environment('testing')) {
                $this->ensureSuperAdminAfterMigrate();
            }
        }
    }

    /**
     * Named limiters for resident household linking. Plain throttle:N,M keys every route by IP
     * alone, so all such routes (including forgot-password) would share one counter per IP;
     * named limiters get their own key. Residents are not default-guard users, so resident
     * limits are keyed by the session's resident account, falling back to IP before login.
     */
    private function registerResidentRateLimiters(): void
    {
        $residentKey = static function (Request $request): string {
            $accountId = $request->hasSession()
                ? $request->session()->get(ResidentAuthenticator::SESSION_ACCOUNT_ID)
                : null;

            return $accountId !== null && $accountId !== ''
                ? 'account:'.$accountId
                : 'ip:'.$request->ip();
        };

        // SR-07: dataset uploads/replacements per signed-in Admin.
        RateLimiter::for('lmlinga-dataset-upload', static fn (Request $request): Limit => Limit::perHour(
            max(1, (int) config('lmlinga.chatbot_datasets.uploads_per_hour', 20))
        )->by('staff:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('lmlinga-household-submit', static fn (Request $request): Limit => Limit::perHour(
            max(1, (int) config('lmlinga.household_linking.submit_per_hour', 10))
        )->by($residentKey($request)));

        RateLimiter::for('lmlinga-household-sms-send', static fn (Request $request): Limit => Limit::perMinutes(
            10,
            max(1, (int) config('lmlinga.household_linking.sms_send_per_10_minutes', 5))
        )->by($residentKey($request)));

        RateLimiter::for('lmlinga-resident-register', static fn (Request $request): Limit => Limit::perHour(
            max(1, (int) config('lmlinga.household_linking.register_per_hour_per_ip', 5))
        )->by('ip:'.$request->ip()));

        // Email OTP (SR-03): registration resend/verify are keyed by IP (no account yet), existing-account
        // send/verify by resident account. Per-address limits live in ResidentEmailOtpService.
        RateLimiter::for('lmlinga-resident-email-otp-send', static fn (Request $request): Limit => Limit::perMinutes(
            10,
            max(1, (int) config('lmlinga.resident_email_verification.send_per_10_minutes', 5))
        )->by($residentKey($request)));

        RateLimiter::for('lmlinga-resident-email-otp-verify', static fn (Request $request): Limit => Limit::perMinutes(
            10,
            max(1, (int) config('lmlinga.resident_email_verification.verify_per_10_minutes', 10))
        )->by($residentKey($request)));
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
