<?php

namespace Tests\Feature;

use App\Events\ActivityRecorded;
use App\Models\Board;
use App\Models\Card;
use App\Models\Column;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Requests that lose a race with someone else's change get a clean answer,
 * not a 500. Each test runs the competing change at the exact moment
 * another request would commit it.
 */
class ConcurrentChangesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Board $board;

    private Column $todo;

    private Column $done;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->board = Board::factory()->create();
        $this->board->addMember($this->user);
        $this->todo = Column::factory()->for($this->board)->create(['position' => 1024]);
        $this->done = Column::factory()->for($this->board)->create(['position' => 2048]);
        $this->actingAs($this->user);
    }

    public function test_adding_a_card_to_a_column_deleted_meanwhile_is_a_404(): void
    {
        // Deleted right after the request has looked the column up.
        $deleted = false;
        Column::retrieved(function (Column $column) use (&$deleted) {
            if ($deleted || $column->id !== $this->todo->id) {
                return;
            }
            $deleted = true;
            DB::table('columns')->where('id', $column->id)->delete();
        });

        $this->postJson("/api/columns/{$this->todo->id}/cards", ['title' => 'Too late'])
            ->assertNotFound()
            ->assertJsonPath('message', 'That column was just deleted.');
        $this->assertSame(0, Card::count());
    }

    public function test_moving_a_card_into_a_column_deleted_meanwhile_is_a_404(): void
    {
        $card = Card::factory()->for($this->todo)->create(['position' => 1024]);

        // Deleted right after validation has checked that the column exists.
        $this->afterQuery('select count(*) as "aggregate" from "columns"', fn () => DB::table('columns')->where('id', $this->done->id)->delete());

        $this->patchJson("/api/cards/{$card->id}/move", ['column_id' => $this->done->id, 'after_id' => null])
            ->assertNotFound();
        $this->assertSame($this->todo->id, $card->fresh()->column_id);
    }

    public function test_accepting_an_invite_someone_just_accepted_for_you_is_a_no_op(): void
    {
        Event::fake([ActivityRecorded::class]);
        $guest = User::factory()->create();

        // The other request (a double click, a second tab) joins first.
        $this->afterQuery('from "boards" where "invite_token"', fn () => DB::table('board_user')->insert([
            'board_id' => $this->board->id, 'user_id' => $guest->id, 'role' => Board::MEMBER,
        ]));

        $this->actingAs($guest)->postJson("/api/invites/{$this->board->invite_token}")->assertOk();

        $this->assertSame(1, DB::table('board_user')->where('user_id', $guest->id)->count());
        Event::assertNotDispatched(ActivityRecorded::class);
    }

    /** Run $write once, straight after the first query whose SQL contains $sql. */
    private function afterQuery(string $sql, callable $write): void
    {
        $done = false;
        DB::listen(function (QueryExecuted $query) use ($sql, $write, &$done) {
            if ($done || ! str_contains($query->sql, $sql)) {
                return;
            }
            $done = true;
            $write();
        });
    }
}
