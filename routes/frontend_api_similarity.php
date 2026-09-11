<?php

use App\Http\Controllers\Frontend\SimilarityController;
use Illuminate\Support\Facades\Route;

// Data/mutation backend for issue #42 (docs/specs/42-similarity.md), behind
// the web session guard + CSRF (not routes/api.php's auth:sanctum group --
// see docs/specs/README.md's "Contrato comum proposto v1"). Gated to admins
// only: per the spec, a participant must get a plain 403, never even a
// glimpse of another team's data.
Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/api/frontend/similarity', [SimilarityController::class, 'index']);
    Route::post('/api/frontend/similarity/checks', [SimilarityController::class, 'store']);
    Route::get('/api/frontend/similarity/runs/{run}/source', [SimilarityController::class, 'downloadSource']);
});
