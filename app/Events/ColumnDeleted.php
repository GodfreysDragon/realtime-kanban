<?php

namespace App\Events;

class ColumnDeleted extends BoardEvent
{
    public function __construct(int $boardId, public readonly int $columnId)
    {
        parent::__construct($boardId);
    }

    public function broadcastAs(): string
    {
        return 'column.deleted';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->columnId];
    }
}
