<?php

namespace App\Http\Controllers\Api;

use App\Events\ColumnCreated;
use App\Events\ColumnDeleted;
use App\Events\ColumnMoved;
use App\Events\ColumnsRebalanced;
use App\Events\ColumnUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\MoveColumnRequest;
use App\Http\Resources\ColumnResource;
use App\Models\Board;
use App\Models\Column;
use App\Services\BoardActivity;
use App\Services\FractionalIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ColumnController extends Controller
{
    public function __construct(
        private readonly BoardActivity $activity,
        private readonly FractionalIndex $index,
    ) {}

    public function store(Request $request, Board $board): JsonResponse
    {
        Gate::authorize('view', $board);
        $validated = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $column = DB::transaction(function () use ($board, $validated) {
            Board::whereKey($board->id)->lockForUpdate()->first();

            return $board->columns()->create([
                ...$validated,
                'position' => $this->index->endOf(Column::where('board_id', $board->id)),
            ]);
        });

        broadcast(new ColumnCreated($column))->toOthers();
        $this->activity->record($board->id, $request->user(), 'column.created', "added column “{$column->name}”");

        return ColumnResource::make($column)->response()->setStatusCode(201);
    }

    /**
     * Reorder: the same fractional indexing as cards, with the board row as
     * the lock (column creates take it too). Like a card reorder within a
     * column, this isn't logged in the activity feed.
     */
    public function move(MoveColumnRequest $request, Column $column): ColumnResource
    {
        $rebalanced = DB::transaction(function () use ($request, $column) {
            Board::whereKey($column->board_id)->lockForUpdate()->first();

            $placement = $this->index->placeAfter(
                Column::where('board_id', $column->board_id)->whereKeyNot($column->id),
                $request->validated('after_id'),
            );
            $column->update(['position' => $placement['position']]);
            $column->refresh();

            return $placement['rebalanced'];
        });

        if ($rebalanced !== null) {
            broadcast(new ColumnsRebalanced($column->board_id, $rebalanced));
        }

        broadcast(new ColumnMoved($column))->toOthers();

        return ColumnResource::make($column);
    }

    public function update(Request $request, Column $column): ColumnResource
    {
        Gate::authorize('view', $column->board);
        $validated = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $previousName = $column->name;

        $column->update($validated);

        broadcast(new ColumnUpdated($column))->toOthers();
        $this->activity->record($column->board_id, $request->user(), 'column.renamed', "renamed column “{$previousName}” to “{$column->name}”");

        return ColumnResource::make($column);
    }

    public function destroy(Request $request, Column $column): Response
    {
        Gate::authorize('view', $column->board);
        $cardCount = $column->cards()->count();
        $column->delete();

        broadcast(new ColumnDeleted($column->board_id, $column->id))->toOthers();
        $this->activity->record(
            $column->board_id,
            $request->user(),
            'column.deleted',
            "deleted column “{$column->name}”".($cardCount ? " and its {$cardCount} ".str('card')->plural($cardCount) : ''),
        );

        return response()->noContent();
    }
}
