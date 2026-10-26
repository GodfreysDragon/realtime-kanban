<?php

namespace App\Events;

class ColumnMoved extends ColumnEvent
{
    public function broadcastAs(): string
    {
        return 'column.moved';
    }
}
