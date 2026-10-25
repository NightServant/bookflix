# Phase 3 execution ledger (append-only)

Plan: `2026-10-23-phase-3-dewey-classification.md`, on PR #3's branch. Executors read a copy in the controller's scratchpad. Branch: `phase-3-dewey`, cut from `phase-2-catalog` (PR #6). The PR opens only after #6 merges, then gets rebased onto `main`.

## Design gate (user, 2026-10-23)
- The UI tasks 3.4 (explore pages), 3.5 (shelves) and 3.6 (admin review queue) wait for the Figma "Phase 3 Screens" page in DS file `TzkLMmNwpWscUEOpaEafoO` to pass F6 and get the user's sign-off.
- The backend tasks 3.1, 3.2, 3.3 and 3.7 run now.

## Conflict scan
| Pair / task | Shared file or interface | Ruling |
|---|---|---|
| 3.2 × 3.3 × 3.6 | `ClassificationService` | Sequential; 3.6 is gated |
| 3.1 × 3.3 × 3.7 | `config/bookflix.php` `classification.*` | Sequential |
| 3.3 × Phase 2 `IngestionPipeline` | `ClassifyMedia` dispatched after store | Append only; don't change the pipeline's semantics |
| 3.3 × 3.7 | `ClassifyAll` command | Same batch |

## Rulings (with the cost if wrong)
- R1. Batches: {3.1+3.2}, {3.3+3.7}, and after sign-off {3.4+3.5}, {3.6}.
- R2. Q1 default per the plan: comics and manga formats get a primary `741.5x` by country, plus a secondary 8xx when the work is literary.
- R3. The hard rule from Phase 2 R8 applies: no `migrate:fresh`/`db:wipe`/`migrate:refresh`/`--env=testing`. New migrations are new files; only migrations from this branch may be edited in place, and if one is, the controller repairs the dev DB.

## Tasks
| Batch | Commits | Status | Open findings |
|---|---|---|---|
| 3.1+3.2 | `5c63aa4`, `132ab14` | DONE: 242 tests pass; the DB rejects a second primary; an extra test covers public-scope leaks (Review Focus #1) | F1: `assign()` `updateOrCreate` can reset admin decisions. Fix at the root in `assign()` (3.3 batch) |

| 3.3+3.7 | `09fe73d`, `9b8263e` | DONE: 294 tests pass. F1 guard lives in `assign()`, and `apply()` never re-adds pairs or overrides a live primary or manual row. Review Focus #3, #4 and #5 covered | none |

- Accepted deviations from 3.3/3.7:
  - Every external `741.5x` code resolves to `741.5`, since Bookflix splits by country itself.
  - The Martian test asserts with `pluck('code')`, because the plan's version could never match (int keys).
  - The `cooking→640` keyword, because 641 isn't seeded.
  - `ClassifyMedia` is dispatched `afterCommit()` on Created and Updated imports only.
  - A comic without a country gets `741.59` at 0.80, so it's public. A non-fiction prose work gets a literature class at 0.75, so it's pending.
- **F6 decisions (the user said "lock in with your decisions", 2026-10-23), binding for 3.4–3.6:**
  - D1. The CSV gains 811–818 (spec §12). 813 is the subject level and shows stories only; there are no 813.x subsections and no "Subsections" term.
  - D2. Focus variants (the `accent/electric` ring) exist on every interactive component.
  - D3. Explore the Library lives under **Discover**: Task 3.4 adds a `Discover` item to `config('bookflix.navigation')` with route `explore.index`, active on `explore.*`. Phase 4's Discover page later becomes its target and links to Explore.
  - D4. No new type size: mobile H1 is `section-title` 24, H2 is `body-emphasis` 20.
  - D5. Admin row actions: Accept, Reject and Change are visible; "Add class" and "Remove class" go in a "More" menu (`flux:dropdown`).
  - D6. The selected chip gets a check icon, the current page gets an underline, and the avatar menu gets an accessible label.
  - D7. "See all" links get an accessible name that includes the shelf title.
- D1 code side is `e0b43ec`: CSV rows 811–818 (the CSV has 4 columns, and level and path are computed by the seeder); 295 tests pass; dev hierarchy re-seeded with `DeweySeeder` only.
| 3.4+3.5 | `f4ccaab`, `048aff0` | DONE: 309 tests pass; Review Focus #1 enforced through `scopePublicPrimary` in counts, lists and shelves; Discover nav item added (D3) | Minor: D6's underline is applied only through `aria-current`; story tiles aren't linked until Phase 4 |

- Accepted deviations from 3.4/3.5:
  - The `DeweyCounts` cache is flushed through a version key.
  - The breadcrumb is plain HTML, plus a shared `focus-ring` utility.
  - `WithPagination`.
  - The Discover icon is `globe-alt`.
  - Executors read the plan through `git show 394326e:<path>`, because the scratchpad copy was lost when the session restarted.
- 2026-10-25: the user stopped the Figma D2–D7 pass partway, to save tokens. The decisions D1–D7 still bind the code. R4: implementers build from the plan, the ledger decisions and the existing Blade/Flux components, with **no Figma calls**. F7 design QA is deferred. Cost: the Figma frames may lag behind the code until a later QA pass.
- The Phase 3 backend is complete. The UI (3.4–3.6) waits for F6 and the user's sign-off; the whole-branch review comes after the UI.
- Accepted deviations:
  - Helpers live in `tests/Pest.php`, and the validator test is bound to TestCase.
  - `scopePublicDewey(?DeweyClass)` takes a subtree filter.
  - `PrimaryClassificationExists` is a RuntimeException.
  - The atomic primary swap is deferred to 3.6, per the plan.
  - `model_version_id` has no FK until Phase 6.
