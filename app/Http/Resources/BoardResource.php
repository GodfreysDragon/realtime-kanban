<?php

namespace App\Http\Resources;

use App\Models\Board;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Board */
class BoardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'public' => $this->public,
            'seq' => (int) $this->event_seq,
            'role' => $this->whenHas('viewer_role'),
            'invite_token' => $this->when($this->viewer_role !== null, fn () => $this->invite_token),
            'cards_count' => $this->whenCounted('cards'),
            'columns' => ColumnResource::collection($this->whenLoaded('columns')),
            'cards' => CardResource::collection($this->whenLoaded('cards')),
            'activities' => ActivityResource::collection($this->whenLoaded('activities')),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
