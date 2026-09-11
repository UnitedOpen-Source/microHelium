<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Issue #45: the "scheduler" service in docker-compose.yml already runs
// `php artisan schedule:run` in a loop, but nothing was actually scheduled
// on it until now.
Schedule::command('runs:reconcile-stuck')->everyFiveMinutes();
