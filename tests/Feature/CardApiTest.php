<?php

namespace Tests\Feature;

use App\Events\ActivityRecorded;
use App\Events\CardCreated;
use App\Events\CardDeleted;
use App\Events\CardMoved;
use App\Events\CardUpdated;
use App\Events\ColumnRebalanced;
use App\Models\Board;
use App\Models\Card;
use App\Models\Column;
use App\Models\User;
use App\Services\CardPositioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CardApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Board $board;

    private Column $todo;

    private Column $done;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        $this->user = User::factory()->create();
        $this->board = Board::factory()->create();
        $this->todo = Column::factory()->for($this->board)->create(['name' => 'To do', 'position' => 1024]);
        $this->done = Column::factory()->for($this->board)->create(['name' => 'Done', 'position' => 2048]);
        $this->board->addMember($this->user);

        $this->actingAs($this->user);
    }

    private function card(Column $column, float $position, array $attributes = []): Card
    {
        return Card::factory()->for($column)->create(['position' => $position, ...$attributes]);
    }

    // --- create ------------------------------------------------------------

    public function test_creating_a_card_appends_it_and_broadcasts_to_other_sockets(): void
    {
        $this->card($this->todo, 3000);

        $response = $this->withHeader('X-Socket-ID', '123.456')
            ->postJson("/api/columns/{$this->todo->id}/cards", ['title' => 'Ship it'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Ship it')
            ->assertJsonPath('data.position', fn ($position) => $position == 3000 + CardPositioner::GAP)
            ->assertJsonPath('data.version', 1);

        Event::assertDispatched(CardCreated::class, function (CardCreated $event) use ($response) {
            return $event->card->id === $response->json('data.id')
                && $event->socket === '123.456'
                && $event->broadcastOn()->name === "presence-board.{$this->board->id}"
                && $event->broadcastAs() === 'card.created'
                && $event->broadcastWith()['card']['title'] === 'Ship it';
        });
        Event::assertDispatched(ActivityRecorded::class, fn ($e) => $e->activity->description === 'added “Ship it” to To do');
    }

    public function test_activity_is_broadcast_to_everyone_including_the_actor(): void
    {
        $this->withHeader('X-Socket-ID', '123.456')->postJson("/api/columns/{$this->todo->id}/cards", ['title' => 'A']);

        Event::assertDispatched(ActivityRecorded::class, fn ($e) => $e->socket === null);
    }

    public function test_card_validation(): void
    {
        $this->postJson("/api/columns/{$this->todo->id}/cards", ['title' => '', 'label' => 'neon'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'label']);
    }

    // --- update / optimistic concurrency ------------------------------------

    public function test_updating_with_the_current_version_saves_and_bumps_the_version(): void
    {
        $card = $this->card($this->todo, 1024, ['title' => 'Old']);

        $this->putJson("/api/cards/{$card->id}", ['title' => 'New', 'label' => 'red', 'version' => 1])
            ->assertOk()
            ->assertJsonPath('data.title', 'New')
            ->assertJsonPath('data.label', 'red')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.updated_by', $this->user->id);

        Event::assertDispatched(CardUpdated::class, fn ($e) => $e->card->version === 2);
    }

    public function test_a_stale_version_is_rejected_with_the_current_card(): void
    {
        $card = $this->card($this->todo, 1024, ['title' => 'Original']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $this->board->addMember($bob);

        // Bob saves first...
        $this->actingAs($bob)->putJson("/api/cards/{$card->id}", ['title' => 'Bob’s title', 'version' => 1])->assertOk();

        // ...then I save from the version I opened (1).
        $this->actingAs($this->user)
            ->putJson("/api/cards/{$card->id}", ['title' => 'My title', 'version' => 1])
            ->assertConflict()
            ->assertJsonPath('card.title', 'Bob’s title')
            ->assertJsonPath('card.version', 2)
            ->assertJsonPath('editor.name', 'Bob');

        $this->assertSame('Bob’s title', $card->fresh()->title);
        Event::assertDispatchedTimes(CardUpdated::class, 1);
    }

    public function test_overwriting_after_a_conflict_uses_the_new_version(): void
    {
        $card = $this->card($this->todo, 1024);
        $card->update(['version' => 2]);

        $this->putJson("/api/cards/{$card->id}", ['title' => 'Mine wins', 'version' => 2])
            ->assertOk()
            ->assertJsonPath('data.version', 3);
    }

    public function test_description_can_be_cleared(): void
    {
        $card = $this->card($this->todo, 1024, ['description' => 'Something']);

        $this->putJson("/api/cards/{$card->id}", ['description' => null, 'version' => 1])->assertOk();

        $this->assertNull($card->fresh()->description);
    }

    // --- move -----------------------------------------------------------------

    public function test_moving_between_two_cards_takes_the_midpoint(): void
    {
        $a = $this->card($this->done, 1000);
        $this->card($this->done, 2000);
        $moving = $this->card($this->todo, 1000);

        $this->patchJson("/api/cards/{$moving->id}/move", ['column_id' => $this->done->id, 'after_id' => $a->id])
            ->assertOk()
            ->assertJsonPath('data.column_id', $this->done->id)
            ->assertJsonPath('data.position', fn ($position) => $position == 1500);

        Event::assertDispatched(CardMoved::class);
        Event::assertDispatched(ActivityRecorded::class, fn ($e) => str_contains($e->activity->description, 'from To do to Done'));
    }

    public function test_moving_to_the_top_halves_the_first_position(): void
    {
        $this->card($this->done, 1000);
        $moving = $this->card($this->todo, 1000);

        $this->patchJson("/api/cards/{$moving->id}/move", ['column_id' => $this->done->id, 'after_id' => null])
            ->assertJsonPath('data.position', fn ($position) => $position == 500);
    }

    public function test_moving_into_an_empty_column(): void
    {
        $moving = $this->card($this->todo, 1000);

        $this->patchJson("/api/cards/{$moving->id}/move", ['column_id' => $this->done->id, 'after_id' => null])
            ->assertJsonPath('data.position', fn ($position) => $position == CardPositioner::GAP);
    }

    public function test_reordering_within_a_column_does_not_log_activity(): void
    {
        $a = $this->card($this->todo, 1000);
        $this->card($this->todo, 2000);
        $c = $this->card($this->todo, 3000);

        $this->patchJson("/api/cards/{$a->id}/move", ['column_id' => $this->todo->id, 'after_id' => $c->id])
            ->assertJsonPath('data.position', fn ($position) => $position == 3000 + CardPositioner::GAP);

        Event::assertDispatched(CardMoved::class);
        Event::assertNotDispatched(ActivityRecorded::class);
    }

    public function test_when_the_anchor_card_has_moved_away_the_card_goes_to_the_bottom(): void
    {
        // The client saw `anchor` in Done, but someone has since moved it to To do.
        $anchor = $this->card($this->todo, 1000);
        $this->card($this->done, 1000);
        $this->card($this->done, 2000);
        $moving = $this->card($this->todo, 2000);

        $this->patchJson("/api/cards/{$moving->id}/move", ['column_id' => $this->done->id, 'after_id' => $anchor->id])
            ->assertOk()
            ->assertJsonPath('data.position', fn ($position) => $position == 2000 + CardPositioner::GAP);
    }

    public function test_exhausted_gaps_trigger_a_broadcast_rebalance(): void
    {
        $a = $this->card($this->done, 1.0);
        $b = $this->card($this->done, 1.0 + 1e-9);
        $moving = $this->card($this->todo, 1000);

        $this->patchJson("/api/cards/{$moving->id}/move", ['column_id' => $this->done->id, 'after_id' => $a->id])
            ->assertOk()
            ->assertJsonPath('data.position', fn ($position) => $position == 2 * CardPositioner::GAP);

        $this->assertSame(CardPositioner::GAP, $a->fresh()->position);
        $this->assertSame(3 * CardPositioner::GAP, $b->fresh()->position);

        Event::assertDispatched(ColumnRebalanced::class, function (ColumnRebalanced $event) use ($a, $b) {
            return $event->columnId === $this->done->id
                && $event->positions === [$a->id => CardPositioner::GAP, $b->id => 3 * CardPositioner::GAP]
                && $event->socket === null;
        });
    }

    public function test_a_move_broadcasts_an_edit_saved_while_it_was_in_flight(): void
    {
        $moving = $this->card($this->todo, 1000, ['title' => 'Before']);
        $this->interleaveOnRetrieve($moving->id, fn () => Card::whereKey($moving->id)->update(['title' => 'After', 'version' => 2]));

        $this->patchJson("/api/cards/{$moving->id}/move", ['column_id' => $this->done->id, 'after_id' => null])
            ->assertOk()
            ->assertJsonPath('data.title', 'After')
            ->assertJsonPath('data.version', 2);

        // Clients ignore card events older than their copy, so a move carrying
        // the stale version 1 would be dropped by everyone who saw the edit.
        Event::assertDispatched(CardMoved::class, fn (CardMoved $event) => $event->card->version === 2
            && $event->card->title === 'After'
            && $event->card->column_id === $this->done->id);
    }

    public function test_a_rebalance_leaves_a_card_that_was_moved_out_concurrently_alone(): void
    {
        $a = $this->card($this->done, 1.0);
        $b = $this->card($this->done, 1.0 + 1e-9);
        $moving = $this->card($this->todo, 1000);
        $this->interleaveOnRetrieve($b->id, fn () => Card::whereKey($b->id)->update(['column_id' => $this->todo->id, 'position' => 5000]));

        $this->patchJson("/api/cards/{$moving->id}/move", ['column_id' => $this->done->id, 'after_id' => $a->id])
            ->assertOk();

        $this->assertSame(5000.0, $b->fresh()->position);
        Event::assertDispatched(ColumnRebalanced::class, fn (ColumnRebalanced $event) => $event->positions === [$a->id => CardPositioner::GAP]);
    }

    /**
     * Run $write the first time card $id is read from the database, standing
     * in for another request that commits between our read and our write.
     */
    private function interleaveOnRetrieve(int $id, callable $write): void
    {
        Event::fakeExcept('eloquent.retrieved: '.Card::class);

        $done = false;
        Card::retrieved(function (Card $card) use ($id, $write, &$done) {
            if ($done || $card->id !== $id) {
                return;
            }
            $done = true;
            $write();
        });
    }

    public function test_cannot_move_a_card_to_another_boards_column(): void
    {
        $moving = $this->card($this->todo, 1000);
        $foreign = Column::factory()->create();

        $this->patchJson("/api/cards/{$moving->id}/move", ['column_id' => $foreign->id, 'after_id' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('column_id');
    }

    public function test_repeated_drops_into_the_same_gap_keep_a_strict_order(): void
    {
        $top = $this->card($this->done, 1024);
        $bottom = $this->card($this->done, 2048);

        // Keep dropping a new card directly below `top`: each lands between
        // `top` and the previous one, halving the gap until a rebalance.
        for ($i = 0; $i < 60; $i++) {
            $card = $this->card($this->todo, 1000);
            $this->patchJson("/api/cards/{$card->id}/move", ['column_id' => $this->done->id, 'after_id' => $top->id])->assertOk();
        }

        $order = Card::where('column_id', $this->done->id)->orderBy('position')->pluck('id');
        $this->assertSame($top->id, $order->first());
        $this->assertSame($bottom->id, $order->last());
        $this->assertSame($order->count(), Card::where('column_id', $this->done->id)->distinct()->count('position'));
        Event::assertDispatched(ColumnRebalanced::class);
    }

    // --- delete ---------------------------------------------------------------

    public function test_deleting_a_card_broadcasts_its_id(): void
    {
        $card = $this->card($this->todo, 1024);

        $this->deleteJson("/api/cards/{$card->id}")->assertNoContent();

        $this->assertModelMissing($card);
        Event::assertDispatched(CardDeleted::class, fn ($e) => $e->broadcastWith() === ['id' => $card->id] && $e->broadcastAs() === 'card.deleted');
    }
}
