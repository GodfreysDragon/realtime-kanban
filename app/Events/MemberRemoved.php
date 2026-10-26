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

    public function payload(): array
    {
        return ['user_id' => $this->userId];
    }
}
