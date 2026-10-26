# Kanban Live: notes for contributors and coding agents

A real-time collaborative Kanban board: Laravel 13 (PHP 8.4+), Laravel Reverb, Laravel Echo with pusher-js, and Vue 3
with Tailwind 4. `README.md` explains the design and the reasons behind it. Read it before changing sync,
positioning or concurrency code.

## Commands

```sh
composer run dev                 # app server, Reverb, queue worker and Vite
php artisan test                 # PHPUnit feature tests
vendor/bin/pint                  # format PHP (CI-style check: vendor/bin/pint --test)
npm run build                    # production assets
npm run test:e2e                 # two-browser test; needs `php artisan serve` and `php artisan reverb:start` running
```

## Invariants to keep

- **Every broadcast carries the full current row.** Clients apply events idempotently and drop card events whose
  `version` is older than their copy, so broadcast the row as it is after the write (refresh the model inside the
  transaction), never a model loaded before it.
- **Broadcasts use `ShouldBroadcastNow` and `->toOthers()`.** The acting tab applies its own change optimistically and
  sends `X-Socket-ID`. Activity, rebalance and member-removed events are the exceptions: they go to everyone.
- **Card content edits are conditional on `version`** (`UPDATE … WHERE version = ?`, 409 on mismatch). Moves are
  last-writer-wins and do not bump `version`, so a drag never turns into an edit conflict.
- **Access goes through `BoardPolicy`.** `view` guards the API (in form requests' `authorize()` or `Gate::authorize`)
  and the presence channel callback alike; a new endpoint on a board must check it too.
- **Positions are fractional.** Use `App\Services\CardPositioner`; don't renumber a column anywhere else.
- **The client snapshot is the source of truth.** It is re-fetched whenever the presence channel (re)subscribes; see
  the header comment in `resources/js/composables/useBoard.js`.

## Conventions

- Format PHP with Pint before committing.
- Validate input in Form Requests, scoped to the board (see `MoveCardRequest`).
- Test races deterministically. `CardApiTest::interleaveOnRetrieve()` runs a competing write at the exact moment a
  request reads the card.
