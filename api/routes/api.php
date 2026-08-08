<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Games\CategoryController;
use App\Http\Controllers\Games\MechanicController;
use App\Http\Controllers\Games\UserGameController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', fn (Request $request) => $request->user());

    Route::get('/games', [UserGameController::class, 'index']);
    Route::post('/games', [UserGameController::class, 'store']);
    Route::put('/games/{userGame}', [UserGameController::class, 'update']);
    Route::delete('/games/{userGame}', [UserGameController::class, 'destroy']);

    Route::get('/mechanics', [MechanicController::class, 'index']);
    Route::get('/categories', [CategoryController::class, 'index']);
});
