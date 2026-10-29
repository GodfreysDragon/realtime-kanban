<?php

namespace Tests\Feature;

use App\Events\ActivityRecorded;
use App\Events\CardCreated;
use App\Models\Board;
use App\Models\Column;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EventSequenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Board $board;

    private Column $column;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->board = Board::factory()->create();
        $this->board->addMember($this->user);
        $this->column = Column::factory()->for($this->board)->create();
        $this->actingAs($this->user);
    }

    public function test_every_broadcast_carries_the_boards_next_sequence_number(): void
    {
        Event::fake([CardCreated::class, ActivityRecorded::class]);

        $this->postJson("/api/columns/{$this->column->id}/cards", ['title' => 'One'])->assertCreated();
        $this->postJson("/api/columns/{$this->column->id}/cards", ['title' => 'Two'])->assertCreated();

        $seqs = [];
        Event::assertDispatched(CardCreated::class, function (CardCreated $e) use (&$seqs) {
            $seqs[] = $e->broadcastWith()['seq'];

            return true;
        });
        Event::assertDispatched(ActivityRecorded::class, function (ActivityRecorded $e) use (&$seqs) {
            $seqs[] = $e->broadcastWith()['seq'];

            return true;
        });

        sort($seqs);
        $this->assertSame([1, 2, 3, 4], $seqs);
        $this->assertSame(4, $this->board->fresh()->event_seq);
    }

    public function test_boards_are_numbered_independently(): void
    {
        $other = Board::factory()->create(['public' => true]);
        $otherColumn = Column::factory()->for($other)->create();

        $this->postJson("/api/columns/{$this->column->id}/cards", ['title' => 'Here'])->assertCreated();
        $this->postJson("/api/columns/{$otherColumn->id}/cards", ['title' => 'There'])->assertCreated();

        $this->assertSame(2, $this->board->fresh()->event_seq);
        $this->assertSame(2, $other->fresh()->event_seq);
    }

    public function test_the_response_lists_the_sequence_numbers_the_request_broadcast(): void
    {
        // The card.created event skips the sender (toOthers), so without the
        // header the sender would see its number as a gap.
        $this->postJson("/api/columns/{$this->column->id}/cards", ['title' => 'One'])
            ->assertCreated()
            ->assertHeader('X-Board-Events', "{$this->board->id}:1,{$this->board->id}:2");

        $this->postJson("/api/columns/{$this->column->id}/cards", ['title' => 'Two'])
            ->assertHeader('X-Board-Events', "{$this->board->id}:3,{$this->board->id}:4");

        $this->getJson("/api/boards/{$this->board->id}")->assertHeaderMissing('X-Board-Events');
    }

    public function test_the_snapshot_reports_the_sequence_number_it_includes(): void
    {
        $this->postJson("/api/columns/{$this->column->id}/cards", ['title' => 'One'])->assertCreated();

        $this->getJson("/api/boards/{$this->board->id}")
            ->assertOk()
            ->assertJsonPath('data.seq', 2)
            ->assertJsonCount(1, 'data.cards');
    }

    public function test_a_refused_request_broadcasts_nothing_and_uses_no_number(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson("/api/columns/{$this->column->id}/cards", ['title' => 'Nope'])
            ->assertForbidden()
            ->assertHeaderMissing('X-Board-Events');

        $this->assertSame(0, $this->board->fresh()->event_seq);
    }

    public function test_a_write_returns_the_sequence_number_of_its_own_event(): void
    {
        // Clients compare it with the numbers on events about the same card or
        // column, so a slow response can't overwrite a newer change.
        $card = $this->postJson("/api/columns/{$this->column->id}/cards", ['title' => 'One'])
            ->assertCreated()->assertJsonPath('seq', 1)->json('data');

        $this->putJson("/api/cards/{$card['id']}", ['title' => 'Two', 'version' => 1])->assertJsonPath('seq', 3);
        $this->patchJson("/api/cards/{$card['id']}/move", ['column_id' => $this->column->id, 'after_id' => null])->assertJsonPath('seq', 5);

        $column = $this->postJson("/api/boards/{$this->board->id}/columns", ['name' => 'Later'])->assertJsonPath('seq', 6)->json('data');
        $this->putJson("/api/columns/{$column['id']}", ['name' => 'Soon'])->assertJsonPath('seq', 8);
        $this->patchJson("/api/columns/{$column['id']}/move", ['after_id' => null])->assertJsonPath('seq', 10);
    }

    public function test_renaming_the_board_reports_the_current_sequence_number(): void
    {
        // board.renamed and its activity entry take 1 and 2.
        $this->putJson("/api/boards/{$this->board->id}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.seq', 2);
    }
}
