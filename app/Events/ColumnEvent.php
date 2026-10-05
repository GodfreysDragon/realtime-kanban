<?php

namespace App\Events;

use App\Http\Resources\ColumnResource;
use App\Models\Column;

/**
 * A board event whose payload is the full, current state of one column.
 */
abstract class ColumnEvent extends BoardEvent
{
    public function __construct(public readonly Column $column)
    {
        parent::__construct($column->board_id);
    }

    public function broadcastWith(): array
    {
        return ['column' => ColumnResource::make($this->column)->resolve()];
    }
}
