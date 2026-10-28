<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_board_api_is_rate_limited_per_user(): void
    {
        config(['kanban.api_requests_per_minute' => 3]);
        $ada = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($ada);
        foreach (range(1, 3) as $i) {
            $this->getJson('/api/boards')->assertOk();
        }
        $this->getJson('/api/boards')->assertTooManyRequests();

        // Counted per user, not per IP: one person's script doesn't lock out the rest.
        $this->actingAs($bob)->getJson('/api/boards')->assertOk();
    }
}
