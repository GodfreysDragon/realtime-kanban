<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Contracts\Broadcasting\Factory as Broadcasting;
use Throwable;

/**
 * Server-side control over people's open WebSockets.
 *
 * Channel authorisation only runs when a socket subscribes, so taking
 * someone off a board doesn't stop a socket that is already subscribed.
 * The app's own client leaves when told it was removed, but a modified one
 * could stay and keep receiving the board. Closing the user's connections
 * (Pusher's terminate_connections, which Reverb implements) forces every
 * client to reconnect and subscribe again, and that subscription is checked
 * against the new membership.
 */
class LiveConnections
{
    public function __construct(private readonly Broadcasting $broadcasting) {}

    /**
     * Close every socket the user has open. Their other tabs and boards
     * reconnect within a second and re-sync from a snapshot.
     */
    public function disconnect(User $user): void
    {
        $broadcaster = $this->broadcasting->connection();
        if (! $broadcaster instanceof PusherBroadcaster) {
            return; // the "log" and "null" drivers in development and tests
        }

        try {
            $broadcaster->getPusher()->terminateUserConnections((string) $user->getAuthIdentifier());
        } catch (Throwable $e) {
            // The membership change has already been made; the API refuses
            // the user either way. Don't fail the request over the socket.
            report($e);
        }
    }
}
