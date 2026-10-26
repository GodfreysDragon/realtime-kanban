<?php

namespace App\Http\Controllers\Api;

use App\Events\CardCreated;
use App\Events\CardDeleted;
use App\Events\CardMoved;
use App\Events\CardUpdated;
use App\Events\ColumnRebalanced;
use App\Http\Controllers\Controller;
use App\Http\Requests\MoveCardRequest;
use App\Http\Requests\StoreCardRequest;
use App\Http\Requests\UpdateCardRequest;
use App\Http\Resources\CardResource;
use App\Models\Card;
use App\Models\Column;
use App\Services\BoardActivity;
use App\Services\CardPositioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CardController extends Controller
{
    public function __construct(
        private readonly CardPositioner $positioner,
        private readonly BoardActivity $activity,
    ) {}

    public function store(StoreCardRequest $request, Column $column): JsonResponse
    {
        $card = DB::transaction(function () use ($request, $column) {
            // Same lock as move(): two cards added at once can't read the same
            // max position, and an add can't interleave with a rebalance.
            Column::whereKey($column->id)->lockForUpdate()->first();

            return Card::create([
                ...$request->validated(),
                'board_id' => $column->board_id,
                'column_id' => $column->id,
                'position' => $this->positioner->endOf($column->id),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
        })->refresh();

        broadcast(new CardCreated($card))->toOthers();
        $this->activity->record($card->board_id, $request->user(), 'card.created', "added “{$this->short($card->title)}” to {$column->name}");

        return CardResource::make($card)->response()->setStatusCode(201);
    }

    /**
     * Content edits use optimistic concurrency: the UPDATE only matches the
     * row if its version is still the one the client started editing from.
     * Zero rows affected means someone else saved first, and the client gets
     * a 409 with the current card so it can show both versions.
     */
    public function update(UpdateCardRequest $request, Card $card): JsonResponse
    {
        $expectedVersion = (int) $request->validated('version');
        $changes = $request->safe()->except('version');

        $updated = Card::whereKey($card->id)
            ->where('version', $expectedVersion)
            ->update([
                ...$changes,
                'version' => $expectedVersion + 1,
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);

        $card->refresh();

        if ($updated === 0) {
            return response()->json([
                'message' => 'This card was changed by someone else while you were editing it.',
                'card' => CardResource::make($card)->resolve(),
                'editor' => $card->editor?->toPresence(),
            ], Response::HTTP_CONFLICT);
        }

        broadcast(new CardUpdated($card))->toOthers();
        $this->activity->record($card->board_id, $request->user(), 'card.updated', "edited “{$this->short($card->title)}”");

        return CardResource::make($card)->response();
    }

    /**
     * Moves are last-writer-wins: the server places the card relative to its
     * new neighbour as it finds them now, not as the client saw them.
     */
    public function move(MoveCardRequest $request, Card $card): JsonResponse
    {
        $fromColumn = $card->column;
        $toColumnId = (int) $request->validated('column_id');

        $rebalanced = DB::transaction(function () use ($request, $card, $toColumnId) {
            Column::whereKey($toColumnId)->lockForUpdate()->first();

            $placement = $this->positioner->placeAfter($card, $toColumnId, $request->validated('after_id'));

            $card->update([
                'column_id' => $toColumnId,
                'position' => $placement['position'],
            ]);

            // The route-bound model was read before the transaction, so an edit
            // saved in the meantime isn't in it. Clients drop card events older
            // than the version they hold, so broadcast the row as it is now.
            $card->refresh();

            return $placement['rebalanced'];
        });

        if ($rebalanced !== null) {
            broadcast(new ColumnRebalanced($card->board_id, $toColumnId, $rebalanced));
        }

        broadcast(new CardMoved($card))->toOthers();

        if ($fromColumn->id !== $toColumnId) {
            $toColumn = Column::find($toColumnId);
            $this->activity->record($card->board_id, $request->user(), 'card.moved', "moved “{$this->short($card->title)}” from {$fromColumn->name} to {$toColumn->name}");
        }

        return CardResource::make($card)->response();
    }

    public function destroy(Card $card): Response
    {
        Gate::authorize('view', $card->board);
        $card->delete();

        broadcast(new CardDeleted($card->board_id, $card->id))->toOthers();
        $this->activity->record($card->board_id, request()->user(), 'card.deleted', "deleted “{$this->short($card->title)}”");

        return response()->noContent();
    }

    private function short(string $title): string
    {
        return Str::limit($title, 40);
    }
}
