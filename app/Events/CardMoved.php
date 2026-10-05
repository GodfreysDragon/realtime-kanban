<?php

namespace App\Events;

class CardMoved extends CardEvent
{
    public function broadcastAs(): string
    {
        return 'card.moved';
    }
}
