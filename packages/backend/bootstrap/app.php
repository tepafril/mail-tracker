<?php

use App\Http\Middleware\InitializeTenancyForUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Honor X-Forwarded-* so URL/scheme generation is correct behind a tunnel
        // (ngrok) or load balancer. Scope `at:` to your LB's IPs in production.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            // Initialize tenancy from the authenticated Sanctum user (single-DB mode).
            'tenant.user' => InitializeTenancyForUser::class,
            // Sanctum token-ability guards.
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
