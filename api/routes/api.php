<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Bgg\BggCsvImportController;
use App\Http\Controllers\Bgg\BggImportController;
use App\Http\Controllers\Bgg\BggLookupController;
use App\Http\Controllers\Bgg\BggPlaysImportController;
use App\Http\Controllers\Games\CategoryController;
use App\Http\Controllers\Games\GameDescriptionController;
use App\Http\Controllers\Games\GameTranslationBackfillController;
use App\Http\Controllers\Games\MechanicController;
use App\Http\Controllers\Games\PlayController;
use App\Http\Controllers\Games\UserGameController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:6,1');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', fn (Request $request) => $request->user());
    Route::put('/user', [ProfileController::class, 'update']);
    Route::put('/user/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:6,1');

    Route::get('/games', [UserGameController::class, 'index']);
    Route::post('/games', [UserGameController::class, 'store']);
    Route::put('/games/{userGame}', [UserGameController::class, 'update']);
    Route::delete('/games/{userGame}', [UserGameController::class, 'destroy']);
    Route::delete('/games', [UserGameController::class, 'clear']);
    Route::post('/games/{game}/translate-description', [GameDescriptionController::class, 'translate'])->middleware('throttle:20,1');
    // Owner-only - see GameTranslationBackfillController docblock.
    Route::post('/games/backfill-translations', GameTranslationBackfillController::class)->middleware('throttle:2,1');

    Route::get('/mechanics', [MechanicController::class, 'index']);
    Route::get('/categories', [CategoryController::class, 'index']);

    Route::post('/bgg-imports', [BggImportController::class, 'store'])->middleware('throttle:6,1');
    // 30,1 (not the same 6,1 as the routes that actually trigger a BGG
    // request) - the frontend polls this one every 3s while an import is
    // pending, i.e. ~20 legitimate requests/min on its own; a strict 6,1
    // would throttle normal usage, not just abuse.
    Route::get('/bgg-imports/{bggImport}', [BggImportController::class, 'show'])->middleware('throttle:30,1');
    Route::post('/bgg-imports/csv', [BggCsvImportController::class, 'store'])->middleware('throttle:6,1');

    Route::get('/bgg-lookup/games/{bggId}', [BggLookupController::class, 'show'])->middleware('throttle:12,1');

    Route::get('/plays', [PlayController::class, 'index']);
    Route::get('/plays/stats', [PlayController::class, 'stats']);
    Route::post('/bgg-plays-imports', [BggPlaysImportController::class, 'store'])->middleware('throttle:6,1');
});
