<?php

namespace App\Events;

class MemberRemoved extends BoardEvent
{
    public function __construct(int $boardId, public readonly int $userId)
    {
        parent::__construct($boardId);
    }

    public function broadcastAs(): string
    {
        return 'member.removed';
    }

    public function broadcastWith(): array
    {
        return ['user_id' => $this->userId];
    }
}
