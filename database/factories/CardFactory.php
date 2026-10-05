<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\Column;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'column_id' => Column::factory(),
            'board_id' => fn (array $attributes) => Column::find($attributes['column_id'])->board_id,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'position' => fake()->randomFloat(2, 1, 10_000),
            'version' => 1,
        ];
    }
}
