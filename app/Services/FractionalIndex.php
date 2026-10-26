<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Fractional indexing: the order of cards in a column, and of columns on a
 * board.
 *
 * Each row stores a float position; a row dropped between two neighbours
 * gets the midpoint of theirs, so a move writes exactly one row and two
 * people reordering different rows never fight over a shared index.
 *
 * Repeatedly dropping into the same gap halves it each time. Once a gap is
 * smaller than MIN_GAP the whole list is renumbered (a "rebalance"), and the
 * caller broadcasts the new positions so every client stays in step.
 *
 * The list is given as a query for the *other* rows in it (the moving row
 * excluded), scoped to the list: every write goes through that same query,
 * so a row that has meanwhile left the list is never renumbered into it.
 */
class FractionalIndex
{
    public const GAP = 1024.0;

    public const MIN_GAP = 1e-6;

    /**
     * Where a row goes when dropped directly after $afterId (null = first).
     *
     * If $afterId has meanwhile left the list, because someone else moved
     * it, the row goes to the end rather than failing the request.
     *
     * @param  Builder<Model>  $siblings
     * @return array{position: float, rebalanced: array<int, float>|null}
     */
    public function placeAfter(Builder $siblings, ?int $afterId): array
    {
        $rows = (clone $siblings)->orderBy('position')->orderBy('id')->get(['id', 'position'])->values();

        $index = $this->insertionIndex($rows->pluck('id')->all(), $afterId);

        $lower = $index > 0 ? $rows[$index - 1]->position : null;
        $upper = $rows[$index]->position ?? null;

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

        return $this->rebalance($siblings, $rows->pluck('id')->all(), $index);
    }

    /**
     * Position for a new row at the end of the list.
     *
     * @param  Builder<Model>  $siblings
     */
    public function endOf(Builder $siblings): float
    {
        return ((clone $siblings)->max('position') ?? 0.0) + self::GAP;
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
     * for the row being placed.
     *
     * @param  Builder<Model>  $siblings
     * @param  array<int, int>  $orderedIds
     * @return array{position: float, rebalanced: array<int, float>}
     */
    private function rebalance(Builder $siblings, array $orderedIds, int $index): array
    {
        $positions = [];

        foreach ($orderedIds as $i => $id) {
            $position = ($i < $index ? $i + 1 : $i + 2) * self::GAP;

            // Through the scoped query: a sibling someone else has just moved
            // out keeps its new place and is left out of the broadcast.
            if ((clone $siblings)->whereKey($id)->update(['position' => $position])) {
                $positions[$id] = $position;
            }
        }

        return ['position' => ($index + 1) * self::GAP, 'rebalanced' => $positions];
    }
}
