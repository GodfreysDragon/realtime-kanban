<?php

namespace App\Events;

use App\Services\BoardSequence;
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
 *
 * Every event takes the board's next sequence number when it is created,
 * which is after the change it describes has been committed. A snapshot
 * that read the board's sequence number before its data therefore already
 * contains every event numbered up to that value; see BoardSequence.
 */
abstract class BoardEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public readonly int $seq;

    public function __construct(public readonly int $boardId)
    {
        $this->seq = app(BoardSequence::class)->next($boardId);
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("board.{$this->boardId}");
    }

    abstract public function broadcastAs(): string;

    /**
     * The event's own data. Every broadcast also carries `seq`.
     *
     * @return array<string, mixed>
     */
    abstract public function payload(): array;

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [...$this->payload(), 'seq' => $this->seq];
    }
}
