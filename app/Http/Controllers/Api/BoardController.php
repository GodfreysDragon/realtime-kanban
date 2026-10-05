<?php

namespace App\Http\Controllers\Api;

use App\Events\BoardRenamed;
use App\Http\Controllers\Controller;
use App\Http\Resources\BoardResource;
use App\Models\Board;
use App\Services\BoardActivity;
use App\Services\CardPositioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class BoardController extends Controller
{
    public const DEFAULT_COLUMNS = ['To do', 'In progress', 'Done'];

    public function __construct(private readonly BoardActivity $activity) {}

    public function index(): AnonymousResourceCollection
    {
        return BoardResource::collection(Board::withCount('cards')->latest('updated_at')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:80']]);

        $board = DB::transaction(function () use ($validated, $request) {
            $board = Board::create([...$validated, 'created_by' => $request->user()->id]);

            foreach (self::DEFAULT_COLUMNS as $i => $name) {
                $board->columns()->create(['name' => $name, 'position' => ($i + 1) * CardPositioner::GAP]);
            }

            return $board;
        });

        $this->activity->record($board->id, $request->user(), 'board.created', 'created the board');

        return BoardResource::make($board)->response()->setStatusCode(201);
    }

    /**
     * The full snapshot a client renders from. Clients also re-fetch this
     * after a WebSocket reconnect, since events sent while they were offline
     * are not replayed.
     */
    public function show(Board $board): BoardResource
    {
        return BoardResource::make($board->load([
            'columns',
            'cards',
            'activities' => fn ($query) => $query->with('user')->limit(30),
        ]));
    }

    public function update(Request $request, Board $board): BoardResource
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:80']]);

        $board->update($validated);

        broadcast(new BoardRenamed($board))->toOthers();
        $this->activity->record($board->id, $request->user(), 'board.renamed', "renamed the board to “{$board->name}”");

        return BoardResource::make($board);
    }
}
