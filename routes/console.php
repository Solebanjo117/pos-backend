<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Daily database backup at 02:00
Schedule::command('db:backup')->daily()->at('02:00');

// Weekly cleanup of old backups (runs every Sunday at 03:00)
Schedule::command('db:clean-backups')->weekly()->sundays()->at('03:00');

Schedule::command('products:import-reconcile')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
