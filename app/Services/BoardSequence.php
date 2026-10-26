<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Hands out a per-board, gap-free sequence number for every broadcast.
 *
 * Clients use it to spot a message they never received (Pusher-protocol
 * servers drop messages silently, for example while a socket reconnects)
 * and re-fetch the snapshot instead of drifting out of sync.
 *
 * The sequence numbers assigned during a request are also returned to the
 * caller in the X-Board-Events header: events sent ->toOthers() never reach
 * the tab that caused them, and without the header it would see their
 * numbers as gaps.
 */
class BoardSequence
{
    /** @var array<int, array<int, int>> board id => sequence numbers */
    private array $assigned = [];

    public function next(int $boardId): int
    {
        $seq = DB::transaction(function () use ($boardId) {
            DB::table('boards')->where('id', $boardId)->increment('event_seq');

            return (int) DB::table('boards')->where('id', $boardId)->value('event_seq');
        });

        $this->assigned[$boardId][] = $seq;

        return $seq;
    }

    /** Forget what was assigned so far: a new request starts here. */
    public function reset(): void
    {
        $this->assigned = [];
    }

    /** "board:seq,board:seq" for the response header, or null if nothing was sent. */
    public function header(): ?string
    {
        $pairs = [];
        foreach ($this->assigned as $boardId => $seqs) {
            foreach ($seqs as $seq) {
                $pairs[] = "{$boardId}:{$seq}";
            }
        }

        return $pairs ? implode(',', $pairs) : null;
    }
}
