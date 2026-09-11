<?php

use App\Http\Controllers\Webcast\ScoreboardController;
use Illuminate\Support\Facades\Route;

// Issue #44 -- the ENTIRE surface area a webcast credential can reach.
// Registered from bootstrap/app.php's withRouting(then: ...) callback with
// its own middleware group (webcast.auth + throttle), deliberately outside
// both the `web` group (no session/CSRF -- this consumer has no browser
// session) and the `api` group (no Sanctum). See
// app/Http/Middleware/AuthenticateWebcastCredential.php and
// app/Http/Controllers/Webcast/ScoreboardController.php.
Route::get('/api/webcast/scoreboard', [ScoreboardController::class, 'show']);
