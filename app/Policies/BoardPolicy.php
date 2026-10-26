<?php

namespace App\Policies;

use App\Models\Board;
use App\Models\User;

/**
 * Who may do what on a board. "view" covers everything on the board itself
 * (cards, columns, the live channel); public boards are open to every
 * signed-in user, private ones to their members.
 */
class BoardPolicy
{
    public function view(User $user, Board $board): bool
    {
        return $board->public || $board->roleOf($user) !== null;
    }

    /** Reset the invite link. */
    public function manage(User $user, Board $board): bool
    {
        return $board->roleOf($user) === Board::OWNER;
    }

    /** Owners may remove any other member; members may remove themselves (leave). */
    public function removeMember(User $user, Board $board, User $member): bool
    {
        if ($board->roleOf($member) !== Board::MEMBER) {
            return false;
        }

        return $user->is($member) || $this->manage($user, $board);
    }
}
