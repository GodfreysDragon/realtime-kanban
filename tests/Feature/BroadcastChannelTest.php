<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the real /broadcasting/auth endpoint with the Reverb (Pusher
 * protocol) broadcaster. Signing happens locally, so no server is needed.
 */
class BroadcastChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml uses the null broadcaster, which authorises nothing.
        // Switch to Reverb and re-register the channel callbacks on it.
        config(['broadcasting.default' => 'reverb']);
        $this->app->make(BroadcastManager::class)->purge();
        require base_path('routes/channels.php');
    }

    public function test_members_get_a_signed_presence_payload_with_their_public_info(): void
    {
        $user = User::factory()->create(['name' => 'Ada', 'color' => '#22c55e']);
        $board = Board::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "presence-board.{$board->id}"])
            ->assertOk()
            ->assertJsonStructure(['auth', 'channel_data']);

        $channelData = json_decode($response->json('channel_data'), true);
        $this->assertEquals($user->id, $channelData['user_id']);
        $this->assertSame(['id' => $user->id, 'name' => 'Ada', 'color' => '#22c55e'], $channelData['user_info']);
        $this->assertStringStartsWith(config('broadcasting.connections.reverb.key').':', $response->json('auth'));
    }

    public function test_guests_cannot_join_a_board_channel(): void
    {
        $board = Board::factory()->create();

        $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "presence-board.{$board->id}"])
            ->assertForbidden();
    }

    public function test_unknown_boards_cannot_be_joined(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'presence-board.999'])
            ->assertForbidden();
    }
}
