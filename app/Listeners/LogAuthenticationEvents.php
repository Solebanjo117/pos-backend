<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Spatie\Activitylog\Models\Activity;

class LogAuthenticationEvents
{
    /**
     * Handle the Login event.
     */
    public function handleLogin(Login $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->withProperties([
                'ip'    => request()->ip(),
                'agent' => request()->userAgent(),
            ])
            ->event('login')
            ->log("{$event->user->name} inició sesión");
    }

    /**
     * Handle the Logout event.
     */
    public function handleLogout(Logout $event): void
    {
        if (! $event->user) {
            return;
        }

        activity('auth')
            ->causedBy($event->user)
            ->withProperties([
                'ip'    => request()->ip(),
                'agent' => request()->userAgent(),
            ])
            ->event('logout')
            ->log("{$event->user->name} cerró sesión");
    }
}
