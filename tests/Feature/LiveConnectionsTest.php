<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LiveConnections;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Pusher\Pusher;
use RuntimeException;
use Tests\TestCase;

class LiveConnectionsTest extends TestCase
{
    public function test_it_asks_the_websocket_server_to_close_the_users_connections(): void
    {
        $user = new User;
        $user->id = 42;

        $pusher = $this->mock(Pusher::class);
        $pusher->shouldReceive('terminateUserConnections')->once()->with('42')->andReturn((object) []);
        $this->usePusher($pusher);

        app(LiveConnections::class)->disconnect($user);
    }

    public function test_a_websocket_server_that_cannot_be_reached_does_not_fail_the_request(): void
    {
        $pusher = $this->mock(Pusher::class);
        $pusher->shouldReceive('terminateUserConnections')->andThrow(new RuntimeException('Connection refused'));
        $this->usePusher($pusher);

        app(LiveConnections::class)->disconnect(new User);

        $this->addToAssertionCount(1);
    }

    public function test_it_does_nothing_without_a_websocket_server(): void
    {
        config(['broadcasting.default' => 'null']);

        app(LiveConnections::class)->disconnect(new User);

        $this->addToAssertionCount(1);
    }

    private function usePusher(Pusher $pusher): void
    {
        Broadcast::extend('fake-pusher', fn () => new PusherBroadcaster($pusher));
        config(['broadcasting.connections.fake' => ['driver' => 'fake-pusher'], 'broadcasting.default' => 'fake']);
    }
}
