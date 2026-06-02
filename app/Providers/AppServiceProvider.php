<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationEvents;
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
        $listener = new LogAuthenticationEvents();
        Event::listen(Login::class, [$listener, 'handleLogin']);
        Event::listen(Logout::class, [$listener, 'handleLogout']);

        RateLimiter::for('product-import-heavy', fn (Request $request): Limit => Limit::perMinute(6)->by($this->rateLimitKey($request, 'product-import-heavy')));
        RateLimiter::for('product-import-export', fn (Request $request): Limit => Limit::perMinute(10)->by($this->rateLimitKey($request, 'product-import-export')));
        RateLimiter::for('product-import-mutation', fn (Request $request): Limit => Limit::perMinute(30)->by($this->rateLimitKey($request, 'product-import-mutation')));
    }

    private function rateLimitKey(Request $request, string $scope): string
    {
        return $scope.'|'.($request->user()?->id ?: $request->ip());
    }
}
