<?php

namespace App\Models;

use Database\Factories\BoardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'public', 'created_by'])]
#[Hidden(['invite_token'])]
class Board extends Model
{
    /** @use HasFactory<BoardFactory> */
    use HasFactory;

    public const OWNER = 'owner';

    public const MEMBER = 'member';

    protected static function booted(): void
    {
        static::creating(fn (Board $board) => $board->invite_token ??= self::newInviteToken());
    }

    protected function casts(): array
    {
        return ['public' => 'boolean', 'event_seq' => 'integer'];
    }

    public static function newInviteToken(): string
    {
        return Str::random(32);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function columns(): HasMany
    {
        return $this->hasMany(Column::class)->orderBy('position');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class)->orderBy('position');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->latest('id');
    }

    /**
     * Boards the user may open: the ones they belong to, plus public ones.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('public', true)
            ->orWhereHas('members', fn (Builder $members) => $members->whereKey($user->id)));
    }

    /** The user's role on this board, or null if they aren't a member. */
    public function roleOf(User $user): ?string
    {
        return $this->members()->whereKey($user->id)->value('role');
    }

    public function addMember(User $user, string $role = self::MEMBER): void
    {
        $this->members()->syncWithoutDetaching([$user->id => ['role' => $role]]);
    }
}
