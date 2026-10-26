<?php

namespace App\Http\Controllers\Api;

use App\Events\ColumnCreated;
use App\Events\ColumnDeleted;
use App\Events\ColumnUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\ColumnResource;
use App\Models\Board;
use App\Models\Column;
use App\Services\BoardActivity;
use App\Services\CardPositioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ColumnController extends Controller
{
    public function __construct(private readonly BoardActivity $activity) {}

    public function store(Request $request, Board $board): JsonResponse
    {
        Gate::authorize('view', $board);
        $validated = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $column = DB::transaction(function () use ($board, $validated) {
            Board::whereKey($board->id)->lockForUpdate()->first();

            return $board->columns()->create([
                ...$validated,
                'position' => ($board->columns()->max('position') ?? 0.0) + CardPositioner::GAP,
            ]);
        });

        broadcast(new ColumnCreated($column))->toOthers();
        $this->activity->record($board->id, $request->user(), 'column.created', "added column “{$column->name}”");

        return ColumnResource::make($column)->response()->setStatusCode(201);
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
