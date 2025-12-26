<?php

use App\Http\Controllers\Api\ContestController;
use App\Http\Controllers\Api\ClarificationController;
use App\Http\Controllers\Api\ProblemController;
use App\Http\Controllers\Api\RunController;
use App\Http\Controllers\Api\ScoreboardController;
use App\Models\Contest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::apiResource('contests', ContestController::class);
    Route::post('/contests/{contest}/activate', [ContestController::class, 'activate']);
    Route::post('/contests/{contest}/deactivate', [ContestController::class, 'deactivate']);
    Route::get('/contests/{contest}/status', [ContestController::class, 'status']);

    Route::get('/clarifications/pending', [ClarificationController::class, 'pending']);
    Route::apiResource('clarifications', ClarificationController::class);
    Route::put('/clarifications/{clarification}/answer', [ClarificationController::class, 'answer']);

    Route::apiResource('problems', ProblemController::class);
    Route::get('/problems/{problem}/download', [ProblemController::class, 'download']);
    Route::get('/problems/{problem}/export', [ProblemController::class, 'exportPackage']);

    Route::apiResource('runs', RunController::class);
    Route::get('/runs/{run}/source', [RunController::class, 'downloadSource']);
    Route::post('/runs/{run}/rejudge', [RunController::class, 'rejudge']);
    Route::put('/runs/{run}/judge', [RunController::class, 'judge']);

    Route::get('/contests/{contest}/scoreboard', [ScoreboardController::class, 'index']);
    Route::get('/contests/{contest}/my-score', [ScoreboardController::class, 'userScore']);
    Route::get('/contests/{contest}/scoreboard/export', [ScoreboardController::class, 'export']);
    Route::get('/contests/{contest}/statistics', [ScoreboardController::class, 'statistics']);
});


// Health check
Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// Current active contest for timer
Route::get('/contest/current', function () {
    $contest = Contest::where('is_active', true)->first();

    if (!$contest) {
        return response()->json(null);
    }

    return response()->json([
        'id' => $contest->id,
        'name' => $contest->name,
        'start_time' => $contest->start_time?->toIso8601String(),
        'duration' => $contest->duration,
        'freeze_time' => $contest->getRawOriginal('freeze_time') ?? 60,
        'is_running' => $contest->isRunning(),
        'is_frozen' => $contest->isFrozen(),
    ]);
});
