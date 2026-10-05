<?php

use App\Models\Board;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * One presence channel per board. Returning an array (rather than true)
 * authorises the subscription *and* becomes the member's info that every
 * other subscriber sees in here(), joining() and leaving().
 *
 * Every signed-in user may open every board; a membership check would go here.
 */
Broadcast::channel('board.{board}', function (User $user, Board $board) {
    return $user->toPresence();
});
