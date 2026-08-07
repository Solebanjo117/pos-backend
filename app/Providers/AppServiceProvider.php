<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationEvents;
use App\Models\CapitalMovement;
use App\Models\OperationalExpense;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrderPayment;
use App\Services\FinancialLiquiditySyncService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([
            SalesOrderPayment::class,
            PurchaseOrderPayment::class,
            OperationalExpense::class,
            CapitalMovement::class,
        ] as $modelClass) {
            $modelClass::created(fn ($model) => app(FinancialLiquiditySyncService::class)->sync($model));
            $modelClass::deleting(fn ($model) => app(FinancialLiquiditySyncService::class)->reverse($model));
        }
        OperationalExpense::updated(function (OperationalExpense $expense): void {
            $syncService = app(FinancialLiquiditySyncService::class);
            if (in_array($expense->status, ['cancelled', 'reversed'], true)) {
                $syncService->reverse($expense);

                return;
            }

            $syncService->sync($expense);
        });

        $listener = new LogAuthenticationEvents;
        Event::listen(Login::class, [$listener, 'handleLogin']);
        Event::listen(Logout::class, [$listener, 'handleLogout']);

        RateLimiter::for('product-import-heavy', fn (Request $request): Limit => Limit::perMinute(6)->by($this->rateLimitKey($request, 'product-import-heavy')));
        RateLimiter::for('product-import-chunk', fn (Request $request): Limit => Limit::perMinute(120)->by($this->chunkRateLimitKey($request, 'product-import-chunk')));
        RateLimiter::for('product-import-export', fn (Request $request): Limit => Limit::perMinute(10)->by($this->rateLimitKey($request, 'product-import-export')));
        RateLimiter::for('product-import-mutation', fn (Request $request): Limit => Limit::perMinute(30)->by($this->rateLimitKey($request, 'product-import-mutation')));
    }

    private function rateLimitKey(Request $request, string $scope): string
    {
        return $scope.'|'.($request->user()?->id ?: $request->ip());
    }

    private function chunkRateLimitKey(Request $request, string $scope): string
    {
        $importRun = $request->route('importRun');
        $importRunKey = is_object($importRun) && method_exists($importRun, 'getKey')
            ? $importRun->getKey()
            : $importRun;

        return $this->rateLimitKey($request, $scope).'|'.($importRunKey ?: 'unknown');
    }
}
