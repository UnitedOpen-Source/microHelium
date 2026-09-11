<?php

use App\Http\Controllers\Frontend\WebcastController;
use Illuminate\Support\Facades\Route;

// Issue #44 -- /api/frontend/webcast*. Required from the bottom of
// routes/web.php, so these inherit the `web` group (session + CSRF) that
// web.php itself is registered under -- per docs/specs/README.md's shared
// contract, this is a session-authenticated JSON API, not a bearer-token
// one. See app/Http/Controllers/Frontend/WebcastController.php.
//
// The webcast-credential-authenticated scoreboard read (the consumer-
// facing side of #44) is intentionally NOT here -- it lives in
// routes/webcast_consumer.php, registered outside both the `web` and
// `api` groups from bootstrap/app.php, with its own guard middleware and
// no session/CSRF at all.
Route::prefix('api/frontend')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/webcast', [WebcastController::class, 'index']);
    Route::post('/webcast/credentials', [WebcastController::class, 'storeCredential']);
    Route::delete('/webcast/credentials/{id}', [WebcastController::class, 'revokeCredential'])->whereNumber('id');
    Route::get('/webcast/export', [WebcastController::class, 'export']);
});
