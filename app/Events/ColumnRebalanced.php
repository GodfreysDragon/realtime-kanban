<?php

namespace App\Events;

/**
 * Sent when a column's fractional positions ran out of precision and every
 * card in it was renumbered, so clients must replace their local positions.
 */
class ColumnRebalanced extends BoardEvent
{
    /**
     * @param  array<int, float>  $positions  card id => new position
     */
    public function __construct(int $boardId, public readonly int $columnId, public readonly array $positions)
    {
        parent::__construct($boardId);
    }

    public function broadcastAs(): string
    {
        return 'column.rebalanced';
    }

    public function broadcastWith(): array
    {
        return ['column_id' => $this->columnId, 'positions' => (object) $this->positions];
    }
}
