<?php

namespace Tests\Feature;

use App\Events\ActivityRecorded;
use App\Events\ColumnMoved;
use App\Events\ColumnsRebalanced;
use App\Models\Board;
use App\Models\Column;
use App\Models\User;
use App\Services\FractionalIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ColumnMoveTest extends TestCase
{
    use RefreshDatabase;

    private Board $board;

    private Column $todo;

    private Column $doing;

    private Column $done;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ColumnMoved::class, ColumnsRebalanced::class, ActivityRecorded::class]);

        $user = User::factory()->create();
        $this->board = Board::factory()->create();
        $this->board->addMember($user);
        $this->todo = Column::factory()->for($this->board)->create(['name' => 'To do', 'position' => 1024]);
        $this->doing = Column::factory()->for($this->board)->create(['name' => 'Doing', 'position' => 2048]);
        $this->done = Column::factory()->for($this->board)->create(['name' => 'Done', 'position' => 3072]);
        $this->actingAs($user);
    }

    public function test_moving_between_two_columns_takes_the_midpoint_and_broadcasts_to_others(): void
    {
        $this->withHeader('X-Socket-ID', '123.456')
            ->patchJson("/api/columns/{$this->done->id}/move", ['after_id' => $this->todo->id])
            ->assertOk()
            ->assertJsonPath('data.position', fn ($position) => $position == 1536);

        $this->getJson("/api/boards/{$this->board->id}")
            ->assertJsonPath('data.columns.*.name', ['To do', 'Done', 'Doing']);

        Event::assertDispatched(ColumnMoved::class, fn (ColumnMoved $e) => $e->column->id === $this->done->id
            && $e->payload()['column']['position'] == 1536
            && $e->socket === '123.456');
        Event::assertNotDispatched(ActivityRecorded::class);
    }

    public function test_moving_to_the_front_halves_the_first_position(): void
    {
        $this->patchJson("/api/columns/{$this->done->id}/move", ['after_id' => null])
            ->assertOk()
            ->assertJsonPath('data.position', fn ($position) => $position == 512);
    }

    public function test_after_id_must_be_a_column_on_the_same_board(): void
    {
        $foreign = Column::factory()->create();

        $this->patchJson("/api/columns/{$this->done->id}/move", ['after_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('after_id');
        $this->patchJson("/api/columns/{$this->done->id}/move", [])->assertJsonValidationErrors('after_id');
    }

    public function test_outsiders_cannot_move_columns(): void
    {
        $this->actingAs(User::factory()->create())
            ->patchJson("/api/columns/{$this->done->id}/move", ['after_id' => null])
            ->assertForbidden();

        $this->assertSame(3072.0, $this->done->fresh()->position);
    }

    public function test_an_exhausted_gap_renumbers_the_columns_for_everyone(): void
    {
        $this->doing->update(['position' => 1024 + 1e-9]);

        $this->patchJson("/api/columns/{$this->done->id}/move", ['after_id' => $this->todo->id])
            ->assertOk()
            ->assertJsonPath('data.position', fn ($position) => $position == 2 * FractionalIndex::GAP);

        $this->assertSame(FractionalIndex::GAP, $this->todo->fresh()->position);
        $this->assertSame(3 * FractionalIndex::GAP, $this->doing->fresh()->position);
        Event::assertDispatched(ColumnsRebalanced::class, fn (ColumnsRebalanced $e) => $e->positions === [
            $this->todo->id => FractionalIndex::GAP,
            $this->doing->id => 3 * FractionalIndex::GAP,
        ] && $e->socket === null);
    }
}
