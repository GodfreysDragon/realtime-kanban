<?php

namespace App\Events;

use App\Models\Board;

class BoardRenamed extends BoardEvent
{
    public function __construct(public readonly Board $board)
    {
        parent::__construct($board->id);
    }

    public function broadcastAs(): string
    {
        return 'board.renamed';
    }

    public function payload(): array
    {
        return ['name' => $this->board->name];
    }
}
