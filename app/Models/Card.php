<?php

namespace App\Models;

use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['board_id', 'column_id', 'title', 'description', 'label', 'position', 'version', 'created_by', 'updated_by'])]
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory;

    public const LABELS = ['red', 'amber', 'green', 'blue', 'purple'];

    protected function casts(): array
    {
        return ['position' => 'float', 'version' => 'integer'];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(Column::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
