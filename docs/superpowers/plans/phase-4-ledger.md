# Phase 4 execution ledger (append-only)

Plan: `docs/superpowers/plans/2026-10-23-phase-4-discovery.md` on `origin/claude/phase-plans-7d02cb` (PR #3); executors read it with `git show`. Branch: `phase-4-discovery`, cut from `phase-3-dewey` (PR #7). The PR opens after #7 merges, then gets rebased with `git rebase --onto main phase-3-dewey phase-4-discovery`.

## Scope and gate
- Backend first: 4.1 (Scout plus search legs) and 4.2 (filters).
- Gated: 4.0a (frontend foundation: `@alpinejs/ui`, Motion, Pest browser plus Playwright, which needs disk space for browsers), 4.0b (component kit), and 4.3–4.8 (UI). They wait for the user's call on the design gate and token budget.

## Rulings (with the cost if wrong)
- R1. Batches: {4.1+4.2}, then the UI batches after the gate decision.
- R2. `laravel/scout` is approved by the phase start (master plan A4).
- R3. Phase 2 R8 hard rule: no `migrate:fresh`/`db:wipe`/`migrate:refresh`/`--env=testing`. Tests run with `env -u AI_AGENT -u CLAUDECODE composer test`.

## Tasks
| Batch | Commits | Status | Open findings |
|---|---|---|---|
| 4.1+4.2 | `b4b61db`, `0aaa3da` | DONE: 400 tests pass; Review Focus #2 holds; the `media_search_fts` index matches Scout's SQL | F1: no prefix matching (`japan` vs `Japanese`), so 4.3 adds prefix `:*` on the last token for as-you-type. F2: `HybridSearch` returns bare `Media`, so the UI tasks must eager-load; `preventLazyLoading` comes with 4.0a |

- Accepted deviations from 4.1/4.2:
  - Scout defaults to the `database` driver in config.
  - Transliterator `NFC; [:Latin:] Latin-ASCII`, because the plan's id corrupted kana dakuten.
  - `SearchTextBuilder`.
  - Exclusion comes from soft deletes plus `Media::unmerged()`, because the database engine ignores `shouldBeSearchable`.
  - The subject leg uses whole-word class names plus exact codes.
  - The keyword leg costs up to 4 queries per search.
- Logo (user request, 2026-10-25): an original mark (red rounded square with a white "B" made of a spine and two stacked book blocks) plus a white title-case "Bookflix" wordmark, shipped separately as PR #8 (`brand-logo`). The Figma logo swap (replacing the text "Bookflix"/"B" placeholders with the SVG via `createNodeFromSvg`) is folded into the Phase 4 F6 pass, so it doesn't collide with the running F4/F5 agent in the same file.
- Figma F3 part 2 is done: 14 new sets plus D2/D3/D6, 0 raw paints, and focus variants on all 31 interactive sets. F4/F5 are done: page "Phase 4 Screens" `3061:1555` with 21 frames, 6 flows and 0 raw fills.
- Gaps for F6:
  - The Top nav has no Search entry and no "no active item" variant, so Search, Story and Series frames show the wrong active item.
  - The Icon button with the swap property is 32 px (needs 44 px), and two sets share the name "Icon button".
  - The Story preview sample says "Berserk" (needs a public-domain title).
  - The Figma logo swap (from PR #8) is outstanding.
- TypeUI is connected (user signed in 2026-10-25; no creative access). F6 uses its section prompts and design-system guidance (`typeui_list_prompt_sections` / `typeui_get_section_prompt`) as a benchmark for hero, navbar, search and detail. It's inspiration only and never overrides the tokens or decisions. Its "typeui-fundamentals" project skill is installed only if a tool requires it (into `.claude/`, which is gitignored).
- F6 waits for master plan C7 (inspiration research by the phase-plans session on PR #3), so the screens get revised once.
- **F6 is done** (Opus-reviewed screens, 7,471 nodes): 0 raw fills, 0 contrast failures; `target/min` 44 bound; the wordmark is Inter Black vectors.
- **User decisions, "all yes" (2026-10-26), binding for 4.0b–4.8 and counting as the Phase 4 design sign-off:**
  - (a) The Search screens use the Search panel.
  - (b) The Home search panel sits under the hero.
  - (c) `radius/full` for chips, step markers and slider thumbs; `brand` is logo-only. In code, `rounded-full` is allowed for exactly those.
  - (d) "catalog" (US spelling).
  - (e) The Home empty state reads for an empty catalog, with an admin CTA to sync providers.
  - (f) Shelf titles in Title Case; buttons and body text in sentence case.
  - (g) The command palette has a visible 44 px close button.
- The final Figma pass applying (a)–(g) is done and verified.
  - Added: "Home v1 - empty (admin)" with a "Sync providers" CTA; readers see no CTA.
  - Ruling: "Library" stays capitalized in "Add to Library" and "Explore the Library", as the product-area name matching the nav.
  - The Figma design gate for Phase 4 is **passed**.
- The TypeUI `typeui-fundamentals` skill is installed (in `.claude/` and `.agents/`, both gitignored; reviewed: markdown principles only, MIT; the design system wins). `skills-lock.json` is excluded through `.git/info/exclude`. UI implementers may load it for principle checks.
- 4.0a is `f183891`:
  - Pinned: `@alpinejs/ui` 3.17.5, motion 12.43.0, nouislider 15.8.1, playwright 1.64.1, pest-plugin-browser v5.1.2.
  - The Livewire ESM build with `@livewireScriptConfig` in every layout; `preventLazyLoading` outside production.
  - 5 browser tests pass, on a Chromium headless shell (about 206 MB).
  - Afterwards the branch was rebased onto `main`, picking up the logo (PR #8).
- R4. The CI browser job is added in Task 4.8 (`npx playwright install --with-deps chromium --only-shell`, then `pest tests/Browser`). Cost: browser tests run locally only until 4.8.
- R5. 4.0b runs as **sequential batches**, not parallel subagents, because parallel writers in one checkout collide on git and on the shared `bookflix_test` database:
  - A: harness, cover-tile (consolidating Phase 3's `story-tile`), shelf, hero, story-info, cta, accordion;
  - B: tabs, combobox, command-palette, range-filter, filter-sheet, search-panel;
  - C: story-preview, quick-view, quick-links, view-toggle, result-row, series-item, rating-display.
  Cost: more wall-clock time.
- R6. Hero upgrade (user, 2026-10-26; from a live browser inspection of nextjs-animated-slider):
  - A full-bleed backdrop, plus a row of the next 3 cover cards.
  - The card morphs into the backdrop (View Transitions API, with a Motion fallback).
  - A line-by-line masked title reveal.
  - A counter and progress line; no autoplay, so no pause control.
  - Instant swap under reduced motion; carousel ARIA.
  Sent to the batch A implementer while it was building `<x-hero>`; C7 row #1 is being updated on PR #3. The Figma hero frames trail the code until a design-QA pass. Cost: the Figma frames lag the code.
- Backend done. Waiting on Figma F3 part 2, then F4/F5, F6 and the user's sign-off, before 4.0a and onward.
