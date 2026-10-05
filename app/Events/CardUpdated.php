<?php

namespace App\Events;

class CardUpdated extends CardEvent
{
    public function broadcastAs(): string
    {
        return 'card.updated';
    }
}
