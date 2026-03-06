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
        then: function () {
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_dashboard.php'));
            
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_inventory.php'));
            
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_warehouses.php'));
            
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_inventory_movements.php'));
            
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_reports.php'));
            
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_customers.php'));
            
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_vehicles.php'));
            
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_users.php'));
            
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_purchase_orders.php'));
            
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_payment_methods.php'));
                
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_store_settings.php'));
                
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_backup.php'));
                
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_activity_logs.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register role middleware alias
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);

        // Enable API CORS (already enabled by default for /api routes)
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
        
        // Exclude API routes from CSRF verification
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
