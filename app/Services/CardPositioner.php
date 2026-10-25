<?php

namespace App\Services;

use App\Models\Card;

/**
 * Fractional indexing for card order.
 *
 * Each card stores a float position; a card dropped between two neighbours
 * gets the midpoint of theirs, so a move writes exactly one row and two
 * people reordering different cards never fight over a shared index.
 *
 * Repeatedly dropping into the same gap halves it each time. Once a gap is
 * smaller than MIN_GAP the whole column is renumbered (a "rebalance"), and
 * the caller broadcasts the new positions so every client stays in step.
 */
class CardPositioner
{
    public const GAP = 1024.0;

    public const MIN_GAP = 1e-6;

    /**
     * Work out the position for $card dropped into $columnId directly after
     * $afterId (null = top of the column).
     *
     * If $afterId has meanwhile left the column, because someone else moved
     * it, the card goes to the bottom rather than failing the request.
     *
     * @return array{position: float, rebalanced: array<int, float>|null}
     */
    public function placeAfter(Card $card, int $columnId, ?int $afterId): array
    {
        $siblings = Card::query()
            ->where('column_id', $columnId)
            ->whereKeyNot($card->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'position'])
            ->values();

        $index = $this->insertionIndex($siblings->pluck('id')->all(), $afterId);

        $lower = $index > 0 ? $siblings[$index - 1]->position : null;
        $upper = $siblings[$index]->position ?? null;

        $position = match (true) {
            $lower === null && $upper === null => self::GAP,
            $lower === null => $upper / 2,
            $upper === null => $lower + self::GAP,
            default => ($lower + $upper) / 2,
        };

        $gap = $upper === null ? INF : $upper - ($lower ?? 0.0);

        if ($gap >= self::MIN_GAP) {
            return ['position' => $position, 'rebalanced' => null];
        }

        return $this->rebalance($siblings->pluck('id')->all(), $index, $columnId);
    }

    /**
     * Position for a brand-new card at the bottom of a column.
     */
    public function endOf(int $columnId): float
    {
        return (Card::where('column_id', $columnId)->max('position') ?? 0.0) + self::GAP;
    }

    /**
     * @param  array<int, int>  $orderedIds
     */
    private function insertionIndex(array $orderedIds, ?int $afterId): int
    {
        if ($afterId === null) {
            return 0;
        }

        $found = array_search($afterId, $orderedIds, true);

        return $found === false ? count($orderedIds) : $found + 1;
    }

    /**
     * Renumber every sibling at even spacing, leaving the slot at $index free
     * for the card being placed.
     *
     * @param  array<int, int>  $orderedIds
     * @return array{position: float, rebalanced: array<int, float>}
     */
    private function rebalance(array $orderedIds, int $index, int $columnId): array
    {
        $positions = [];

        foreach ($orderedIds as $i => $id) {
            $position = ($i < $index ? $i + 1 : $i + 2) * self::GAP;

            // Scoped to the column: a sibling someone else has just moved out
            // keeps its new place and is left out of the broadcast.
            if (Card::whereKey($id)->where('column_id', $columnId)->update(['position' => $position])) {
                $positions[$id] = $position;
            }
        }

        return ['position' => ($index + 1) * self::GAP, 'rebalanced' => $positions];
    }
}
