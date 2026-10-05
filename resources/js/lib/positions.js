// Mirrors App\Services\CardPositioner so an optimistic move lands where the
// server will put it. The server's answer still wins when it arrives.
export const GAP = 1024;

export function positionBetween(lower, upper) {
    if (lower == null && upper == null) return GAP;
    if (lower == null) return upper / 2;
    if (upper == null) return lower + GAP;
    return (lower + upper) / 2;
}

export function byPosition(a, b) {
    return a.position - b.position || a.id - b.id;
}
