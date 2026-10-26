<?php

namespace Tests\Feature;

use App\Events\ActivityRecorded;
use App\Events\BoardRenamed;
use App\Events\ColumnCreated;
use App\Events\ColumnDeleted;
use App\Events\ColumnUpdated;
use App\Http\Controllers\Api\BoardController;
use App\Models\Board;
use App\Models\Card;
use App\Models\Column;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BoardApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_creating_a_board_adds_the_default_columns_in_order(): void
    {
        Event::fake();

        $id = $this->postJson('/api/boards', ['name' => 'Launch'])->assertCreated()->json('data.id');

        $board = Board::findOrFail($id);
        $this->assertSame(BoardController::DEFAULT_COLUMNS, $board->columns->pluck('name')->all());
        $this->assertSame($this->user->id, $board->created_by);
        Event::assertDispatched(ActivityRecorded::class, fn ($e) => $e->activity->type === 'board.created');
    }

    public function test_index_lists_boards_with_card_counts(): void
    {
        $column = Column::factory()->create();
        $column->board->addMember($this->user);
        Card::factory()->count(2)->for($column)->create();

        $this->getJson('/api/boards')
            ->assertOk()
            ->assertJsonPath('data.0.id', $column->board_id)
            ->assertJsonPath('data.0.cards_count', 2);
    }

    public function test_show_returns_the_full_snapshot(): void
    {
        $board = Board::factory()->create();
        $board->addMember($this->user);
        $todo = Column::factory()->for($board)->create(['name' => 'To do', 'position' => 1]);
        Column::factory()->for($board)->create(['name' => 'Done', 'position' => 2]);
        $card = Card::factory()->for($todo)->create(['title' => 'Write tests']);

        $this->getJson("/api/boards/{$board->id}")
            ->assertOk()
            ->assertJsonPath('data.name', $board->name)
            ->assertJsonPath('data.columns.0.name', 'To do')
            ->assertJsonPath('data.columns.1.name', 'Done')
            ->assertJsonPath('data.cards.0.id', $card->id)
            ->assertJsonPath('data.cards.0.version', 1)
            ->assertJsonStructure(['data' => ['activities']]);
    }

    public function test_show_returns_404_for_unknown_boards(): void
    {
        $this->getJson('/api/boards/999')->assertNotFound();
    }

    public function test_renaming_a_board_broadcasts_to_others(): void
    {
        Event::fake();
        $board = Board::factory()->create();
        $board->addMember($this->user);

        $this->putJson("/api/boards/{$board->id}", ['name' => 'Renamed'])->assertOk();

        $this->assertSame('Renamed', $board->fresh()->name);
        Event::assertDispatched(BoardRenamed::class, fn ($e) => $e->payload() === ['name' => 'Renamed']);
    }

    public function test_columns_can_be_added_renamed_and_deleted(): void
    {
        Event::fake();
        $board = Board::factory()->create();
        $board->addMember($this->user);
        Column::factory()->for($board)->create(['position' => 5000]);

        $columnId = $this->postJson("/api/boards/{$board->id}/columns", ['name' => 'Review'])
            ->assertCreated()
            ->assertJsonPath('data.position', 5000 + 1024)
            ->json('data.id');
        Event::assertDispatched(ColumnCreated::class);

        $this->putJson("/api/columns/{$columnId}", ['name' => 'QA'])->assertOk()->assertJsonPath('data.name', 'QA');
        Event::assertDispatched(ColumnUpdated::class, fn ($e) => $e->column->name === 'QA');

        Card::factory()->for(Column::find($columnId))->create();
        $this->deleteJson("/api/columns/{$columnId}")->assertNoContent();

        $this->assertDatabaseMissing('columns', ['id' => $columnId]);
        $this->assertSame(0, Card::where('column_id', $columnId)->count());
        Event::assertDispatched(ColumnDeleted::class, fn ($e) => $e->payload() === ['id' => $columnId]);
        Event::assertDispatched(ActivityRecorded::class, fn ($e) => str_contains($e->activity->description, 'and its 1 card'));
    }
}
