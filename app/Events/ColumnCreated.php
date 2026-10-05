<?php

namespace App\Events;

class ColumnCreated extends ColumnEvent
{
    public function broadcastAs(): string
    {
        return 'column.created';
    }
}
