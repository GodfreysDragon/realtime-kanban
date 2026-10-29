<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Column;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

/**
 * With the WebSocket server down, a write still succeeds: the change is
 * committed before anything is broadcast, so a 500 would only make the
 * client undo something that happened.
 */
class BroadcastFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A real Reverb broadcaster, pointed at a port nothing listens on.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
        $this->app->make(BroadcastManager::class)->purge();
        Exceptions::fake();
    }

    public function test_writes_succeed_and_report_the_failure_when_reverb_is_down(): void
    {
        $user = User::factory()->create();
        $board = Board::factory()->create();
        $board->addMember($user);
        $column = Column::factory()->for($board)->create();
        $this->actingAs($user);

        $card = $this->postJson("/api/columns/{$column->id}/cards", ['title' => 'Still saved'])
            ->assertCreated()
            ->assertJsonPath('seq', 1)
            ->json('data');
        $this->putJson("/api/cards/{$card['id']}", ['title' => 'Still edited', 'version' => 1])->assertOk();
        $this->deleteJson("/api/cards/{$card['id']}")->assertNoContent();

        $this->assertDatabaseMissing('cards', ['id' => $card['id']]);
        Exceptions::assertReported(BroadcastException::class);
    }
}
