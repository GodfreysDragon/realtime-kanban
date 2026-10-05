<?php

namespace App\Events;

use App\Http\Resources\ActivityResource;
use App\Models\Activity;

/**
 * Unlike the other board events this one goes to everyone, including the
 * user who caused it, so every feed renders from the same source.
 */
class ActivityRecorded extends BoardEvent
{
    public function __construct(public readonly Activity $activity)
    {
        parent::__construct($activity->board_id);
    }

    public function broadcastAs(): string
    {
        return 'activity.recorded';
    }

    public function broadcastWith(): array
    {
        return ['activity' => ActivityResource::make($this->activity->loadMissing('user'))->resolve()];
    }
}
