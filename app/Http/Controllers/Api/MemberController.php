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

    public function index(Board $board): JsonResponse
    {
        Gate::authorize('view', $board);

        $members = $board->members()->orderByPivot('created_at')->get()
            ->map(fn (User $user) => [...$user->toPresence(), 'role' => $user->pivot->role]);

        return response()->json(['data' => $members]);
    }

    /** Accept an invite link. Joining a board you're already on is a no-op. */
    public function accept(Request $request, string $token): JsonResponse
    {
        $board = Board::where('invite_token', $token)->first();
        abort_if($board === null, Response::HTTP_NOT_FOUND, 'This invite link is no longer valid.');

        if ($board->roleOf($request->user()) === null) {
            $board->addMember($request->user());
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

        $board->members()->detach($user->id);

        // Sent to everyone, the removed member included: their tab is still
        // subscribed and is the one that has to react.
        broadcast(new MemberRemoved($board->id, $user->id));
        $leaving = $request->user()->is($user);

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
