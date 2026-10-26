<?php

use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\ColumnController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app')->name('home');

Route::post('join', [AuthController::class, 'join'])->middleware('throttle:10,1')->name('join');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

Route::prefix('api')->name('api.')->group(function () {
    Route::get('me', [AuthController::class, 'me'])->name('me');

    Route::middleware('auth')->group(function () {
        Route::apiResource('boards', BoardController::class)->only(['index', 'store', 'show', 'update']);

        Route::get('boards/{board}/members', [MemberController::class, 'index'])->name('members.index');
        Route::delete('boards/{board}/members/{user}', [MemberController::class, 'destroy'])->name('members.destroy');
        Route::post('boards/{board}/invite', [MemberController::class, 'resetInvite'])->name('invite.reset');
        Route::post('invites/{token}', [MemberController::class, 'accept'])->middleware('throttle:30,1')->name('invite.accept');

        Route::post('boards/{board}/columns', [ColumnController::class, 'store'])->name('columns.store');
        Route::patch('columns/{column}/move', [ColumnController::class, 'move'])->name('columns.move');
        Route::apiResource('columns', ColumnController::class)->only(['update', 'destroy']);

        Route::post('columns/{column}/cards', [CardController::class, 'store'])->name('cards.store');
        Route::patch('cards/{card}/move', [CardController::class, 'move'])->name('cards.move');
        Route::apiResource('cards', CardController::class)->only(['update', 'destroy']);
    });
});
