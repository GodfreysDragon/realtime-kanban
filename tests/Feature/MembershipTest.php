<?php

namespace Tests\Feature;

use App\Events\ActivityRecorded;
use App\Events\MemberRemoved;
use App\Models\Board;
use App\Models\Card;
use App\Models\Column;
use App\Models\User;
use App\Services\LiveConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private User $outsider;

    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();

        // Only the broadcasts: faking every event would also switch off the
        // model hook that gives each board its invite token.
        Event::fake([ActivityRecorded::class, MemberRemoved::class]);

        $this->owner = User::factory()->create(['name' => 'Ada']);
        $this->member = User::factory()->create(['name' => 'Bob']);
        $this->outsider = User::factory()->create(['name' => 'Eve']);

        $this->board = Board::factory()->create(['created_by' => $this->owner->id]);
        $this->board->addMember($this->owner, Board::OWNER);
        $this->board->addMember($this->member);
    }

    public function test_the_creator_of_a_board_is_its_owner_and_sees_the_invite_link(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/boards', ['name' => 'Launch'])->assertCreated()->json('data.id');

        $this->getJson("/api/boards/{$id}")
            ->assertOk()
            ->assertJsonPath('data.role', Board::OWNER)
            ->assertJsonPath('data.public', false)
            ->assertJsonPath('data.invite_token', Board::find($id)->invite_token);
    }

    public function test_the_board_list_shows_only_boards_you_belong_to_and_public_ones(): void
    {
        $public = Board::factory()->create(['public' => true]);

        $ids = $this->actingAs($this->outsider)->getJson('/api/boards')->assertOk()->json('data.*.id');
        $this->assertSame([$public->id], $ids);

        $ids = $this->actingAs($this->member)->getJson('/api/boards')->json('data.*.id');
        $this->assertEqualsCanonicalizing([$public->id, $this->board->id], $ids);
    }

    public function test_outsiders_are_refused_by_every_board_endpoint(): void
    {
        $column = Column::factory()->for($this->board)->create();
        $card = Card::factory()->for($column)->create();
        $this->actingAs($this->outsider);

        $this->getJson("/api/boards/{$this->board->id}")->assertForbidden();
        $this->putJson("/api/boards/{$this->board->id}", ['name' => 'Mine now'])->assertForbidden();
        $this->getJson("/api/boards/{$this->board->id}/members")->assertForbidden();
        $this->postJson("/api/boards/{$this->board->id}/columns", ['name' => 'X'])->assertForbidden();
        $this->putJson("/api/columns/{$column->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/columns/{$column->id}")->assertForbidden();
        $this->postJson("/api/columns/{$column->id}/cards", ['title' => 'X'])->assertForbidden();
        $this->putJson("/api/cards/{$card->id}", ['title' => 'X', 'version' => 1])->assertForbidden();
        $this->patchJson("/api/cards/{$card->id}/move", ['column_id' => $column->id, 'after_id' => null])->assertForbidden();
        $this->deleteJson("/api/cards/{$card->id}")->assertForbidden();

        // Authorisation runs before validation, so an invalid request doesn't
        // tell an outsider anything either.
        $this->postJson("/api/columns/{$column->id}/cards", [])->assertForbidden();

        $this->assertSame($card->title, $card->fresh()->title);
        Event::assertNotDispatched(ActivityRecorded::class);
    }

    public function test_anyone_signed_in_can_use_a_public_board_but_only_members_see_its_invite_link(): void
    {
        $this->board->update(['public' => true]);

        $this->actingAs($this->outsider)
            ->getJson("/api/boards/{$this->board->id}")
            ->assertOk()
            ->assertJsonPath('data.role', null)
            ->assertJsonMissingPath('data.invite_token');

        $this->postJson("/api/boards/{$this->board->id}/columns", ['name' => 'Ideas'])->assertCreated();
    }

    public function test_an_invite_link_adds_you_as_a_member_once(): void
    {
        $token = $this->board->invite_token;

        $this->actingAs($this->outsider)
            ->postJson("/api/invites/{$token}")
            ->assertOk()
            ->assertJsonPath('data.board_id', $this->board->id);
        $this->postJson("/api/invites/{$token}")->assertOk();

        $this->assertSame(Board::MEMBER, $this->board->roleOf($this->outsider));
        Event::assertDispatchedTimes(ActivityRecorded::class, 1);
        $this->getJson("/api/boards/{$this->board->id}")->assertOk();
    }

    public function test_an_unknown_invite_link_is_rejected(): void
    {
        $this->actingAs($this->outsider)->postJson('/api/invites/not-a-token')->assertNotFound();
    }

    public function test_the_owner_can_reset_the_invite_link_and_the_old_one_stops_working(): void
    {
        $old = $this->board->invite_token;

        $this->actingAs($this->member)->postJson("/api/boards/{$this->board->id}/invite")->assertForbidden();

        $new = $this->actingAs($this->owner)->postJson("/api/boards/{$this->board->id}/invite")->assertOk()->json('data.invite_token');
        $this->assertNotSame($old, $new);

        $this->actingAs($this->outsider)->postJson("/api/invites/{$old}")->assertNotFound();
        $this->postJson("/api/invites/{$new}")->assertOk();
    }

    public function test_the_members_list_includes_roles(): void
    {
        $this->actingAs($this->member)
            ->getJson("/api/boards/{$this->board->id}/members")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ada')
            ->assertJsonPath('data.0.role', Board::OWNER)
            ->assertJsonPath('data.1.name', 'Bob')
            ->assertJsonPath('data.1.role', Board::MEMBER);
    }

    public function test_the_owner_can_remove_a_member_and_everyone_on_the_board_is_told(): void
    {
        $this->actingAs($this->owner)
            ->deleteJson("/api/boards/{$this->board->id}/members/{$this->member->id}")
            ->assertNoContent();

        $this->assertNull($this->board->roleOf($this->member));
        Event::assertDispatched(MemberRemoved::class, fn (MemberRemoved $e) => $e->userId === $this->member->id && $e->socket === null);
        Event::assertDispatched(ActivityRecorded::class, fn ($e) => $e->activity->description === 'removed Bob from the board');

        $this->actingAs($this->member)->getJson("/api/boards/{$this->board->id}")->assertForbidden();
    }

    public function test_a_removed_member_cannot_rejoin_with_the_link_they_already_had(): void
    {
        $oldToken = $this->actingAs($this->member)->getJson("/api/boards/{$this->board->id}")->json('data.invite_token');

        $this->actingAs($this->owner)->deleteJson("/api/boards/{$this->board->id}/members/{$this->member->id}")->assertNoContent();

        $this->actingAs($this->member)->postJson("/api/invites/{$oldToken}")->assertNotFound();
        $this->getJson("/api/boards/{$this->board->id}")->assertForbidden();

        // Everyone else gets the new link, from the snapshot or the Share dialog.
        $newToken = $this->actingAs($this->owner)->getJson("/api/boards/{$this->board->id}/members")->assertOk()->json('invite_token');
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame($newToken, $this->board->fresh()->invite_token);
        $this->actingAs($this->outsider)->postJson("/api/invites/{$newToken}")->assertOk();
    }

    public function test_leaving_keeps_the_invite_link(): void
    {
        $token = $this->board->invite_token;

        $this->actingAs($this->member)->deleteJson("/api/boards/{$this->board->id}/members/{$this->member->id}")->assertNoContent();

        $this->assertSame($token, $this->board->fresh()->invite_token);
    }

    public function test_only_members_get_the_invite_link_with_the_members_list(): void
    {
        $this->board->update(['public' => true]);

        $this->actingAs($this->member)->getJson("/api/boards/{$this->board->id}/members")->assertJsonPath('invite_token', $this->board->invite_token);
        $this->actingAs($this->outsider)->getJson("/api/boards/{$this->board->id}/members")->assertOk()->assertJsonPath('invite_token', null);
    }

    public function test_removing_a_member_closes_their_sockets(): void
    {
        $this->mock(LiveConnections::class)
            ->shouldReceive('disconnect')->once()->withArgs(fn (User $user) => $user->is($this->member));

        $this->actingAs($this->owner)->deleteJson("/api/boards/{$this->board->id}/members/{$this->member->id}")->assertNoContent();
    }

    public function test_on_a_public_board_a_removed_member_keeps_their_sockets(): void
    {
        $this->board->update(['public' => true]);
        $this->mock(LiveConnections::class)->shouldNotReceive('disconnect');

        $this->actingAs($this->owner)->deleteJson("/api/boards/{$this->board->id}/members/{$this->member->id}")->assertNoContent();
    }

    public function test_a_member_can_leave_but_cannot_remove_others(): void
    {
        $carol = User::factory()->create();
        $this->board->addMember($carol);
        $this->mock(LiveConnections::class)->shouldNotReceive('disconnect');

        $this->actingAs($this->member)->deleteJson("/api/boards/{$this->board->id}/members/{$carol->id}")->assertForbidden();
        $this->deleteJson("/api/boards/{$this->board->id}/members/{$this->member->id}")->assertNoContent();

        $this->assertNull($this->board->roleOf($this->member));
        Event::assertDispatched(ActivityRecorded::class, fn ($e) => $e->activity->description === 'left the board');
    }

    public function test_the_owner_cannot_be_removed(): void
    {
        $this->actingAs($this->owner)->deleteJson("/api/boards/{$this->board->id}/members/{$this->owner->id}")->assertForbidden();
        $this->actingAs($this->member)->deleteJson("/api/boards/{$this->board->id}/members/{$this->owner->id}")->assertForbidden();

        $this->assertSame(Board::OWNER, $this->board->roleOf($this->owner));
    }
}
