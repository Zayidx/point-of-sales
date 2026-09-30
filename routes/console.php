<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('crm:sync-segments')->dailyAt('01:00');
Schedule::command('crm:generate-reminders')->dailyAt('01:15');
Schedule::command('reorder:generate')->dailyAt('02:00');
Schedule::command('backup:database')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('transactions:expire')->hourly();
Schedule::command('outlets:notify-unclosed')->everyFifteenMinutes()->withoutOverlapping();
