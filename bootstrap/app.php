<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register role middleware alias
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'warehouse_context' => \App\Http\Middleware\EnforceWarehouseContext::class,
        ]);
        
        // Exclude API routes from CSRF verification
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        $middleware->appendToGroup('api',
            \App\Http\Middleware\RequestTimingMiddleware::class
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
