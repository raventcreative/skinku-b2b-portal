<?php

use App\Http\Middleware\InternalOnlyMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'internal' => InternalOnlyMiddleware::class,
        ]);

        // Webhook Telegram: request publik dari server Telegram, tak pernah
        // membawa CSRF token. Keamanan dijaga oleh verifikasi secret token di
        // TelegramWebhookController, bukan oleh CSRF.
        $middleware->validateCsrfTokens(except: [
            'telegram/webhook',
            'api/kol-agent/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
