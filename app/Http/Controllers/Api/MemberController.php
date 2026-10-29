<?php

namespace App\Http\Controllers\Api;

use App\Events\MemberRemoved;
use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\User;
use App\Services\BoardActivity;
use App\Services\LiveConnections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Board membership. People join through the board's invite link; the owner
 * can reset that link (old links stop working) and remove members, and a
 * member can leave.
 */
class MemberController extends Controller
{
    public function __construct(
        private readonly BoardActivity $activity,
        private readonly LiveConnections $connections,
    ) {}

    public function index(Request $request, Board $board): JsonResponse
    {
        Gate::authorize('view', $board);

        $members = $board->members()->orderByPivot('created_at')->get()
            ->map(fn (User $user) => [...$user->toPresence(), 'role' => $user->pivot->role]);

        // The current link, for members: it changes when someone is removed,
        // and the Share dialog shouldn't offer one that no longer works.
        $isMember = $members->contains('id', $request->user()->id);

        return response()->json(['data' => $members, 'invite_token' => $isMember ? $board->invite_token : null]);
    }

    /**
     * Accept an invite link. Joining a board you're already on is a no-op,
     * also when two requests (a double click, two tabs) race to join.
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        $board = Board::where('invite_token', $token)->first();
        abort_if($board === null, Response::HTTP_NOT_FOUND, 'This invite link is no longer valid.');

        $joined = DB::table('board_user')->insertOrIgnore([
            'board_id' => $board->id,
            'user_id' => $request->user()->id,
            'role' => Board::MEMBER,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($joined) {
            $this->activity->record($board->id, $request->user(), 'member.joined', 'joined the board');
        }

        return response()->json(['data' => ['board_id' => $board->id]]);
    }

    public function resetInvite(Board $board): JsonResponse
    {
        Gate::authorize('manage', $board);

        $board->forceFill(['invite_token' => Board::newInviteToken()])->save();

        return response()->json(['data' => ['invite_token' => $board->invite_token]]);
    }

    public function destroy(Request $request, Board $board, User $user): Response
    {
        Gate::authorize('removeMember', [$board, $user]);

        $leaving = $request->user()->is($user);

        DB::transaction(function () use ($board, $user, $leaving) {
            $board->members()->detach($user->id);

            // Every member's snapshot included the invite link, so the
            // removed person still has it. Replace it, or they could simply
            // join again. (Leaving by choice keeps the link: they could have
            // been invited back anyway.)
            if (! $leaving) {
                $board->forceFill(['invite_token' => Board::newInviteToken()])->save();
            }
        });

        // Sent to everyone, the removed member included: their tab is still
        // subscribed and is the one that has to react.
        (new MemberRemoved($board->id, $user->id))->send(toOthers: false);

        // Their subscription was authorised while they were a member. Close
        // their sockets so a client that ignores the event above can't stay
        // subscribed; subscribing again is refused. Nothing to protect on a
        // public board, or when someone leaves by choice.
        if (! $leaving && ! $board->public) {
            $this->connections->disconnect($user);
        }
        $this->activity->record($board->id, $request->user(), 'member.removed', $leaving ? 'left the board' : "removed {$user->name} from the board");

        return response()->noContent();
    }
}
