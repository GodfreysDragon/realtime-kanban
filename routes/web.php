<?php

use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\ColumnController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app')->name('home');

Route::post('join', [AuthController::class, 'join'])->middleware('throttle:10,1')->name('join');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

Route::prefix('api')->name('api.')->group(function () {
    Route::get('me', [AuthController::class, 'me'])->name('me');

    Route::middleware('auth')->group(function () {
        Route::apiResource('boards', BoardController::class)->only(['index', 'store', 'show', 'update']);

        Route::post('boards/{board}/columns', [ColumnController::class, 'store'])->name('columns.store');
        Route::apiResource('columns', ColumnController::class)->only(['update', 'destroy']);

        Route::post('columns/{column}/cards', [CardController::class, 'store'])->name('cards.store');
        Route::patch('cards/{card}/move', [CardController::class, 'move'])->name('cards.move');
        Route::apiResource('cards', CardController::class)->only(['update', 'destroy']);
    });
});
