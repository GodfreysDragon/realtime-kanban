<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for everything pushed to a board's presence channel.
 *
 * Broadcasts are sent synchronously (ShouldBroadcastNow) rather than queued:
 * a queue with several workers can deliver "moved" before "created", and
 * clients apply events in arrival order. The HTTP request that caused the
 * change pays the few milliseconds it takes to hand the message to Reverb.
 */
abstract class BoardEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly int $boardId) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("board.{$this->boardId}");
    }

    abstract public function broadcastAs(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function broadcastWith(): array;
}
