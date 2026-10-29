<?php

namespace App\Services;

use App\Events\ActivityRecorded;
use App\Models\Activity;
use App\Models\User;

class BoardActivity
{
    /**
     * Persist an entry in the board's feed and push it to everyone watching.
     */
    public function record(int $boardId, ?User $user, string $type, string $description): Activity
    {
        $activity = Activity::create([
            'board_id' => $boardId,
            'user_id' => $user?->id,
            'type' => $type,
            'description' => $description,
        ]);

        (new ActivityRecorded($activity->setRelation('user', $user)))->send(toOthers: false);

        return $activity;
    }
}
