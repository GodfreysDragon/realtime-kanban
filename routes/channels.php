<?php

use App\Models\Board;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
 * One presence channel per board. Returning an array (rather than true)
 * authorises the subscription *and* becomes the member's info that every
 * other subscriber sees in here(), joining() and leaving().
 *
 * Members may join, and anyone signed in may join a public board: the same
 * rule as the API (BoardPolicy::view), so the live view and the REST
 * snapshot can't disagree about who sees a board.
 */
Broadcast::channel('board.{board}', function (User $user, Board $board) {
    return Gate::forUser($user)->allows('view', $board) ? $user->toPresence() : false;
});
