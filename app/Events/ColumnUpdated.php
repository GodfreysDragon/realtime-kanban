<?php

namespace App\Events;

class ColumnUpdated extends ColumnEvent
{
    public function broadcastAs(): string
    {
        return 'column.updated';
    }
}
