<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(\App\Http\Middleware\DecodeOpaqueUrl::class);

        $middleware->web(prepend: [
            \App\Http\Middleware\EnsureOfflineJsonRequests::class,
        ], append: [
            \App\Http\Middleware\EnsureOpaqueRouteParameters::class,
        ]);

        $middleware->alias([
            'ui.role' => \App\Http\Middleware\PersistUiRole::class,
            'ui.admin' => \App\Http\Middleware\EnsureAdminRole::class,
            'staff.password-current' => \App\Http\Middleware\EnsurePasswordIsCurrent::class,
            'staff.account-usable' => \App\Http\Middleware\EnsureStaffAccountIsUsable::class,
            'resident.chatbot' => \App\Http\Middleware\EnsureResidentChatbotAccount::class,
            'auth.nocache' => \App\Http\Middleware\PreventAuthenticatedPageCache::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Health Worker Edit uses hw_* password field names (frozen UI contract).
        $exceptions->dontFlash([
            'hw_password',
            'hw_password_confirmation',
            'email',
            'username',
            'full_name',
            'password',
            'password_confirmation',
        ]);

        // Laravel does not report 404s; keep a path-only trace of which URL missed.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
                || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                \Illuminate\Support\Facades\Log::warning('http_404', [
                    'method' => $request->method(),
                    'path' => (string) $request->attributes->get('opaque.original_uri', $request->getPathInfo()),
                    'route' => $request->route()?->uri(),
                    'referer_path' => parse_url((string) $request->headers->get('referer'), PHP_URL_PATH) ?: null,
                    'reason' => $e::class,
                ]);
            }

            return null;
        });

        $exceptions->shouldRenderJsonWhen(function (\Illuminate\Http\Request $request): bool {
            return $request->is('offline') || $request->is('offline/*') || $request->expectsJson();
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if (! $request->is('offline') && ! $request->is('offline/*')) {
                return null;
            }

            return response()->json([
                'ok' => false,
                'code' => 'SESSION_EXPIRED',
                'message' => 'Your session has expired. Please sign in again.',
            ], 401);
        });

        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, \Illuminate\Http\Request $request) {
            if (! $request->is('offline') && ! $request->is('offline/*')) {
                return null;
            }

            return response()->json([
                'ok' => false,
                'code' => 'CSRF_MISMATCH',
                'message' => 'The CSRF token is missing or invalid.',
            ], 419);
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            if (! $request->is('offline') && ! $request->is('offline/*')) {
                return null;
            }

            return response()->json([
                'ok' => false,
                'code' => 'CSRF_MISMATCH',
                'message' => 'The CSRF token is missing or invalid.',
            ], 419);
        });

        $exceptions->render(function (\App\Support\Offline\OfflineSyncException $e) {
            return $e->toResponse();
        });
    })->create();
