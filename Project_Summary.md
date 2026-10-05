# Project Summary: Kanban Live (Real-time Collaborative Kanban)

_Last updated: 2026-10-04_

## What it is

Kanban Live is a Kanban board that several people can edit at the same time. Every change appears in the other
browsers instantly over WebSockets, without polling or page refreshes. You can also see:
- who else is on the board;
- their cursor moving;
- which card they are editing or dragging.

When two people edit the same card, the app catches it and asks the second person how to resolve the conflict.

The project is meant to show real-time, stateful work beyond plain request and response. That covers:
- presence;
- client-to-client messages;
- optimistic UI;
- concurrency control;
- recovering after a disconnect.

**Stack:** Laravel 13 · PHP 8.4 · Laravel Reverb (WebSocket server, Pusher protocol) · Laravel Echo + pusher-js · Vue 3 ·
Tailwind 4 · SQLite (any Laravel database works)

## What was built

### Backend
- **Data model:** boards, columns, cards and activities. Cards have a float `position` (fractional index) and a
  `version` (optimistic-concurrency token).
- **REST API** (session auth, `/api/*`): board list, create, snapshot and rename; column create, rename and delete; card
  create, edit, move and delete. Form requests validate input, including blocking moves to another board's column.
- **10 broadcast events** on a per-board presence channel. They share base classes (`BoardEvent`, `CardEvent`,
  `ColumnEvent`), use short `broadcastAs` names and send full-row payloads. They are sent with `ShouldBroadcastNow` and
  `toOthers()`.
- **`CardPositioner`:** places a dropped card at the midpoint between its new neighbours. If the neighbour the client
  saw has since moved away, the card goes to the bottom. When a gap gets too small, it renumbers the column and
  broadcasts the new positions.
- **Conditional-update conflict detection:** `UPDATE … WHERE version = ?`. On a mismatch the API returns 409 with the
  current card and the name of whoever last edited it.
- **Activity log**, persisted and broadcast to every viewer.
- **Presence channel authorisation** that returns each member's public info (name and colour).

### Frontend
- **`useBoard` composable:**
  - holds the snapshot, applies events idempotently and drops events older than the local copy of a card;
  - buffers events while a snapshot loads;
  - re-fetches the board after a reconnect;
  - applies the user's own changes optimistically and rolls back by re-fetching if the server rejects them.
- **Presence:**
  - an avatar stack of who's online;
  - **live cursors** sent as whispers about 20 times a second;
  - "X is editing" and "X is moving" badges on cards, which are re-sent to people who join later.
- **Native HTML5 drag and drop** with a drop-position indicator. There is also a column select in the card dialog, for
  keyboard and touch users.
- **Card dialog:** a "someone else is also editing" warning, follows other people's saves while you haven't typed
  anything, keeps your text and shows a banner if you have, and a *Use theirs* / *Overwrite with mine* conflict panel.
- A connection status indicator, a live activity feed, toasts, a responsive layout and automatic dark mode.

### Quality
- **30 PHPUnit feature tests (173 assertions)**. They cover broadcast channel, name, payload and socket exclusion;
  presence-auth signing against the real `/broadcasting/auth` endpoint; version conflicts; positioning edge cases; and a
  60-drop stress test that forces a rebalance.
- **12-step two-browser end-to-end test** (`npm run test:e2e`). It runs headless Edge through `playwright-core` against
  a live Reverb server.
- Code formatted with Pint. A demo seeder creates a sample board.

## Verification status

| Area | Status |
|---|---|
| PHPUnit suite | ✅ 30/30 passing |
| Two users over real Reverb WebSockets: presence, create, drag-move, cursors, editing badges, activity, delete | ✅ Verified (e2e, headless Edge) |
| Concurrent edit → 409 → resolution UI | ✅ Verified end to end |
| Disconnect → missed change → reconnect → resync | ✅ Verified end to end |
| Desktop and phone-width layout | ✅ Checked with screenshots |
| Touch drag and drop | ⚠️ Not supported (native HTML5 DnD). The column select in the card dialog works on touch |
| Multi-server Reverb scaling (Redis pub/sub) | ⚠️ Not configured. Reverb supports it via `REVERB_SCALING_ENABLED` |

## Notes from the build
- **Port clash on this machine:** another project's Vite server was already listening on `127.0.0.1:8000`, and Windows
  let `artisan serve` bind the same port without an error, so requests went to the other app. The symptom is a
  different site loading, or a join form that never appears. Either stop the other server, or skip
  `composer run dev` (it always uses 8000) and start the processes by hand on a free port:
  ```sh
  php artisan reverb:start              # WebSockets on 8080 (not affected)
  php artisan serve --port=8010         # then open http://localhost:8010
  npm run dev                           # only if you're editing frontend code; otherwise run npm run build once
  E2E_URL=http://127.0.0.1:8010 npm run test:e2e
  ```
  Nothing else needs to change: the WebSocket URL comes from the `REVERB_*` settings, not the app's port. All
  verification above ran this way, on port 8010.
- **Sign-in is deliberately minimal** ("join with a display name" creates a guest user). Everything downstream
  (session guard, CSRF, channel auth) is standard Laravel, so swapping in Fortify or a starter kit needs no other
  changes.

## Next steps
1. Board membership and invitations: a check in the `board.{board}` channel callback plus a policy on the API.
2. Column reordering by drag, using the same fractional-index approach.
3. Touch drag and drop (e.g. SortableJS) and keyboard reordering inside a column.
4. A per-board event sequence number, so clients can spot gaps and resync without waiting for a reconnect.
