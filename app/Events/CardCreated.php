<?php

namespace App\Events;

class CardCreated extends CardEvent
{
    public function broadcastAs(): string
    {
        return 'card.created';
    }
}
