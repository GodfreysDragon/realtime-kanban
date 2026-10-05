<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_returns_null_for_guests(): void
    {
        $this->getJson('/api/me')->assertOk()->assertExactJson(['user' => null]);
    }

    public function test_joining_creates_a_user_with_a_presence_colour_and_logs_them_in(): void
    {
        $response = $this->postJson('/join', ['name' => '  Ada Lovelace '])
            ->assertCreated()
            ->assertJsonPath('user.name', 'Ada Lovelace');

        $user = User::sole();
        $this->assertContains($user->color, User::COLORS);
        $this->assertAuthenticatedAs($user);
        $response->assertJsonPath('user.id', $user->id);
    }

    public function test_joining_requires_a_name(): void
    {
        $this->postJson('/join', ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertGuest();
    }

    public function test_api_routes_require_authentication(): void
    {
        $this->getJson('/api/boards')->assertUnauthorized();
        $this->postJson('/api/boards', ['name' => 'Nope'])->assertUnauthorized();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertNoContent();

        $this->assertGuest();
    }
}
