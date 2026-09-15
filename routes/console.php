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
// withoutOverlapping(): a large stuck-run backlog (or a hung JudgeRunJob
// dispatch on a sync queue connection) could make one run take longer than
// 5 minutes; without this, two concurrent instances could both increment
// reconcile_attempts or both give up on the same run.
Schedule::command('runs:reconcile-stuck')->everyFiveMinutes()->withoutOverlapping();

// Issue #159: tokens carry an expires_at (config/sanctum.php) and the guard
// refuses an expired one, so this changes no authorization decision -- it
// only stops personal_access_tokens growing without bound over successive
// contests on the same installation. --hours=24 keeps a just-expired row
// around for a day so that "my token stopped working" can still be answered
// by looking.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
