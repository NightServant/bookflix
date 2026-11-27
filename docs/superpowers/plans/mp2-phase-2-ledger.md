# Masterplan 2, Phase 2 ledger: Broken functionality and mobile [B]

Append-only. Branch `mp2-phase-2-broken-mobile` from Phase 1's tip 9132e22 (D2-24; Phase 1 is PR'd separately and not merged, so this branch is rebased onto `origin/main` after it merges). Plan: `docs/superpowers/plans/2026-11-26-bookflix-masterplan-2.md` Part E. Brief: `docs/superpowers/specs/2026-11-26-cleanup-brief.md` Part B.

Same session as Phase 1: one Sonnet implementer and one reviewer (both reused). Screenshots (Part G step 6): factory data only (`tmp-*@example.invalid` users on the dev database, seeded by a scratch script), 375×667, 768×1024 and 1280×800, JPEG quality 70, in `docs/screenshots/mp2-phase-2/<task>/{before,after}-<page>-<width>.jpg`. "Before" shots are taken from the branch's starting point (9132e22) before the task edits anything.

## Audit (2026-11-27, against 9132e22)

| Brief [B] item | What the code does now | Plan |
|---|---|---|
| Broken links, footer included | No `href="#"`; landing anchors `#top-10`, `#together`, `#faq`, `#main` exist. No automated link check. Footer has no Privacy/Terms/Contact (Phase 3) | 2.1: crawl per role (guest, reader, admin), fix at the source |
| Buttons that do nothing | Every `wire:click` target resolves (A.2); the disabled Run-now in admin Models is the "running" state | 2.5 sweep (multi-line `<button` without `type`, empty or `javascript:` hrefs) |
| Horizontal scroll at 320–1440 | Many browser tests assert no overflow at 375/390/1280/1440; none on admin or auth pages; little at 320; none at 414. Watch list: `R/components/shelf.blade.php:87-88`, `R/components/⚡story-reviews.blade.php:317`, `R/components/filter-sheet.blade.php:51`, `R/pages/settings/layout.blade.php:20`, `R/pages/profile/⚡show.blade.php:508` | 2.2: one browser test over every page and width, seeded with long unbroken strings |
| Mobile menu | Landing: none; the in-page links are `max-md:hidden`. Readers: the account menu (`R/components/desktop-user-menu.blade.php`, Settings and Log out) sits in the top bar on phones with its label hidden; plus the bottom nav. Admin: Flux sidebar drawer with Esc and focus handling; scroll lock and close-on-navigate unconfirmed. Assistant: history drawer | 2.3 landing drawer; 2.4 tests for reader, admin and assistant, fixing only what fails |
| Logo goes home | Landing/auth → `landing`; reader header → `home`; admin → `admin.index` (sidebar and top bar). Auth card/simple layouts link a bare icon to `landing`. No `data-test="logo"` hooks | 2.1: hooks plus a test per visitor kind |
| Clickable phone and email | The site shows no contact details | Not applicable (2.5 sweep confirms) |
| Real mobile check | No end-to-end phone flow test | 2.5: search → story → library → reader → back → dialog at 375×667 |
| Placeholder text, dead nav | None found in views, seeders or lang (A.2) | 2.5 sweep |

## Conflict scan

| Pair | Shared file or interface | Resolution |
|---|---|---|
| 2.1 × 2.1 | Layout logo links, the new crawl test | none |
| 2.1 × 2.2 | Both may edit layouts (`R/layouts/app/header.blade.php`) | Run in sequence (2.1 first) |
| 2.1 × 2.3 | `R/components/landing/header.blade.php` (logo hook vs drawer) | 2.1 first; 2.3 keeps the drawer self-contained in the header component (Phase 4 rewrites it) |
| 2.1 × 2.4 | `R/layouts/admin.blade.php` (logo hook vs drawer behaviour) | 2.1 first |
| 2.2 × 2.4 | `R/layouts/app/header.blade.php` | Run in sequence (2.2 first) |
| 2.2 × 2.5 | Overflow assertions; 2.5 reuses the no-sideways-scroll check | 2.5 reuses 2.2's helper |
| 2.3 × 2.3 | `resources/js/landing.js` (new `landingMenu`), the header | none |
| 2.4 × 2.4 | Header, user menu, admin layout, assistant history partials | none |
| 2.5 × 2.5 | New `tests/Browser/PhoneFlowsTest.php` | none |

All tasks run in sequence with one implementer, so no pair runs concurrently.

## Rulings

| # | Ruling | Cost if wrong |
|---|---|---|
| P2-R1 | Screenshots are viewport-sized (not full page) unless the change is below the fold, where a full-page shot is used; overflow fixes are shown at the width that failed plus the three standard widths. | Low. |
| P2-R2 | "Before" shots are taken for every page a task is expected to change, before the task starts; pairs are committed only for pages whose pixels actually changed. | Low. |

## Deferred minors

(none yet)

## Needs decision

(filled in as tasks run)

## Task log

| Task | Status | Commits | Notes |
|---|---|---|---|
| 2.1 Internal links per role, logo hooks | pending | | |
| 2.2 No sideways scroll 320–1440 | pending | | |
| 2.3 Landing phone menu | pending | | |
| 2.4 Phone settings/sign-out, drawers | pending | | |
| 2.5 Phone flows and sweep | pending | | |

## Rulings (continued, 2026-11-27)

| # | Ruling | Cost if wrong |
|---|---|---|
| P2-R3 | (2.1) Admin report titles link to the story's admin page (`admin.media.edit`), not the reader story page, which bounces admins to `/admin`. | Low. |
| P2-R4 | (2.1) The link crawl follows up to 3 redirects and fails on a final status >= 400 or a bounce to the role's bounce target; the `/settings/security` → confirm-password redirect is allowed. | Low. |
| P2-R5 | (2.1b) The landing "Top 10" links (bar, phone menu, footer) render only when the Top 10 section does (same `$topTen` data). | None. |
| P2-R6 | (2.2) Long user strings wrap (`wrap-anywhere`); wide admin tables scroll inside `x-admin-table`; no page-level `overflow-x: hidden` anywhere. The CTA component does not wrap globally (reverted after review: it wrapped buttons that fit); the forgot-password label is "Send reset link". | Low. |
| P2-R7 | (2.2) The overflow test also fails on text that spills past its box and on fixed/sticky boxes past the right edge (a fixed header never widens the page). Its 30 s page-load timeout applies only to that file: in full runs the search page sometimes stalls only while other test processes load the machine. | Low; a real hang still fails after 30 s. |
| P2-R8 | (2.2) Profile stats use 4 columns only from `xl`; admin "At a glance" text wraps to its own line on phones. | Low; visual. |
| P2-R9 | (2.3) Below `sm` the landing bar is logo + "Get started" + menu; "Sign in" moves into the drawer (it pushed the menu button off-screen at 320/375). | Low. |
| P2-R10 | (2.4) The reader's account menu (top bar on phones) already gives Settings and Log out in two taps; no second menu added. The admin drawer now locks the page while open and clears the lock on close, on resize and on the next page after `wire:navigate`. | Low. |

## Task 2.5 sweep

| Check | Result |
|---|---|
| `<button>` without `type` inside a `<form>` (multi-line tags included) | 0 (`x-cta` and `flux:button` default to `type="button"`) |
| `href` empty, `#` or `javascript:` | 0 |
| Placeholder text ("lorem", "coming soon", "your text here", "TBD", "xxx") | 0 |
| `TODO` / `FIXME` in views, seeders, lang, app | 0; no `TODO(owner)` comments exist |
| Contact details (`tel:`, `mailto:`, phone numbers, addresses, emails) | 0 (only `email@example.com` form placeholders) → [B] phone/email: Not applicable |

## Task log (updates)

| Task | Status | Commits | Notes |
|---|---|---|---|
| 2.1 | done, reviewed twice | 0dbf0fa, 49d349a (2.1b), 7608d8c (fixes) | Found: admin report titles bounced admins; landing Top 10 links dead on an empty catalogue |
| 2.2 | done, reviewed twice | 24a6523, fc6c6a2 (fixes) | 10 pages overflowed; review caught a global CTA regression and text spills |
| 2.3 | done, reviewed | b905ae5 | Menu button was off-screen at 320/375 before commit (fixed) |
| 2.4 | done | 2cfe7f9 | Admin drawer scroll lock |
| 2.5 | done | (this commit) | Phone flow passed first time; sweep clean |

Phase fix wave (pending): 2.1 nits (guest bounce check, exact `/admin` prefix); 2.3 review (resize-past-768 test, aria-hidden count before/after, reduced-motion assertion, `aria-labelledby` on the drawer); plus the 2.4 review and the whole-branch review.
