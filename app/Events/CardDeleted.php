<?php

namespace App\Events;

class CardDeleted extends BoardEvent
{
    public function __construct(int $boardId, public readonly int $cardId)
    {
        parent::__construct($boardId);
    }

    public function broadcastAs(): string
    {
        return 'card.deleted';
    }

    public function payload(): array
    {
        return ['id' => $this->cardId];
    }
}
