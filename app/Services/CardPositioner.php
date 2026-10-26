<?php

namespace App\Services;

use App\Models\Card;

/**
 * Card order within a column, on top of FractionalIndex.
 */
class CardPositioner
{
    public const GAP = FractionalIndex::GAP;

    public const MIN_GAP = FractionalIndex::MIN_GAP;

    public function __construct(private readonly FractionalIndex $index) {}

    /**
     * Work out the position for $card dropped into $columnId directly after
     * $afterId (null = top of the column).
     *
     * @return array{position: float, rebalanced: array<int, float>|null}
     */
    public function placeAfter(Card $card, int $columnId, ?int $afterId): array
    {
        return $this->index->placeAfter(Card::where('column_id', $columnId)->whereKeyNot($card->getKey()), $afterId);
    }

    /**
     * Position for a brand-new card at the bottom of a column.
     */
    public function endOf(int $columnId): float
    {
        return $this->index->endOf(Card::where('column_id', $columnId));
    }
}
