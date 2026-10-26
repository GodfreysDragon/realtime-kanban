<?php

namespace App\Events;

/**
 * Sent when the board's column positions ran out of precision and every
 * column was renumbered, so clients must replace their local positions.
 */
class ColumnsRebalanced extends BoardEvent
{
    /**
     * @param  array<int, float>  $positions  column id => new position
     */
    public function __construct(int $boardId, public readonly array $positions)
    {
        parent::__construct($boardId);
    }

    public function broadcastAs(): string
    {
        return 'columns.rebalanced';
    }

    public function payload(): array
    {
        return ['positions' => (object) $this->positions];
    }
}
