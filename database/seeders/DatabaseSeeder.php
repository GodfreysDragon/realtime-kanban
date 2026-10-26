<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Board;
use App\Models\Card;
use App\Models\User;
use App\Services\CardPositioner;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a demo board so there is something to collaborate on right away.
     */
    public function run(): void
    {
        $owner = User::factory()->create(['name' => 'Demo Bot', 'color' => '#8b5cf6']);
        // Public, so everyone who joins can try it without an invite link.
        $board = Board::create(['name' => 'Product launch', 'public' => true, 'created_by' => $owner->id]);
        $board->addMember($owner, Board::OWNER);

        $columns = [
            'Backlog' => [['Competitor pricing review', 'purple'], ['Press kit', null], ['Localise onboarding', 'blue']],
            'In progress' => [['Landing page copy', 'green'], ['Fix signup email bounce', 'red']],
            'Review' => [['Launch checklist', 'amber']],
            'Done' => [['Pick launch date', null]],
        ];

        $columnPosition = 0;
        foreach ($columns as $name => $cards) {
            $column = $board->columns()->create(['name' => $name, 'position' => $columnPosition += CardPositioner::GAP]);

            foreach ($cards as $i => [$title, $label]) {
                Card::create([
                    'board_id' => $board->id,
                    'column_id' => $column->id,
                    'title' => $title,
                    'label' => $label,
                    'position' => ($i + 1) * CardPositioner::GAP,
                    'created_by' => $owner->id,
                    'updated_by' => $owner->id,
                ]);
            }
        }

        Activity::create(['board_id' => $board->id, 'user_id' => $owner->id, 'type' => 'board.created', 'description' => 'created the board']);
    }
}
