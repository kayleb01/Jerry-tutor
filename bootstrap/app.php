<?php

use App\Http\Middleware\EnsureQuizAccess;
use App\Http\Middleware\EnsureTutorIsApproved;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Gateways post webhooks without a CSRF token; the signature check takes its place.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'quiz.access' => EnsureQuizAccess::class,
            'approved.tutor' => EnsureTutorIsApproved::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
