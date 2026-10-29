# Phase 5 execution ledger (append-only)

Plan: `docs/superpowers/plans/2026-10-23-phase-5-personal-library.md` on the plans branch, read together with its "Revision 2026-10-29", which wins where they disagree. Branch: `phase-5-library`, cut from `main` (after PRs #11 to #15 and #13).

## Scope and gate
- Batch A: 5.1 (library data, actions, `InteractionRecorder`, `<livewire:library-controls>`, Add to Library everywhere) and 5.2 (the Library page).
- The later tasks (5.3 to 5.9) are other batches.

## Rulings (with the cost if wrong)
- R1. Hard rule, as in Phases 2 and 4: no `migrate:fresh`, `db:wipe`, `migrate:refresh` or `--env=testing`. Tests run with `env -u AI_AGENT -u CLAUDECODE composer test`.
- R2. Migrations are dated `2026_10_29_*` (revision), not the plan's `2026_10_26_*`.

## Tasks
| Task | Commits | Status | Open findings |
|---|---|---|---|
| 5.1 | `17ffd0a` | DONE: Review Focus #1 (opted-out readers leave no `user_interactions` row through the actions, the recorder, the job, the story page or `?sq=`), #2 (another reader's entry is never read or changed) and #3 (bad ids, statuses and URL values are refused or ignored) hold | F1: nothing emits `?sq=` yet, so the `click` path only fires for links that carry it. F2: the Flux toast is forked locally. F3: 5.4 and 5.5 extend `library-controls`, see the notes below |
| 5.2 | `0e8eeec` | DONE: the library page and its navigation are in; `last_read` and `rating` sorts are NOT wired | F4: wire the two sorts in 5.4 and 5.5. F5: the Collections and History tabs appear by themselves once `collections.index` and `history.index` exist |

## Accepted deviations
- 5.1
  - Plan file list: `Media` is not modified (no relation was needed); `LibraryEntry::scopeVisibleTo` carries the mature gate for entries instead.
  - `user_interactions.value` is `float(24)`, which is PostgreSQL `real` (Laravel's schema builder has no `real()`).
  - `RecordInteraction` re-checks the reader's opt-out when it runs, and skips a deleted reader: an event queued before an opt-out must not be written afterwards. Under the `sync` queue this adds two queries, so the story page budget tests fake the queue (the queued view is not page work) and their budgets grow by exactly one query each, the reader's library entry for the controls (25 to 26, 21 to 22).
  - `SearchLogger::sessionHash()` and `ranInThisSession()` were added so the story page compares against the same keyed hash the logger stores (revision item 10).
  - The story page test forces the session id through the session cookie (a 40 letter id), so the real `?sq=` check is exercised, not just the refusal.
  - Add to Library path (revision item 5):
    - `<x-library-add>` is the one button for `<x-hero>` and `<x-story-summary>`. It renders only for a signed-in reader (`@auth`), replacing the `Route::has('library.store')` gates, and raises the `library-add` event.
    - The hero button is also a form that posts to `library.store` (no-JS fallback). The preview card's button has no form (`:form="false"`), because the card only exists after scripts clone its `<template>`, and a form per tile would add a CSRF token to every tile.
    - `<livewire:library-listener>` lives in `layouts/app/header.blade.php` and hides itself (`<div hidden>`). It applies the same rules as the route: auth, verified, a 60 per minute limit under its own key `library-add:{id}`, and `Media::visibleTo`. A story behind the mature gate is refused with a danger toast, exactly like an unknown id.
    - `library.store` uses `throttle:60,1,library`. The prefix matters: Laravel's numeric throttles share one counter per reader across routes, so without it the cover proxy's hits would count against adding stories.
  - The story page's CTA row now holds `<livewire:library-controls>` (add, a status menu with 44px items, a favorite toggle, remove inside the menu) next to a now quiet "See the series". The page has one primary button.
  - The Flux toast's close button had no accessible name, which failed the axe audit on any page showing a toast (admin toasts included). `resources/views/flux/toast/index.blade.php` is a copy of the vendor file with `aria-label` added and the Blaze fold directive left off (the label is translated at runtime).
  - `ToggleFavorite` on a story not in the library fires `LibraryChanged` twice (the add, then the favorite). Harmless for cache invalidation.
  - Browser tests that used `enableLibraryRoute()` now sign a reader in (the buttons are for readers). The Pest helper was removed.
- 5.2
  - Sorts: only `added` and `title` exist (`SORTS`). Unknown values fall back to `added`. `last_read` needs `reading_progress.last_read_at` (5.4) and `rating` needs `ratings` (5.5); both tables are not created here. The plan's two sort test cases belong to those tasks. Wire them in `entries()` and add the value to `SORTS` and the sort `<select>`.
  - Layouts: the plan's `view` (`grid|shelves`) became `grid|list|shelves` (C7 #7). `<x-view-toggle>` gained a `shelves` prop (a third, 44px option with the Lucide `library` icon, imported with `flux:icon`).
  - The shelves view groups by `ClassShelves::forMedia` and adds a "Not Yet Shelved" row for stories with no public primary class, because the shelves leave those out and the library must never hide an entry. `<x-class-shelves>` gained a `links` prop; the library turns "See all" off, since the class page lists the whole catalog.
  - The shelves view loads up to 200 entries a page (the grid and list 24).
  - `<x-result-row>` gained an optional slot, a page's own line under the facts. The library puts the status, the favorite heart and "Added 3 days ago" there.
  - The page reads its data through computed properties (`entries`, `statusCounts`, `typeOptions`, `shelves`, ...), the way the Search and Discover pages do, not through view data. Tests read `->instance()->entries`.
  - `statusCounts` follow the format and favorite filters (and show every status, zero included), so a tab's number is what the tab shows.
  - The mature gate (revision item 2, D-35) is `LibraryEntry::visibleTo`, which goes through `Media::unmerged()->visibleTo()`. It covers the list, the counts, the format list and the shelves. Gated entries stay stored. The page does not hint that some are hidden. The test uses `Genre::factory()->mature()`, and one with `User::factory()->showsMature()`.
  - Gate `view-library` is defined in `AppServiceProvider` (owner, or public and not deactivated).
  - Navigation (revision item 6): Library is in `config('bookflix.navigation')` for the desktop header and the phone bar (Home, Discover, Search, Library, Profile). Its `active` patterns include `collections.*` and `history.*`, so those pages keep it current.
  - `<x-library-tabs current="…">` draws the Library, Collections and History tabs under the page title. A section whose route does not exist yet is left out, and a lone Library draws nothing. 5.3 and 5.6 only need to put the component in their header and pass their own route name.
  - Discover's quick link "Continue reading" (it pointed at a `library` route that never existed) is now "My library" → `library.index`.
  - The page is `flush`: a `bg-surface-deep` header band plus `px-gutter` content, so the shelves can bleed like Explore's.

## Notes for the next batches
- 5.4 (progress): `library-controls` holds one root `<div class="contents">`; read `$this->entry` once per render into a local (a computed that is null is looked up again on every read). Every action starts with `reader()` (signed in and verified, else 403) and `story($user)` (`unmerged()->visibleTo()`, else 404): keep that, and use `ReadingStatus::tryFrom() ?? throw ValidationException` for input. The library tile already accepts `progress` through `<x-cover-tile>`, so the grid can show it once `reading_progress` exists.
- 5.5 (ratings): the controls' favorite button is a `<x-cta variant="secondary" icon-only>`; the rating input can sit next to it. `x-cta` takes `icon:variant` from the caller.
- Nothing emits `?sq=` (see F1). To make clicks count, `<x-cover-tile>` and `<x-result-row>` on the search page would add `['sq' => $lastQueryId]` to the story link. That reverses a Phase 4 review decision (R11, and the test "result links carry no tracking parameter"), so it needs the lead's call.
- Screenshots of the new page (1440 and 390 px; grid, list, shelves, filtered): the batch's scratchpad, `shots/lib-after`.
