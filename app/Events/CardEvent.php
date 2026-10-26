<?php

namespace App\Events;

use App\Http\Resources\CardResource;
use App\Models\Card;

/**
 * A board event whose payload is the full, current state of one card.
 */
abstract class CardEvent extends BoardEvent
{
    public function __construct(public readonly Card $card)
    {
        parent::__construct($card->board_id);
    }

    public function payload(): array
    {
        return ['card' => CardResource::make($this->card)->resolve()];
    }
}
