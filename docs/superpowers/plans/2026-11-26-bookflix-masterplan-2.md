# Bookflix Masterplan 2: Cleanup, Polish and Hardening

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Take the finished Phase 1–11 Bookflix and make it safe, unbroken on phones, legally honest, consistent in its screens, and closer to "read everything in one place" (reader, your own files, harvested public-domain works, followed series on official platforms, licensed creators). Ten phases, in the brief's priority order.

**Architecture:** Same single Laravel 13 app (Blade + Livewire 4 single-file pages + Flux free + Alpine + Tailwind v4, Motion for animation) on Postgres + pgvector, with the existing FastAPI `ml/` service. New in this plan: a separate `harvest` database connection and private `harvest-raw` disk (Phase 8). They run locally next to the app and move to their own Neon project and bucket later by changing env only. Plus tables for followed external series and creator licenses (Phase 9). Nothing is rewritten that already works; every phase starts by checking the baseline in Part A against the code.

**Tech Stack:** PHP 8.4 · Laravel 13.38 · Livewire 4.4 · Flux 2.21 (free) · Fortify 1.41 (2FA + passkeys on) · Alpine 3 + `@alpinejs/ui` · Tailwind 4 · Motion 12.43 · Chart.js 4.5.1 · foliate-js 1.0.1 · pdfjs-dist 6.5 · Pest 5.3 + pest-plugin-browser 5.1 (Playwright Chromium) · Larastan 3.13 (level 7) · Pint · Horizon 5.50 · Scout 11.9 · Postgres 17 + pgvector · Redis.

**Spec:** `docs/superpowers/specs/2026-11-26-cleanup-brief.md` (the owner's brief, converted from Word, screenshots left out). Brief references below look like **[D]**, **[A3]**, **[F5]**. The first master plan `docs/superpowers/plans/2026-10-22-bookflix-master-plan.md` and the master specification govern anything the brief does not mention; the master spec lives outside the repo, so where it matters this plan restates the rule.

---

## How this plan is organized

| Part | Contents |
|---|---|
| Global Constraints, Review Focus | Rules every task follows; the five failure modes most likely to hurt a real user |
| A | Baseline: what the code already does for each brief item (audited 2026-11-26) |
| B | Decisions and rulings, each with what it costs if wrong; open questions for the owner |
| C | Skills, plugins and tools per phase |
| D | **Phase 1: Security [D]**, split into TDD tasks, ready to execute |
| E | **Phase 2: Broken functionality and mobile [B]**, split into TDD tasks, ready to execute |
| F | Phases 3–10: the cross-phase interface registry, then each phase's scope and decisions, each linked to its own task-level plan file |
| G | Delivery loop: branches, ledgers, reviews, PRs, the report |
| H | Brief coverage matrix |

Phases 1 and 2 are broken down to step level in this file. Phases 3–10 each have their own task-level plan file (`docs/superpowers/plans/mp2-phase-N-<slug>.md`), so an executor reads this masterplan's constraints plus one phase file.

| Phase | Brief | Branch |
|---|---|---|
| 1 Security | D | `mp2-phase-1-security` |
| 2 Broken functionality and mobile | B | `mp2-phase-2-broken-mobile` |
| 3 Legal and compliance | E | `mp2-phase-3-legal` |
| 4 Design references, landing and settings | F10, A1–A3, C4 (CTA) | `mp2-phase-4-landing-settings` |
| 5 Admin redesign | A4–A6 | `mp2-phase-5-admin` |
| 6 Pages, SEO and trust | C1–C4 | `mp2-phase-6-pages-seo` |
| 7 Reader and your own files | F1, F2, F6 | `mp2-phase-7-reader-uploads` |
| 8 Harvest system and sources | F3, F4, F5, F9 | `mp2-phase-8-harvest` |
| 9 Official platforms and creator licensing | F7, F8 | `mp2-phase-9-platforms-licensing` |
| 10 Motion, design-tells audit, final report | G, H, I | `mp2-phase-10-motion-report` |

---

## Global Constraints

Every task follows these. Values come from the brief, the repo, or the owner's standing rules.

- **Versions** as in the Tech Stack line; check `composer show --direct` / `package.json` before relying on an API. Run Boost `search-docs` before relying on any Laravel-ecosystem API.
- **No new Composer, npm or Python dependency** without the owner's approval (AGENTS.md is stricter than the brief here). A task that needs one stops that item, records it in the ledger as "Needs decision" with the package, licence and reason, and moves on.
- **Flux free only.** Pro components (accordion, tabs, autocomplete, file upload, slider, date picker, chart, command, popover) are not available: use native HTML, Alpine (the focus/collapse/intersect plugins ship with Livewire), `@alpinejs/ui`, link-based tabs, and Chart.js for charts.
- Livewire pages are single-file `resources/views/pages/<area>/⚡<name>.blade.php`, routed with `Route::livewire('<path>', 'pages::<area>.<name>')`. Create files with `php artisan make:* --no-interaction`. Run `vendor/bin/pint --dirty --format agent` after PHP edits.
- **Tokens [brief 0]:** canvas `#000000`, surfaces `#161616` / `#232323` / `#2d2d2d`, hairline `#414141`, muted text `#808080`, brand red `#e50914` only for the one primary action. In code: `bg-canvas`, `bg-surface-deep`, `bg-surface-card`, `bg-surface-input`, `border-hairline`, `text-ink`, `text-ink-subtle`, `text-ink-muted`, `bg-brand`. No drop shadows; depth comes from stepping surfaces. Text on the featured gradient is `text-ink` or `text-ink/80`, never `text-ink-subtle`. Netflix Sans is proprietary and is never shipped or embedded.
- **Content [brief 0, F4]:** Bookflix distributes only public-domain, openly licensed, or user-owned content. Never integrate, scrape, proxy, embed or link anything on the F4 exclusion list. MangaDex is metadata only: never fetch or display its chapter images. fmhy.net is a list of candidates to vet, never a download list.
- **Files agents bring in:** only public-domain books from standardebooks.org or gutenberg.org, files generated in the task (fixtures such as the `tinyEpub()` helper), or DRM-free files the owner points to. Harvester tests use generated fixtures and `Http::fake()`; real pilot downloads (Phase 8) wait for the owner's written approval.
- **Don't fabricate [brief 0]:** no invented stats, testimonials, contact details or legal promises the code doesn't keep. Missing content becomes `{{-- TODO(owner): ... --}}` (Blade) or `// TODO(owner): ...` (PHP) and is listed in the phase report.
- **Ask first [brief 0]:** dropping columns, editing migrations that already ran, bulk imports, rotating credentials, or changing auth behaviour for existing users. In an autonomous run, record the item as "Needs decision" and skip it.
- **Accounts are deactivated, never hard-deleted** by any new code (see D2-13 for the existing purge job).
- **Auth** is Fortify email + password (plus the existing two-factor and passkeys). No OAuth or Socialite.
- **Database:** tests run on Postgres + pgvector (`pgvector/pgvector:pg17` in CI; database `bookflix_test`, user `postgres`/`postgres`), never SQLite. Never run `migrate:fresh`, `db:wipe`, `migrate:refresh`, or any artisan command with `--env=testing`. `php artisan migrate` is fine. Never run two test runs at once against the same database.
- **Tests:** `composer test` (Pint check + PHPStan level 7 + Unit and Feature suites) must pass before every PR. `composer test` does **not** run `tests/Browser`; run each touched browser file with `vendor/bin/pest tests/Browser/<File>.php`. Browser tests use the Pest browser plugin (D2-5), not Dusk. On the Mac, prefix test commands with `env -u AI_AGENT -u CLAUDECODE` so `laravel/pao` shows Pest output.
- **AI:** never call a real AI provider from tests or a cloud session. `phpunit.xml` forces `BOOKFLIX_AI_*` off; `Http::preventStrayRequests()` is on for Feature tests.
- **Secrets:** never write a password, token or key into any file, log, commit or note, and never ask the owner to paste one. Only `VITE_APP_NAME` may carry a `VITE_` prefix. New settings go into `config/*.php` with a safe default plus a line in `.env.example`; never edit the owner's `.env`.
- **Never weaken** `ProhibitedContent` (content that sexualises minors is never supported). `BOOKFLIX_OUTSIDE_REVIEWS_ENABLED` stays unset.
- **Git:** never push to `main` (it is protected: PR + green `ci` + `browser-gate`, squash merge, strictly up to date). One branch and one PR per phase. Commit messages and PR bodies carry **no** `Co-Authored-By` lines and no AI attribution. Merge only when the owner says so in chat. Leave PR #3 open.
- **Copy:** plain, specific, no heavy em-dash use, no cursive or script fonts [H].

## Review Focus

Five inputs or conditions the brief implies but no task's happy path covers, most likely to hurt a real person first. Each has its test pinned to the owning task.

1. **A Livewire update request skips route middleware that Livewire doesn't re-run.** Livewire re-applies only `auth` and `can:` on `/livewire/update`; `verified`, `reader`, `onboarded` and `password.confirm` are skipped, so a replayed snapshot runs actions the page itself would refuse. → Task 1.1.
2. **Throttling the auth forms locks out a real person, or throttles the page instead of the post.** Someone who mistypes twice must not be blocked, a different email from a shared IP must still get a reset link, and the GET pages are never throttled. → Task 1.3.
3. **A long unbroken string on a 320px phone** (a 60-character email, a URL-like alternate title, a long series name) pushes the page sideways. → Task 2.2 seeds exactly these.
4. **The phone menu closes through a link but leaves the page scroll-locked**, or lands the section under the fixed header. → Task 2.3.
5. **A link that works for one role is dead for another**: an admin-only link shown to a reader, or a reader link that bounces an admin. → Task 2.1 crawls per role.

---

# Part A: Baseline (audited 2026-11-26 on `main` 08bf8ec)

Status words: **OK** (meets the brief), **Partial**, **Missing**, **N/A**. Paths are relative to the repo root; `R/` is `resources/views/`. Re-check a line before acting on it; the code may have moved.

## A.1 Security [D]

| Brief item | Today |
|---|---|
| Admin routes | **OK.** 13 routes in `routes/admin.php`, group `auth,verified,can:access-admin` (gate in `app/Providers/AppServiceProvider.php:72`). Every admin Livewire page calls `Gate::authorize('access-admin')` in `boot()`. Horizon uses the same gate. |
| Admin route tests | **Partial.** Hand-listed datasets in `tests/Feature/Admin/AdminOnlyAccessTest.php`; no test enumerates every `admin.*` route. No per-action tests for moderation `hide/dismiss/restore`, models `runNow`, staff-picks `create/edit/addStory/removeStory/moveStory/save`. |
| `#[Locked]` | **Partial.** Unlocked admin id props: classifications `rowId`, `mediaId`; content `verifying`; duplicates `comparing`; staff-picks `storyIds`. Reader-side ids are locked. |
| Livewire persistent middleware | **Gap (found in planning).** Nothing calls `Livewire::addPersistentMiddleware`. Livewire's default list (`vendor/livewire/livewire/src/Mechanisms/PersistentMiddleware/PersistentMiddleware.php:16`) has `Authenticate` and `Authorize` but not `EnsureEmailIsVerified`, `App\Http\Middleware\EnsureReaderAccount`, `EnsureOnboarded` or `RequirePassword`. |
| Reader-side scoping | **OK.** Assistant chats, uploads, collections, library, reviews and progress are scoped by user; policies for Collection, ContentSource, Review. |
| Rate limits | **Partial.** OK: login, two-factor, passkeys, search (in code), AI Ask, uploads, reports, reviews, verification resend, reader stream/progress. **Missing:** `POST register` (`register.store`), `POST forgot-password` (`password.email`), `POST reset-password` (`password.update`), `POST user/confirm-password` (`password.confirm.store`). |
| Unescaped output | **OK.** One `{!! !!}`: the server-made 2FA QR SVG (`R/pages/settings/⚡two-factor-setup-modal.blade.php:244`, locked prop). |
| Sort/filter whitelists, `$fillable` | **OK.** Library sort whitelisted; no `$guarded = []`. Verify `app/Discovery/Search/Legs/KeywordLeg.php:87` interpolates only internal expressions. |
| Upload checks | **OK.** Type from bytes, per-format size caps, quota, hourly limit, server-made names (`app/Reader/UploadContentSource.php`); zip-slip, symlink, entry-count, ratio and size guards (`app/Reader/Archives/ZipGuard.php`). Livewire temp uploads are on the private `local` disk, but `config/livewire.php` `preview_mimes` includes `svg`. |
| Secrets | **Partial.** `.env` ignored; only `VITE_APP_NAME`; no keys found in code. **No secret scan, `composer audit` or `npm audit` in CI**; Dependabot covers GitHub Actions only. |
| File serving | **OK.** Reader files and comic pages through signed (10 min), user-bound, throttled routes plus `ContentAccessGate`; exports signed + owner check; covers are a public allow-listed proxy by design. `config/filesystems.php` `local` disk has `'serve' => true` with no caller found. |
| Open endpoints | **OK.** No `routes/api.php`; public routes are landing, `/up`, passkey well-known, covers and Fortify auth. ML service requires a ≥32-char bearer token. |
| Headers | **Partial.** nosniff, Referrer-Policy, X-Frame-Options, Permissions-Policy, nonce CSP. **No HSTS.** No TrustProxies config (no deployment target in the repo). |
| Neon role, private buckets | **N/A locally.** Development uses Docker Postgres and local disks; there is no production deployment in the repo. Reported as "Needs decision" with recommendations. |

## A.2 Broken functionality and mobile [B]

| Brief item | Today |
|---|---|
| Broken links | **OK as far as audited.** No `href="#"`; landing anchors `#top-10`, `#together`, `#faq`, `#main` exist. Footer has no Privacy/Terms/Contact links (added in Phase 3). External footer links open in the same tab. No automated link check exists. |
| Dead buttons | **OK.** Every `wire:click` target resolves (partials included). The disabled Run-now button in admin Models is the "running" state. |
| Overflow | **Partial.** Many browser tests assert `scrollWidth <= innerWidth` (mostly 375/390/1280/1440); **no check on admin pages or auth pages**, little at 320, none at 414. Watch list: `R/components/shelf.blade.php:87-88` (bleed), `R/components/⚡story-reviews.blade.php:317`, `R/components/filter-sheet.blade.php:51`, `R/pages/settings/layout.blade.php:20`, `R/pages/profile/⚡show.blade.php:508`. |
| Mobile menu | **Partial.** Readers: fixed bottom nav (`R/components/bottom-nav.blade.php`); whether Settings and Sign out are reachable on a phone is unconfirmed. Admin: Flux sidebar drawer with Esc and focus handling (`R/layouts/admin.blade.php`); scroll lock and close-on-navigate unconfirmed. Landing: **no phone menu**; the in-page links are hidden below `md`. |
| Logo goes home | **OK.** Landing/auth → `landing` (which redirects signed-in users home), reader header → `home`, admin → `admin.index`. |
| Phone/email links | **N/A.** The site shows no contact details. |
| Placeholder text | **OK.** None found in views, seeders or lang. |

## A.3 Everything else

| Brief item | Today |
|---|---|
| A1 landing nav | **Partial.** `R/components/landing/header.blade.php` is `fixed`, blurs after 8px of scroll, never hides. Closing section (`R/components/landing/closing.blade.php`) has no `id`. |
| A2 FAQ | **Partial.** Buttons with `aria-expanded`/`aria-controls`, one open at a time (`resources/js/landing.js:61`), arrow keys; one column; no default-open card. |
| A3 settings | **Partial.** Four sub-pages (`routes/settings.php`, `R/pages/settings/*`); data export exists (`app/Jobs/ExportUserData.php`); deactivate is a modal; security page behind `password.confirm`. |
| A4 admin tables | **Partial.** Shared bits: `x-admin-table` (scroll region), `x-admin-toolbar`, `x-admin-header`, `x-admin-empty`, `x-pager`. Each page hand-rolls search/pagination (mostly 25/page, duplicates 10); no stat cards; one chart (models compare). Buttons mix `x-cta` and `flux:button` variants. |
| A5 admin overview | **Partial.** `R/pages/admin/⚡index.blade.php`: "Needs attention" + "At a glance" tables from `app/Admin/AdminCounts.php`. No charts or time series. |
| A6 admin shell | **Partial.** Ten links from `config('bookflix.admin_navigation')`; account block at the bottom; no admin settings page; admins can change their email on `settings/profile`. |
| C1 | **Missing:** `resources/views/errors/` (only `R/stories/gated.blade.php`), generic breadcrumbs (`R/components/dewey-breadcrumb.blade.php` only), privacy page. No contact/feedback form exists. |
| C2 | **Partial.** Titles `"{title} - Bookflix"` (`R/partials/head.blade.php`); description + og tags on the landing layout only; favicon ico/svg/apple-touch exist, no manifest; `public/robots.txt` allows all; no sitemap, og:image or twitter tags; cover tiles have width/height/lazy but `alt=""` and no srcset. |
| C3 copyright | **OK.** `now()->year` in the landing footer. |
| C4 | **Partial.** No guest sticky CTA on phones; no analytics; no deployment config (no Vercel). |
| E privacy/terms | **Missing.** No pages, no footer or register links. |
| E deactivation | **Conflict.** `app/Console/Commands/PurgeDeactivatedAccounts.php` deletes the user, their uploads and avatar 30 days after deactivation (`bookflix.privacy.deactivated_retention_days`). The brief says accounts are never hard-deleted (D2-13). |
| E AI self-harm | **Missing.** No handling in `app/Ai/Assistant/*`. "What we send" panel: `R/pages/settings/⚡privacy.blade.php:192-200`. |
| E subscriptions | **N/A.** No billing code. |
| F1 reader | **Partial.** foliate-js for EPUB (`resources/js/reader/epub.js`), pdf.js for PDF, own engine for CBZ/images (`resources/js/reader/pages.js`: paged, webtoon, RTL, spreads, fit, preload); CBR → CBZ job; progress debounced + `sendBeacon` on pagehide. Already there: touch tap zones, arrow keys, font size and line height, webtoon routing by media type, an EPUB/PDF end card. Missing: MOBI/AZW3, FB2, light theme, margin control, comic zoom and swipe, a comic next-chapter prompt, jurisdiction notice (no `jurisdiction` column). |
| F2 help page | **Missing.** |
| F3/F5 | **Partial.** Content providers Gutenberg and Standard Ebooks (`OffersContentFiles`); others metadata only; `content_sources` holds provenance but no jurisdiction; admin Reading files at `/admin/content`. No harvest connection, tables or disk. |
| F6 uploads | **Partial.** Uploads are `content_sources` rows with `access_type=user_owned` (EPUB, PDF, CBZ, CBR; caps and 2048 MB quota in `config/bookflix.php:131-135`); Livewire temporary uploads; owner-only serving. Missing: DRM detection (attestation only), ComicInfo.xml, series grouping, bulk upload, quota shown in Settings. |
| F7 platforms | **Missing.** Only "Where to read" external links (`R/components/where-to-read.blade.php`). |
| F8 licensing | **Missing.** Only a `license` text field. |
| F9 outside ratings | **OK, off.** `external_ratings`, `external_reviews`, `app/Reviews/*`, `RefreshOutsideReviews`, behind `bookflix.reviews.outside_enabled` (stays off). |
| F10 references | **Missing.** |
| G motion | **Partial.** `resources/js/motion.js` (reveal/expand/crossfade directives, reduced-motion aware); tokens 150/250/400 ms in `resources/css/app.css:86-89`; 19 `wire:loading`, 1 skeleton; Livewire navigate progress bar on. |
| H fonts | **Finding.** `--font-sans` names Netflix Sans first (never shipped); Instrument Sans is self-hosted at build time by the Vite font plugin but is not in the stack. |

---

# Part B: Decisions and rulings

Each ruling records what it costs if wrong. Rulings marked **(owner)** were answered by the owner on 2026-11-26.

| # | Ruling | Cost if wrong |
|---|---|---|
| D2-1 | **(owner)** The brief is committed as Markdown at `docs/superpowers/specs/2026-11-26-cleanup-brief.md` (text only) so cloud sessions can read it. | Low; the brief contains no secrets. |
| D2-2 | **(owner)** Harvest system is local-first: a `harvest` DB connection (`HARVEST_DB_*`, default database `bookflix_harvest` on the same Postgres) and a private `harvest-raw` disk; moving to its own Neon project and bucket is an env change. | Medium; a later move may surface assumptions about same-server access, so code must never join across connections. |
| D2-3 | **(owner)** No Figma gate this time. UI phases use the F10 reference library plus screenshot self-review against `DESIGN.md`/`PRODUCT.md`; the owner reviews the PR. | Medium; less design exploration before code. |
| D2-4 | **(owner)** This plan lands on `main` through a docs-only PR, squash-merged when green. | None. |
| D2-5 | Browser checks use the installed Pest browser plugin (Playwright), not Laravel Dusk, with the brief's exact viewports and `document.documentElement.scrollWidth <= window.innerWidth`. | Low; porting assertions to Dusk is mechanical. |
| D2-6 | Production-only items (least-privilege Neon role, private buckets, HSTS behind a proxy, TrustProxies, secure cookies, Vercel Analytics) are reported as "Needs decision" with a recommendation, because the repo has no deployment target. | None until a deployment exists. |
| D2-7 | No dependency is added without owner approval (AGENTS.md), even where the brief allows "name it and justify it". | Some brief items (resumable uploads, malware scanning) stay partial until approved. |
| D2-8 | Uploads stay `content_sources` rows with `access_type=user_owned`; no parallel `user_uploads` table. Missing brief columns (`page_count`, `metadata` jsonb) are added to `content_sources` if a task needs them. | Low; table name differs from the brief. |
| D2-9 | Admin consolidation (A6) keeps the existing page URLs as the tab URLs, so old bookmarks keep working without redirects. | Low; URLs don't show the new grouping. |
| D2-10 | Cover alt text: `alt="Cover of {title} by {author}"` when the image is a link's only content; `alt=""` when the visible title sits in the same link (avoids a double announcement). | Low; deviates from the brief's literal wording. |
| D2-11 | Site-wide og:image only; per-story share images are skipped because cover licences from providers aren't verified. | Low. |
| D2-12 | Malware scanning goes behind a `FileScanner` interface. Without a scanner (ClamAV is a system dependency, so it needs approval) files are marked "not scanned", cannot auto-publish, and show a badge on the Verify screen. | Medium until a scanner is approved. |
| D2-13 | The existing 30-day purge hard-deletes deactivated users, which conflicts with the brief. Phase 3 starts by asking the owner; until then the privacy policy describes what the code actually does, and no new code deletes accounts. | High if the policy and code disagree, so this is asked, never assumed. |
| D2-14 | Livewire persistent middleware gets `EnsureEmailIsVerified`, `EnsureReaderAccount`, `EnsureOnboarded` and `RequirePassword` (Task 1.1). | Low; a page whose route lacks one of these is unaffected (Livewire only re-runs middleware the original route had). |
| D2-15 | Auth-form throttling uses one named limiter `auth-writes` attached through `config('fortify.middleware')`, returning `Limit::none()` for GETs and for routes with their own limiter. | Low. |
| D2-16 | F9 is already built and stays off by the owner's rule; no harvest-side staging tables are added for it. Phase 8 aligns the display with the brief (today it renders "4.1 / 5" plus a separate count) and checks attribution; nothing is switched on. | Low. |
| D2-17 | F1 keeps the installed `foliate-js` 1.0.1 package if it is the upstream project (johnfactotum/foliate-js, MIT); Phase 7 records licence and version in its ledger, else vendors a pinned commit. | Low. |
| D2-18 | Single settings page (A3): security actions confirm the password in a dialog, instead of `password.confirm` gating the whole page. | Medium; touches auth UX for existing users, so the Phase 4 plan shows the flow to the owner before building. |
| D2-19 | Self-harm handling (E) runs as a deterministic check before the assistant's model call, only in Ask (the brief's scope). Crisis resources come from official sources only, live in `config/bookflix.php` with a `reviewed_at` date, and carry a `TODO(owner)` review note. | High if a resource is wrong, which is why the owner reviews the list. |
| D2-20 | F7 official-platform links supersede "Where to read" `external_link` rows for those platforms; existing rows are copied, never deleted. | Low. |
| D2-21 | F8 territory checks use a reader-chosen country in Settings; an unknown country only gets worldwide licences. No IP geolocation dependency. | Medium; readers who skip the setting see fewer licensed works. |
| D2-22 | Motion tokens follow the brief: `--motion-slow` 400 ms becomes 300 ms; hard-coded durations move onto tokens (Phase 10). | Low. |
| D2-23 | F10 screenshots go to `docs/design-references/screenshots/`, which is gitignored (the repo is public); the notes are committed. | None. |
| D2-25 | **(owner)** Story pages are public, and guests can read from them. Phase 6 moves `stories.show` to a public, throttled route with a guest-safe page (no library controls, no write actions, no AI, no interaction logging; reviews follow reviewers' profile visibility). Phase 7 opens the reader to guests for verified public-domain sources on non-restricted media only (never uploads, unverified, mature, external-link or licensed works; Phase 9 adds worldwide-licensed works). Guest stream URLs are bound to the session, guest progress stays in the browser, and series/shelf pages stay sign-in only. | Medium; this is a new public surface, so it has its own security tests in Phases 6 and 7. |
| D2-26 | **(owner delegated)** Buttons: solid red (`primary`) means "go" and only for the one main forward action of a view (Read, Get started, New staff pick); never in Settings forms or dialogs. Settings saves and other main non-reading actions are white (`light`), as `DESIGN.md` says. `danger` means "destroy": transparent fill, red text and border, always a leading `trash` or `exclamation-triangle` icon, always behind `x-confirm-dialog`, whose confirm button is also `danger` with an explicit verb ("Delete pick"). A danger button never sits next to a primary one (row menus or a separate block at the end). | Low; a visual rule, easy to change. |
| D2-24 | Phases 1 and 2 are stacked: Phase 2 branches from Phase 1's tip, and its PR opens against `main` only after Phase 1 merges (rebase onto `origin/main` first). Until then it may be a draft PR based on the Phase 1 branch. | Low. |

**Open questions for the owner** (defaults apply until answered):

1. Deactivation purge (D2-13): keep the 30-day hard delete, or switch to keeping deactivated accounts? *Default: no change, policy describes it.*
2. Where will Bookflix be hosted? This decides Vercel Analytics, TrustProxies, HSTS, secure cookies and the Neon role. *Default: Needs decision.*
3. Takedown and privacy contact address, and the name the policy should use for the operator. *Default: `TODO(owner)`.*
4. Dependabot for Composer, npm and the `ml/` uv project (more PRs)? *Default: actions only.*
5. Approve ClamAV (malware scan), `league/flysystem-aws-s3-v3` (S3 storage, needed for any S3 bucket and direct uploads) and a resumable-upload library? *Default: not added.*
6. Build author pages for internal linking (C1)? *Default: link only between pages that exist.*
7. ~~Settings save buttons~~ Decided 2026-11-26: see D2-26.
8. ~~Public story pages~~ Decided 2026-11-26: public, and guests can read public-domain books (D2-25).

Each phase plan adds its own phase-specific rulings (`PN-R1`…) with their costs, and lists its "Needs decision" items in that phase's PR report.

---

# Part C: Skills, plugins and tools

**Where they live.** Project skills in `.claude/skills/` ship with the repo, so cloud sessions have them: `laravel-best-practices`, `livewire-development`, `fluxui-development`, `tailwindcss-development`, `testing-best-practices`, `fortify-development`, `configuring-horizon`, `scout-development`, `infer-conventions`, `typeui-fundamentals`, `deploying-to-cloud`. The `superpowers:*` skills come from the Superpowers plugin. Skills marked *(Mac)* exist only in the owner's local Claude Code; a cloud session without them follows this plan's text instead. Claude Code built-ins: `/security-review`, `/code-review`, `/simplify`. Laravel Boost MCP (Mac): `search-docs`, `database-schema`, `database-query`, `browser-logs`, `last-error`.

## C.1 Process skills (every phase, in this order)

| Skill | Use |
|---|---|
| `superpowers:writing-plans` | Re-check a phase plan against the code before starting it; write a new plan for any scope the owner adds |
| `ultraclaude:ultraclaude-milestone-execution` *(Mac)* / `superpowers:subagent-driven-development` | The task loop: implement, review after each task, a ledger, one whole-branch review. Run it with the two-subagent budget in Part G step 7 (one implementer and one reviewer, both reused) |
| `opus-orchestration` *(Mac)* | Opus architects and reviews; Sonnet workers implement (`model: "sonnet"` on every worker) |
| `superpowers:test-driven-development` | Failing test first in every task |
| `superpowers:systematic-debugging` | Any failing test or surprise before a fix |
| `superpowers:verification-before-completion` | Evidence before "done" |
| `superpowers:requesting-code-review`, `/code-review` | Per-task and whole-branch review |
| `superpowers:finishing-a-development-branch` | PR, report, hand-off |

## C.2 Per phase

| Phase | Skills and tools |
|---|---|
| 1 Security | `laravel-best-practices`, `livewire-development`, `fortify-development`, `testing-best-practices`, `/security-review`, gitleaks (pinned release binary) |
| 2 Broken & mobile | `livewire-development`, `fluxui-development`, `tailwindcss-development`, `testing-best-practices`, `design:accessibility-review` *(Mac)*, Pest browser plugin |
| 3 Legal | `laravel-best-practices`, `design:ux-copy` *(Mac)*, `humanizer` *(Mac)* (plain legal copy, no AI tells), `testing-best-practices` |
| 4 Landing & settings | `impeccable` *(Mac; reads `PRODUCT.md`/`DESIGN.md`)*, `frontend-design` *(Mac)*, `fluxui-development`, `livewire-development`, `tailwindcss-development`, `micro-interaction` *(Mac)*, `design:accessibility-review` *(Mac)*, Playwright screenshots for F10 |
| 5 Admin | `dataviz` *(Mac)* (Chart.js stat cards and charts), `livewire-development`, `fluxui-development`, `impeccable` *(Mac)*, `design:accessibility-review` *(Mac)* |
| 6 Pages & SEO | `laravel-best-practices`, `tailwindcss-development`, `design:ux-copy` *(Mac)*, `impeccable` *(Mac)* (error pages) |
| 7 Reader & uploads | `livewire-development`, `testing-best-practices`, `60fps-animation` *(Mac)*, `accessible-animation` *(Mac)*, `design:accessibility-review` *(Mac)* |
| 8 Harvest | `laravel-best-practices`, `configuring-horizon`, `testing-best-practices`, `/security-review` |
| 9 Platforms & licensing | `livewire-development`, `laravel-best-practices`, `design:ux-copy` *(Mac)* |
| 10 Motion & audit | `design-motion-principles` *(Mac)*, `accessible-animation` *(Mac)*, `page-transition-animation` *(Mac)*, `micro-interaction` *(Mac)*, `impeccable` *(Mac; critique/audit)*, `design:design-critique` *(Mac)* |

---

# Part D: Phase 1: Security [D] (ready to execute)

Branch `mp2-phase-1-security` from `main`. Ledger `docs/superpowers/plans/mp2-phase-1-ledger.md` (Part G step 2). Tasks 1.1–1.6 touch different files except where noted in the conflict scan (1.1 and 1.2 both read admin and reader components but edit different files).

### Task 1.1: Livewire updates re-run the page's middleware

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (`boot()`)
- Test: `tests/Feature/Security/LivewirePersistentMiddlewareTest.php`

**Interfaces:**
- Produces: `Livewire::getPersistentMiddleware()` contains `Illuminate\Auth\Middleware\EnsureEmailIsVerified`, `App\Http\Middleware\EnsureReaderAccount`, `App\Http\Middleware\EnsureOnboarded`, `Illuminate\Auth\Middleware\RequirePassword`.

- [ ] **Step 1: Write the failing tests**

```php
use App\Http\Middleware\EnsureOnboarded;
use App\Http\Middleware\EnsureReaderAccount;
use App\Models\User;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Livewire\Livewire;

test('livewire updates re-run the middleware their page route ran', function (string $middleware) {
    expect(Livewire::getPersistentMiddleware())->toContain($middleware);
})->with([EnsureEmailIsVerified::class, EnsureReaderAccount::class, EnsureOnboarded::class, RequirePassword::class]);

test('an admin replaying a reader page snapshot is refused', function () {
    $snapshot = livewireSnapshot($this->actingAs(User::factory()->create())->get(route('library.index'))->getContent());

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(livewireUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => '$refresh', 'params' => [], 'metadata' => []]]]]], ['X-Livewire' => '1'])
        ->assertForbidden();
});

test('an unverified reader replaying a verified page snapshot is refused', function () { /* same shape: snapshot as a verified reader, replay as User::factory()->unverified(); assertForbidden() (EnsureEmailIsVerified answers JSON requests with 403) */ });
```

Add two helpers at the bottom of the test file: `livewireSnapshot(string $html): string` (first `wire:snapshot="..."` attribute, HTML-decoded) and `livewireUpdateUri(): string` (`app(\Livewire\Mechanisms\HandleRequests\HandleRequests::class)->getUpdateUri()`). Use the full `php artisan route:list` (the `--except-vendor` flag hides every `Route::livewire` route). If the replay shape needs adjusting for Livewire 4 (payload keys are read in `HandleRequests.php:174-206`), adjust the helper, never the assertion.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Feature/Security/LivewirePersistentMiddlewareTest.php`
Expected: FAIL. The dataset rows report a missing value, and the replay returns 200.

- [ ] **Step 3: Register the four classes with `Livewire::addPersistentMiddleware([...])` in `AppServiceProvider::boot()`**

- [ ] **Step 4: Run the tests, then the reader, settings and admin suites, to verify they pass**

Run: `vendor/bin/pest tests/Feature/Security/LivewirePersistentMiddlewareTest.php && vendor/bin/pest tests/Feature/Settings tests/Feature/Reader tests/Feature/Admin`
Expected: PASS. If a settings/security Livewire test now redirects because `RequirePassword` re-runs, that is the intended behaviour: confirm the password in the test's setup (`session(['auth.password_confirmed_at' => time()])`), and record it in the ledger.

- [ ] **Step 5: Commit**: `git commit -m "fix: Livewire updates re-run verified, reader, onboarded and password-confirm middleware"`

### Task 1.2: Every admin route and action is guarded, and admin ids are locked

**Files:**
- Create: `tests/Feature/Security/AdminRouteGuardTest.php`
- Modify: `tests/Feature/Admin/AdminAccessTest.php` (add the per-action dataset)
- Modify: `R/pages/admin/classifications/⚡index.blade.php`, `R/pages/admin/content/⚡index.blade.php`, `R/pages/admin/duplicates/⚡index.blade.php`, `R/pages/admin/staff-picks/⚡index.blade.php` (add `#[Locked]`)

**Interfaces:**
- Consumes: gate `access-admin`; `User::factory()->admin()`; the demoted-admin pattern at `tests/Feature/Admin/AdminAccessTest.php:30-36`.

- [ ] **Step 1: Write the failing tests**

```php
// AdminRouteGuardTest.php
use App\Models\User;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/** @return list<RouteDefinition> */
function adminRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route) => str_starts_with((string) $route->getName(), 'admin.'))
        ->values()->all();
}

test('every admin route requires a signed-in, verified admin', function () {
    expect(adminRoutes())->not->toBeEmpty();

    foreach (adminRoutes() as $route) {
        expect($route->gatherMiddleware())->toContain('auth', 'verified', 'can:access-admin');
    }
});

test('guests are sent to sign in and readers are refused on every admin page', function () {
    $pages = collect(adminRoutes())->filter(fn (RouteDefinition $route) => in_array('GET', $route->methods()) && $route->parameterNames() === []);
    expect($pages)->not->toBeEmpty();

    foreach ($pages as $route) {
        $this->get('/'.ltrim($route->uri(), '/'))->assertRedirect(route('login'));
    }

    $this->actingAs(User::factory()->create());
    foreach ($pages as $route) {
        $this->get('/'.ltrim($route->uri(), '/'))->assertForbidden();
    }
});
```

```php
// AdminAccessTest.php: a demoted admin is refused on each action
test('a demoted admin cannot run admin actions', function (string $component, string $method, array $params) {
    $admin = User::factory()->admin()->create();
    $page = Livewire::actingAs($admin)->test($component);
    $admin->forceFill(['is_admin' => false])->save();

    $page->call($method, ...$params)->assertForbidden();
})->with([
    ['pages::admin.moderation.index', 'hide', [1]],
    ['pages::admin.moderation.index', 'dismiss', [1]],
    ['pages::admin.moderation.index', 'restore', [1]],
    ['pages::admin.models.index', 'runNow', ['popularity']],
    ['pages::admin.staff-picks.index', 'create', []],
    ['pages::admin.staff-picks.index', 'addStory', [1]],
    ['pages::admin.staff-picks.index', 'save', []],
]);

test('admin id properties cannot be set from the browser', function (string $component, string $property) {
    expect(fn () => Livewire::actingAs(User::factory()->admin()->create())->test($component)->set($property, 1))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
})->with([
    ['pages::admin.classifications.index', 'rowId'],
    ['pages::admin.classifications.index', 'mediaId'],
    ['pages::admin.content.index', 'verifying'],
    ['pages::admin.duplicates.index', 'comparing'],
    ['pages::admin.staff-picks.index', 'storyIds'],
]);
```

Use real argument values from each component (for example a real pipeline key for `runNow`, and seeded report/media ids where the method needs a row). The demoted case must reach `Gate::authorize` (boot or action) and get 403, not 404. Also add `edit`, `removeStory` and `moveStory` for staff-picks.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Feature/Security/AdminRouteGuardTest.php tests/Feature/Admin/AdminAccessTest.php`
Expected: the route tests PASS already (they lock in today's guard; record that in the ledger); the locked-property rows FAIL (no exception).

- [ ] **Step 3: Add `#[Locked]` to the five properties.** Before locking each one, grep its file for `wire:model="<prop>"` / `$wire.<prop> =`. A property the browser legitimately writes stays unlocked, and the action that uses it re-checks the row (record the ruling).

- [ ] **Step 4: Run the tests and the admin suite to verify they pass**

Run: `vendor/bin/pest tests/Feature/Security/AdminRouteGuardTest.php tests/Feature/Admin && vendor/bin/pest tests/Browser/StaffPickEditorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**: `git commit -m "test: every admin route and action refuses non-admins; lock admin id properties"`

### Task 1.3: Throttle the auth forms that post

**Files:**
- Modify: `app/Providers/FortifyServiceProvider.php` (`configureRateLimiting()`)
- Modify: `config/fortify.php` (`'middleware' => ['web', 'throttle:auth-writes']`)
- Test: `tests/Feature/Security/AuthThrottleTest.php`

**Interfaces:**
- Produces: rate limiter `auth-writes`. Limits: `register.store` 5/min per IP; `password.email` 5/min per lower-cased email + IP **and** 20/min per IP; `password.update` 5/min per email + IP; `password.confirm.store` 5/min per user id; every other request `Limit::none()`.

- [ ] **Step 1: Write the failing tests**

```php
test('sign-up is throttled per IP after five posts a minute', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('register.store'), ['email' => "not-an-email-{$attempt}"])->assertRedirect();
    }

    $this->post(route('register.store'), ['email' => 'not-an-email'])->assertTooManyRequests();
});

test('reset links are throttled per email, but another email from the same IP still gets one', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.email'), ['email' => 'reader@example.com'])->assertRedirect();
    }

    $this->post(route('password.email'), ['email' => 'reader@example.com'])->assertTooManyRequests();
    $this->post(route('password.email'), ['email' => 'someone-else@example.com'])->assertRedirect();
});

test('one IP cannot request reset links for more than twenty emails a minute', function () {
    foreach (range(1, 20) as $n) {
        $this->post(route('password.email'), ['email' => "reader{$n}@example.com"])->assertRedirect();
    }

    $this->post(route('password.email'), ['email' => 'reader21@example.com'])->assertTooManyRequests();
});

test('reset submissions are throttled per email', function () { /* 5 posts to route('password.update') with a bogus token for one email → redirect; 6th → 429 */ });

test('password confirmation is throttled per signed-in user', function () { /* actingAs a reader; 5 wrong posts to route('password.confirm.store') → redirect; 6th → 429 */ });

test('two wrong attempts never block a real person', function () { /* 2 bad posts to register.store, then a valid registration succeeds */ });

test('the sign-in, sign-up and reset pages themselves are never throttled', function () {
    foreach (range(1, 12) as $ignored) {
        $this->get(route('register'))->assertOk();
        $this->get(route('password.request'))->assertOk();
        $this->get(route('login'))->assertOk();
    }
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Feature/Security/AuthThrottleTest.php`
Expected: FAIL. The 6th/21st posts return 302, not 429.

- [ ] **Step 3: Add `RateLimiter::for('auth-writes', ...)` (a `match (true)` on `$request->isMethod('GET')` and `$request->routeIs(...)`, returning a `Limit` or an array of `Limit`s), and set `config('fortify.middleware')` to `['web', 'throttle:auth-writes']`.** Login, two-factor and passkeys keep their own limiters; this limiter returns `Limit::none()` for them.

- [ ] **Step 4: Run the throttle tests and the auth suite to verify they pass**

Run: `vendor/bin/pest tests/Feature/Security/AuthThrottleTest.php tests/Feature/Auth`
Expected: PASS.

- [ ] **Step 5: Commit**: `git commit -m "fix: throttle sign-up, reset and password-confirm posts"`

### Task 1.4: Transport and storage hardening

**Files:**
- Modify: `app/Http/Middleware/SetSecurityHeaders.php`
- Modify: `config/filesystems.php` (`local` disk `'serve' => false`)
- Modify: `config/livewire.php` (remove `'svg'` from `temporary_file_upload.preview_mimes`)
- Test: `tests/Feature/Security/SecurityHeadersTest.php` (create, or extend the existing headers test if one exists: `grep -rl "X-Frame-Options" tests`)

**Interfaces:**
- Produces: header `Strict-Transport-Security: max-age=31536000; includeSubDomains` on secure requests only.

- [ ] **Step 1: Write the failing tests**

```php
test('secure responses tell browsers to stay on https', function () {
    $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('plain http responses carry no HSTS header', function () {
    $this->get('http://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
});

test('the private disk is never served over http', function () {
    expect(Route::has('storage.local'))->toBeFalse();
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Feature/Security/SecurityHeadersTest.php`
Expected: FAIL (no HSTS header; `storage.local` exists).

- [ ] **Step 3: Implement.** Set the header when `$request->isSecure()`. Before turning `serve` off, run `grep -rn "temporaryUrl\|disk('local')->url" app resources routes` and confirm nothing serves the local disk by URL (Livewire previews use their own route). Drop `svg` from `preview_mimes`.

- [ ] **Step 4: Run the tests plus the upload, avatar and reader suites to verify they pass**

Run: `vendor/bin/pest tests/Feature/Security tests/Feature/Settings tests/Feature/Reader`
Expected: PASS.

- [ ] **Step 5: Commit**: `git commit -m "fix: HSTS on https, private disk not served, no SVG upload previews"`

TrustProxies and `SESSION_SECURE_COOKIE` depend on the host: record both as "Needs decision" (D2-6) with the recommended values (`trustProxies(at: env-listed proxies)`, `SESSION_SECURE_COOKIE=true` in production).

### Task 1.5: Secret scan and a CI security job

**Files:**
- Modify: `.github/workflows/tests.yml` (new `security` job)
- Create (only if false positives appear): `.gitleaks.toml`
- Ledger: findings table

- [ ] **Step 1: Scan the working tree and full history.** Install gitleaks from its official GitHub release (latest v8.x, verify the archive against the release's `checksums.txt`, never `curl | sh`). Run `gitleaks git --redact --report-path <scratch>/history.json .` and `gitleaks dir --redact --report-path <scratch>/tree.json .` (or `gitleaks detect` on versions without those subcommands). Expected: a report file per scan.
- [ ] **Step 2: Record every finding, redacted, in the ledger** (file, commit, rule, real or false positive). A real secret is reported as "Needs decision: rotate" plus the owner's choice on rewriting history; never rewrite history or rotate anything yourself. False positives (for example `ML_SERVICE_TOKEN=change-me` in `.env.example`, test fixtures) go into `.gitleaks.toml` as narrow path + rule allowlist entries.
- [ ] **Step 3: Run `composer audit --locked` and `npm audit --omit=dev --audit-level=high` locally.** Expected: a clean result, or a list. Existing advisories are "Needs decision" (upgrading dependencies needs approval); they are not fixed here.
- [ ] **Step 4: Add the `security` job** to `.github/workflows/tests.yml`, in the style of the existing jobs (SHA-pinned actions, `permissions: contents: read`): checkout with `fetch-depth: 0`; download the pinned gitleaks release and verify its checksum; `gitleaks git --redact --exit-code 1 .`; `composer audit --locked` and `npm audit --omit=dev --audit-level=high`, only if Step 3 was clean (otherwise leave them out, with a comment pointing at the ledger). Do not make it a required check.
- [ ] **Step 5: Verify:** push the branch and confirm the `security` job passes (`gh run list --branch mp2-phase-1-security`, `gh run view <id> --log-failed` on failure). Expected: green.
- [ ] **Step 6: Commit**: `git commit -m "ci: secret scan and dependency audit job"`

### Task 1.6: Input surface checks

**Files:**
- Test: `tests/Feature/Reader/ZipGuardTest.php` (create only for cases no existing test covers: `grep -rln "ZipGuard\|zip-slip\|\.\./" tests`)
- Modify (only if Step 1 finds input reaching it): `app/Discovery/Search/Legs/KeywordLeg.php`

- [ ] **Step 1: Read `KeywordLeg.php` around line 87** and trace `$expression` to its sources. If only constants and bound parameters reach it, record "OK" in the ledger. If request input can reach it, write a failing test that searches for `') OR 1=1 --` and expects normal results, then bind it.
- [ ] **Step 2: Make sure these archive cases each have a test** (write the missing ones, generating archives in the test with `ZipArchive`):

```php
test('an archive entry that climbs out of its folder is rejected', /* entry name "../evil.png" → upload refused with the archive error */);
test('an archive whose pages unpack past the size cap is rejected', /* config(['bookflix.reader.archive.max_uncompressed_mb' => 1]); 2 MB of zeros → refused */);
test('an archive with too many entries is rejected', /* config max_entries = 5; 6 entries → refused */);
test('a symlink entry is rejected', /* external attributes marking a symlink → refused */);
```

- [ ] **Step 3: Run them to verify they pass** (they should, since ZipGuard already guards these): `vendor/bin/pest tests/Feature/Reader/ZipGuardTest.php`. A failure here is a real bug: fix `ZipGuard` (`superpowers:systematic-debugging`).
- [ ] **Step 4: Inventory form validation.** For every Livewire page with a `wire:submit` or a saving action, confirm a `validate()` call or `#[Validate]` rules, plus a Form Request or rules for every controller POST. Record in the ledger as a table (page, action, validated yes/no). Fix any "no" with a failing test first.
- [ ] **Step 5: Commit**: `git commit -m "test: archive guard and search input checks"`

### Phase 1 exit

- [ ] `composer test` passes; the browser files touched (StaffPickEditorTest) pass.
- [ ] Run `/security-review` on the branch diff; fix real findings in one fix wave.
- [ ] Whole-branch review (most capable model) against [D] and this Part D; one fix wave.
- [ ] PR body: the Part I report table for every [D] item (Fixed / Already OK / Not applicable / Needs decision) with files and notes, including the open-endpoint inventory (A.1) and a recommended least-privilege Postgres role (owner: migrations only; app role: `SELECT, INSERT, UPDATE, DELETE` on app tables, no DDL), marked "Needs decision: no production database yet".
- [ ] Open the PR against `main`. Don't merge it.

---

# Part E: Phase 2: Broken functionality and mobile [B] (ready to execute)

Branch `mp2-phase-2-broken-mobile` from Phase 1's tip (D2-24). Ledger `docs/superpowers/plans/mp2-phase-2-ledger.md`. Conflict scan rows to record: Task 2.3 edits the landing header that Phase 4 (A1) changes again, so keep the drawer markup self-contained in the header component; Tasks 2.2 and 2.4 may both edit `R/layouts/app/header.blade.php`, so run them in sequence.

### Task 2.1: Every internal link a role can see works for that role

**Files:**
- Create: `tests/Feature/Links/InternalLinksTest.php`
- Modify: each layout's logo link gets `data-test="logo"` (`R/components/landing/header.blade.php`, `R/layouts/app/header.blade.php`, `R/layouts/admin.blade.php`, `R/layouts/auth/*.blade.php`)
- Modify: whatever the crawl finds broken

**Interfaces:**
- Produces: helpers `pagesFor(string $role): array<string>` and `internalLinks(string $html): array{paths: list<string>, fragments: list<string>}` inside the test file.

- [ ] **Step 1: Write the failing tests**

```php
test('every internal link a guest can see opens for a guest', function () {
    assertLinksWork(pagesFor('guest'));
});

test('every internal link a reader can see opens for that reader', function () {
    $this->actingAs(User::factory()->create());
    assertLinksWork(pagesFor('reader'));
});

test('every internal link an admin can see opens for that admin', function () {
    $this->actingAs(User::factory()->admin()->create());
    assertLinksWork(pagesFor('admin'));
});

test('the logo goes home for each kind of visitor', function () {
    expect(logoHref($this->get(route('landing'))->getContent()))->toBe(route('landing'));
    $this->actingAs(User::factory()->create());
    expect(logoHref($this->get(route('home'))->getContent()))->toBe(route('home'));
    $this->actingAs(User::factory()->admin()->create());
    expect(logoHref($this->get(route('admin.index'))->getContent()))->toBe(route('admin.index'));
});
```

- `pagesFor('guest')`: `landing`, `login`, `register`, `password.request`. `pagesFor('reader')`: every parameter-less GET route whose middleware includes `reader`, plus one story page, one series page and one explore shelf built from factories. `pagesFor('admin')`: every parameter-less GET `admin.*` route.
- `assertLinksWork($pages)`: GET each page (assert < 400), parse `href`s with `DOMDocument` (suppress libxml warnings). Keep same-origin paths. Skip `logout`, URLs carrying `signature=`, `/reader/stream`, `/reader/*/page`, `/covers/`, `/exports/`, `/livewire`. GET each unique path once as the same role and assert status < 400. For `#id` and `/path#id`, assert that `id="id"` exists in the target page's HTML.
- Seed enough data that shelves, rails and footers render links (media, a series, a Dewey class, a verified public-domain content source).

- [ ] **Step 2: Run them to verify which fail**

Run: `vendor/bin/pest tests/Feature/Links/InternalLinksTest.php`
Expected: the logo test FAILS (no `data-test="logo"`); the crawl tests either pass or name real broken links.

- [ ] **Step 3: Add the logo hooks and fix each broken link** at its source (a wrong route name, an anchor without its id, an admin-only link shown to readers). Fix a link only by correcting or removing it, never by special-casing it in the test.

- [ ] **Step 4: Run to verify they pass**: `vendor/bin/pest tests/Feature/Links/InternalLinksTest.php`. Expected: PASS, in under a minute.

- [ ] **Step 5: Commit**: `git commit -m "test: crawl internal links per role; fix the broken ones"`

### Task 2.2: No page scrolls sideways at any common width

**Files:**
- Create: `tests/Browser/NoHorizontalScrollTest.php`
- Modify: the views that fail (start with the A.2 watch list)

**Interfaces:**
- Consumes: the `sticksOut` helper pattern in `tests/Browser/SmokeTest.php:69-90`; the viewport and sign-in patterns in `tests/Browser/LayoutTest.php`.

- [ ] **Step 1: Write the failing test**

Widths and heights: 320×640, 375×667, 414×896, 768×1024, 1024×768, 1280×800, 1440×900. Pages: guest (landing, login, register, forgot-password); reader (home, discover, search with a query, library, a story page, a series page, profile, every settings page, upload, assistant); admin (every parameter-less admin page). Seed the long strings: a story titled with a 64-character unbroken word, an alternate title that is a 90-character URL, a series with a 50-character unbroken name, and a reader whose email is 60 characters long (that reader also appears in admin Users).

```php
test('no page scrolls sideways', function (string $role, string $path) {
    // sign in as $role, visit $path once, then for each [w, h]: resize and assert
    // document.documentElement.scrollWidth <= window.innerWidth, reporting the widest offending element on failure.
})->with('pagesByRole');
```

Visit each page once and resize through the widths if the plugin can resize a live page; otherwise visit once per width. Keep the file's runtime under about 6 minutes.

- [ ] **Step 2: Run to see what fails**: `vendor/bin/pest tests/Browser/NoHorizontalScrollTest.php`. Expected: some pages fail and name their widest element.
- [ ] **Step 3: Fix each at the cause:** `min-w-0` on flex children, `break-words`/`[overflow-wrap:anywhere]` on user strings, an `overflow-x-auto` container on wide tables, and removing bleed margins at small widths. Rails scroll inside themselves; the page never does.
- [ ] **Step 4: Run to verify it passes**, plus the existing layout tests: `vendor/bin/pest tests/Browser/NoHorizontalScrollTest.php tests/Browser/LayoutTest.php tests/Browser/SmokeTest.php`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "fix: no sideways scroll from 320 to 1440px; long strings wrap"`

### Task 2.3: A menu for the landing page on phones

**Files:**
- Modify: `R/components/landing/header.blade.php`
- Modify: `resources/js/landing.js` (Alpine data `landingMenu`)
- Test: `tests/Browser/LandingMenuTest.php`

**Interfaces:**
- Produces: Alpine component `landingMenu` with `open: boolean`, `toggle(): void`, `close(returnFocus = true): void`; menu button `data-test="landing-menu-button"` (`aria-expanded`, `aria-controls="landing-menu"`), drawer `id="landing-menu"`.

- [ ] **Step 1: Write the failing tests**

```php
test('the phone menu opens, keeps focus inside, and closes on Escape', function () {
    // 375×667, guest on landing: tap the menu button → drawer visible, aria-expanded=true, focus on the first link;
    // Tab past the last item returns to the first (focus stays in the drawer); Escape closes it and focus returns to the button.
});

test('a menu link closes the drawer, unlocks scrolling and lands its section below the header', function () {
    // open the drawer, tap "FAQ": drawer hidden; document.body has no scroll lock (style/overflow and inert cleared);
    // window.scrollY > 0; #faq's top >= the header's height - 1 (scroll-margin).
});

test('the page cannot scroll behind the open drawer', function () {
    // open the drawer: the page is locked (window.scrollTo has no effect).
});

test('there is no menu button from 768px up', function () {
    // 768×1024: menu button not visible; in-page links visible.
});
```

- [ ] **Step 2: Run them to verify they fail**: `vendor/bin/pest tests/Browser/LandingMenuTest.php`. Expected: FAIL (no menu button).
- [ ] **Step 3: Build the drawer** below `md`: Top 10, Why Bookflix, FAQ, Sign in, Get started (red, the one primary action). Use `x-trap.inert.noscroll="open"` (the Alpine focus plugin ships with Livewire), close on Escape, on a link tap and on backdrop click, and use `x-transition` with opacity + transform that switches instantly under `prefers-reduced-motion`. Use tokens only.
- [ ] **Step 4: Run to verify they pass**, plus `tests/Browser/LandingTest.php`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: landing menu drawer on phones"`

### Task 2.4: Settings and sign-out reachable on a phone; drawers behave

**Files:**
- Modify (as the tests demand): `R/layouts/app/header.blade.php`, `R/components/bottom-nav.blade.php` or the user menu component, `R/layouts/admin.blade.php`, `R/partials/assistant-history*.blade.php`
- Test: `tests/Browser/PhoneNavigationTest.php`

- [ ] **Step 1: Write the failing tests**

```php
test('a reader on a phone reaches Settings and signs out in two taps or fewer', function () {
    // 375×667 on home: a visible control opens a menu containing Settings and Sign out;
    // Settings navigates to the settings page; Sign out lands on the landing page signed out.
});

test('the admin drawer locks the page and closes after navigating', function () {
    // 375×667 admin: open the sidebar → page can't scroll behind it; tap "Users" → URL is admin/users and the drawer is closed.
});

test('the assistant history drawer closes on Escape and returns focus', function () {
    // 375×667 reader on the assistant: open history → Escape → drawer closed, focus back on its opener.
});
```

- [ ] **Step 2: Run them to see which fail**: `vendor/bin/pest tests/Browser/PhoneNavigationTest.php`.
- [ ] **Step 3: Fix only what fails.** Reuse the existing user-menu component on phones rather than adding a second menu. Close the Flux sidebar on `livewire:navigated` if it stays open.
- [ ] **Step 4: Run to verify they pass**, plus `tests/Browser/AdminShellTest.php tests/Browser/AssistantHistoryTest.php`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "fix: phone access to settings and sign-out; drawers close and lock correctly"`

### Task 2.5: Real phone flows and the phase sweep

**Files:**
- Create: `tests/Browser/PhoneFlowsTest.php`
- Modify: whatever the flow finds broken

- [ ] **Step 1: Write the failing test** at 375×667 as a reader: search for a seeded title → open its story page → add it to the library and change its status (library controls) → open the reader on a verified public-domain EPUB built with `tinyEpub()` → turn a page → go back → open and close a dialog (report a review, or the deactivate dialog) with touch and with Escape. After every step, assert no sideways scroll and no console errors.
- [ ] **Step 2: Run it**: `vendor/bin/pest tests/Browser/PhoneFlowsTest.php`. Fix failures at the cause, then rerun to green.
- [ ] **Step 3: Sweep without tests, recording each result in the ledger:** multi-line `<button` tags without `type` inside forms (`grep -rnz`), `href` with an empty or `javascript:` value, placeholder strings ("lorem", "coming soon", "your text here", "TODO" in views/seeders/lang), any contact details (expected none: [B] phone/email is Not applicable).
- [ ] **Step 4: Commit**: `git commit -m "test: phone flows from search to reader; phase sweep"`

### Phase 2 exit

- [ ] `composer test` passes; every browser file touched or created in this phase passes when run explicitly.
- [ ] Whole-branch review (most capable model) against [B] and this Part E; one fix wave.
- [ ] PR body: the Part I report table for every [B] item.
- [ ] If Phase 1 has merged: `git rebase origin/main`, push, and open the PR against `main`. If not: open a draft PR based on `mp2-phase-1-security`, and note in its body that it is rebased onto `main` after Phase 1 merges. Don't merge it.

---

# Part F: Phases 3–10

Each phase has its own task-level plan in `docs/superpowers/plans/mp2-phase-N-<slug>.md` (linked below each heading). It is written against the code as it was on 2026-11-26, so each phase re-checks its baseline before its first task. The bullets here are the scope and the decisions already made; the phase plan holds the tasks.

## F.0 Cross-phase interface registry

Later phases use these names exactly. A phase plan may add names but must not rename these.

| Phase | Produces |
|---|---|
| 3 | Routes `legal.privacy` (GET `/privacy`), `legal.terms` (GET `/terms`), public, landing layout, views in `R/legal/`. Shared sections in `R/legal/sections/*.blade.php` that Phases 7 and 9 extend. Config `bookflix.legal.operator_name` (env `BOOKFLIX_LEGAL_OPERATOR`, default null), `bookflix.legal.contact_email` (env `BOOKFLIX_LEGAL_CONTACT_EMAIL`, default null), `bookflix.crisis_resources` (keyed by ISO 3166-1 alpha-2 code plus `default`), `bookflix.crisis_resources_reviewed_at`. `App\Ai\Safety\SafetyCheck::assess(string $message): App\Ai\Safety\SafetyVerdict` (`isCrisis(): bool`). |
| 4 | Route `settings.edit` (GET `/settings`, page `pages::settings.index`) with section ids `profile`, `reading`, `privacy`, `security`, `account`. `profile.edit`, `preferences.edit`, `privacy.edit` and `security.edit` keep their names and redirect to `/settings#<id>`. Blade components `x-settings.section` (props `id`, `title`, `description`) and `x-settings.summary-card` (right column; Phase 7 adds a quota card, Phase 9 a country field). Alpine `settingToggle` for save-immediately toggles. Landing: closing section `id="closing"`; Alpine store `landingChrome` with `pastFaq: bool`, read by the header and the sticky CTA. `docs/design-references/README.md`. |
| 5 | Components `x-admin.stat-card` (`title`, `value`, `subtext`), `x-admin.chart-card` (`title`, `type`, `labels`, `datasets`), `x-admin.tabs` (`items`: label, route, optional badge), upgraded `x-admin-toolbar` and `x-admin-table`, `x-confirm-dialog`; `x-cta` variants `primary`, `secondary`, `outline`, `danger`. Trait `App\Livewire\Concerns\AdminTable`. `App\Admin\Metrics\DailySeries::count(Builder $query, string $column, int $days = 30): array<string, int>`. `App\Admin\WorkQueue::items(): Collection<int, App\Admin\WorkQueueItem>`, fed by classes implementing `App\Admin\Contracts\WorkQueueSource` (`items(): iterable<WorkQueueItem>`), registered in `config('bookflix.admin_work_queue_sources')`. `WorkQueueItem` fields: `area` (catalog, review, operations, people), `severity` (error, warning, info), `label`, `count`, `oldestAt`, `status`, `ctaLabel`, `ctaUrl`. Route `admin.settings`. |
| 6 | `x-breadcrumbs` (`items`: list of `[label, url|null]`) with `BreadcrumbList` JSON-LD; `resources/views/errors/{403,404,419,429,500,503}.blade.php` on a shared `errors/layout.blade.php`. `R/partials/head.blade.php` accepts `$description` and `$ogImage`; titles read `"{Page} | Bookflix"`. The cover proxy accepts `?w=160|320|480` and serves WebP with a fallback. `stories.show` is public (throttle `story-guest`) with a guest-safe page (D2-25). |
| 7 | Columns `content_sources.jurisdiction` (nullable string), and `content_sources.page_count` / `content_sources.metadata` (jsonb) if a task needs them. `App\Reader\Archives\EpubDrmDetector::isProtected(string $path): bool`. `App\Reader\Uploads\UploadMetadataReader::read(string $path, App\Enums\ContentFormat $format, string $originalName): App\Reader\Uploads\UploadMetadata` (series, volume, chapter, title, creators). `App\Reader\NextChapter::for(Media $media, ?ContentSource $source, User $user): ?App\Reader\NextChapterTarget`. Route `help.reading-apps` (GET `/help/reading-apps`). `App\Reader\ContentAccessGate::allows(?User $user, ContentSource $source)` (today's argument order, user now nullable) holds the guest rules (D2-25); `ContentUrl` binds guest URLs to the session id. |
| 8 | Connection `harvest`; models `App\Harvest\Models\{Source, HarvestRun, RawRecord, HarvestFile, LicenseEvidence, Promotion}` on it (`HarvestFile` uses table `files`). Contracts `App\Harvest\Contracts\HarvestAdapter` and `App\Harvest\Contracts\FileScanner`; `App\Harvest\LicenseGate`; jobs `App\Harvest\Jobs\{RunHarvest, DownloadHarvestFile, PromoteRecord}`. Internal routes `internal.harvest.ready` (GET) and `internal.harvest.promote` (POST), guarded by `config('bookflix.harvest.service_token')`. Config `bookflix.harvest.*` including `excluded_hosts`. `App\Harvest\HarvestWorkQueueSource`. |
| 9 | Tables `platform_links`, `user_follows`, `content_licenses`, `content_license_media`, `creator_applications`; column `profiles.country` (ISO 3166-1 alpha-2, nullable), set in Settings. `App\Platforms\PlatformRegistry` (config `bookflix.platforms`). `App\Licensing\LicenseGate::allows(ContentSource $source, ?User $user): bool` (a guest gets worldwide licences only), called from `App\Reader\ContentAccessGate`. Routes `publish` (GET/POST `/publish`) and `publish.thanks`. `App\Licensing\LicenseWorkQueueSource`, `App\Platforms\CreatorApplicationWorkQueueSource`. |
| 10 | Motion tokens in `resources/css/app.css`; no new interfaces. |

## Phase 3: Legal and compliance [E]

Tasks: [`mp2-phase-3-legal.md`](mp2-phase-3-legal.md)

- **First:** ask the owner open question 1 (deactivation purge, D2-13) and question 3 (operator name, contact address).
- **Inventory first** (ledger): every table holding personal data, logs, retention jobs (`bookflix:prune-*`, `PurgeDeactivatedAccounts`), and every third party. Today that is the AI provider (from `config('bookflix.ai.base_url')`), mail, storage, the self-hosted ML service, and the cover-image hosts that readers' browsers load images from (`img-src https:`). Fonts are self-hosted at build time (no outside font host at runtime), and catalog providers run as server jobs without reader data. The policy is written from this inventory, not from a template.
- Public pages `/privacy` and `/terms` (Blade, landing layout), linked from the landing footer, the register form, Settings › Privacy, the landing FAQ and the Phase 7 help page. They cover: what is collected, why, retention, export (the existing export job), AI features (Ask, explanations, AI-assisted search; provider named from config; what is sent, matching the "What we send" panel; switching off), uploads (private, storage, quota, deactivation, takedown contact), followed series (added when Phase 9 lands), and "Not applicable" for subscriptions and auto-renewal.
- **Deactivation:** the policy states exactly what happens to a reader's data and uploads on deactivation, and the code does what the policy says. The report flags that GDPR and CCPA may still require erasure on request; hard deletion is not built without the owner's go-ahead.
- **AI self-harm (D2-19):** a `SafetyCheck` that runs before `AssistantRunner` calls the model. Messages about self-harm or suicide get a warm, direct reply with region-appropriate resources from `config('bookflix.crisis_resources')`, are never routed to book search, never fail silently, and log nothing beyond the normal chat row. Tests use representative messages in both directions: indirect wording and quoted book titles such as "13 Reasons Why" must not misfire as crisis messages.
- Tests: the pages render and are linked; the policy mentions every third party in the inventory (a test reads the configured provider list); safety replies for the representative messages; nothing extra is logged.

## Phase 4: Design references, landing and settings [F10, A1, A2, A3, C4]

Tasks: [`mp2-phase-4-landing-settings.md`](mp2-phase-4-landing-settings.md)

- **F10 first:** `docs/design-references/README.md` plus one note per reference (URL, date, the pattern borrowed and why), covering settings pages (Primer, Geist, Polaris, Material 3), admin tables and dashboards, reader controls (Readest, Thorium, Koodo), and streaming-style shelves. Screenshots go in gitignored `docs/design-references/screenshots/` (D2-23), taken with Playwright of public pages only.
- **A1:** the header hides (transform + opacity, instant under reduced motion) once a sentinel before `#faq` reaches the viewport top, and returns above it. Driven by IntersectionObserver in `landing.js`, not a scroll listener. Give the closing section an `id`, and give anchored sections `scroll-margin-top`. Keep it compatible with the Phase 2 drawer.
- **A2:** two independent flex columns at ≥1024px (first three questions left, last two right), one column below; "What is Bookflix?" open on load; one open at a time across both columns; focus ring from tokens.
- **C4 (landing parts):** "Get started" above the fold at 375×667 and desktop; a guest sticky CTA on phones that respects the safe-area inset and hides while FAQ or closing is on screen (shares A1's observer).
- **A3:** one `/settings` page shaped like Profile: header block, settings column plus a narrower summary column, sticky section nav with the current section highlighted. Sections: Profile, Reading preferences, Privacy and AI, Security, Account. Toggles save immediately with an inline confirmation and roll back with an error on failure; red buttons only for explicit saves; dialogs for change password, two-factor setup (reuse the existing modal) and deactivate (existing). Old sub-routes keep their names and redirect to the matching anchor. Password confirmation becomes a dialog (D2-18; show the flow to the owner first). (The upload quota card is added to the summary column in Phase 7.)
- Tests: Pest browser tests for the A1 toggle (desktop and 375), the FAQ rules (mouse, keyboard, touch), CTA above the fold, the sticky CTA hiding, toggle success and rollback, the redirects, and no setting lost (a Feature test lists every field from today's four pages and finds each on the new page).

## Phase 5: Admin redesign [A4, A5, A6]

Tasks: [`mp2-phase-5-admin.md`](mp2-phase-5-admin.md)

- **Shared pattern:** Blade components `x-admin.stat-card` (title, number, subtext), `x-admin.chart-card` (Chart.js, lazy-loaded like `resources/js/version-chart.js`), an upgraded `x-admin-toolbar` (debounced search plus link tabs and selects, all in the query string) and `x-admin-table` (muted semibold headers, hairline divider, truncation with a tooltip, stacked cards below 768px or a scroll container with the first column pinned), plus a trait `App\Livewire\Concerns\AdminTable` (`#[Url]` search, filters and page; 10 rows per page; a sort whitelist).
- **Four button variants** on one component (`x-cta` today): primary (solid red, one per page), secondary (surface card), outline (hairline), danger (red text and border on a transparent fill, always behind a confirmation dialog).
- **Tables:** Users, Models, Reading files, Staff picks, Classification review, Duplicate review; then Media, Sync and Reports, so every tab inside a group looks the same. Stat numbers come from real queries; a metric not computed anywhere is left out. Charts use existing timestamps first; a new column is added only when no chart is possible otherwise (record it).
- **A5 overview:** stat cards (stories, enabled sources, items awaiting review, registered readers, last sync status), four chart cards (catalog growth, review backlog, sync outcomes by day, sign-ups or active readers), and one work queue merging "Needs attention" and "At a glance" (count, oldest age, status badge, CTA; filters by area and severity; actionable items first).
- **A6:** the avatar, name, Settings link and Sign out at the top of the sidebar; five destinations (Overview; Catalog: Media, Reading files, Staff picks; Review: Duplicates, Classification, with badges; Operations: Sync, Models; People: Users, Reports); link tabs inside each, on the existing URLs (D2-9). An admin settings page in the A3 design with two-factor setup; name edited in a dialog; email read-only, and a Feature test proves a forged email change from an admin is rejected server-side (a guard on the `User` model, tested through the Livewire profile-save path; Fortify's profile-information route is off and stays absent).
- Tests: per-table search + filter + pagination together, URL state round trip, danger confirmation, layout at 375/768/1024/1280 (extend `NoHorizontalScrollTest`), the five-link sidebar, the admin email guard.

## Phase 6: Pages, SEO and trust [C1, C2, C3, C4]

Tasks: [`mp2-phase-6-pages-seo.md`](mp2-phase-6-pages-seo.md)

- **Error pages:** `resources/views/errors/404.blade.php` (search box, home link, a few popular shelves), plus 403, 419, 429 (Phase 1 added throttles), 500 and 503 in the same style.
- **Thank-you pages:** Not applicable today (no contact or feedback form; reports finish inline). The Phase 9 "Publish on Bookflix" form uses post/redirect/get to a thank-you page.
- **Breadcrumbs:** a generic `x-breadcrumbs` component plus `BreadcrumbList` JSON-LD (a script tag carrying the CSP nonce) on story, series, explore shelves (replacing the Dewey breadcrumb's markup) and admin pages.
- **Public story pages (D2-25):** `stories.show` leaves the sign-in group for a public, per-IP throttled route; the page is guest-safe, and every Livewire action on it authorises itself.
- **Internal links** among story, series, shelf and similar stories; author pages only if the owner answers question 6.
- **SEO:** titles become `"{Page} | Bookflix"` (story pages `"{title} by {author} | Bookflix"`) through `R/partials/head.blade.php`; a meta description on every page (story pages: synopsis trimmed to about 155 characters on a word boundary); `site.webmanifest`; `robots.txt` disallowing `/admin`, `/settings`, `/library`, auth and Livewire paths (no sitemap exists, so none is referenced); site-wide og:image 1200×630 (an original image made for Bookflix) and Twitter card tags (D2-11); cover alt text (D2-10).
- **Images:** the cover proxy serves WebP (with fallback), resized widths for `srcset`, explicit width/height, and lazy loading below the fold. Use GD if it is installed (check `php -m`); otherwise "Needs decision".
- **Forms:** every form shows inline field errors, a visible success state and a readable server-error message. Each gets one forced-validation and one forced-server-error test.
- **Analytics:** Not on Vercel (no deployment config), so it is reported as "Needs decision" with a host-appropriate option; nothing is added.

## Phase 7: Reader and your own files [F1, F2, F6]

Tasks: [`mp2-phase-7-reader-uploads.md`](mp2-phase-7-reader-uploads.md)

- **F1:** confirm the foliate-js package (D2-17); add MOBI/AZW3 and FB2 through foliate; a light theme next to dark and sepia; line-height and margin controls; keyboard and swipe throughout; reduced motion. In the comics reader, confirm or add pinch and double-tap zoom, tap zones, arrow keys, and webtoon detection from series metadata or tall image ratios. At the last page, one tap opens the next chapter or volume, never a list. Add a nullable `jurisdiction` column on `content_sources` (and an input on the Reading files screen), shown on the story page before reading ("Public domain in the United States. Check your local laws if you're elsewhere."). DjVu stays out of scope; CBR keeps converting at ingest.
- **Guest reading (D2-25):** guests open the reader for verified public-domain sources on non-restricted media; session-bound signed URLs; per-IP throttles; progress in the browser only; a line added to the legal sections.
- **F2:** a public help page listing only the brief's reading apps, linked to their official sites.
- **F6 (D2-8):** detect DRM-protected EPUBs (an `encryption.xml` entry beyond the IDPF/Adobe font-obfuscation algorithms) and reject them with a plain explanation that never mentions removal tools. Read `ComicInfo.xml`, EPUB metadata and filenames (series, volume, chapter) to match the catalog and group volumes in reading order, and let the reader fix a wrong match. Add bulk upload. Make the temporary-upload disk an env switch (default `local`). Direct-to-S3 temporary uploads need `league/flysystem-aws-s3-v3`, which isn't installed, and resumable uploads need a library: both are "Needs decision" (D2-7). Show the quota in Settings. Uploads stay private everywhere (search, shelves, recommendations). Add a terms line and the takedown contact on the upload screen. No import-from-URL (a test proves no such route exists).
- Tests per the brief's Part I: one user can never fetch another's upload (direct URL, reused signed URL, Livewire action); DRM EPUB rejected; quota holds; next-chapter prompt opens the right chapter.

## Phase 8: Harvest system and sources [F3, F4, F5, F9]

Tasks: [`mp2-phase-8-harvest.md`](mp2-phase-8-harvest.md)

- **Infrastructure (D2-2):** connection `harvest` in `config/database.php` (`HARVEST_DB_*`), migrations in `database/migrations/harvest` run with `php artisan migrate --database=harvest --path=database/migrations/harvest`, test database `bookflix_harvest_test`, a private `harvest-raw` disk, queue `harvest` with its own Horizon supervisor, and scheduler entries that run only when `HARVEST_ENABLED=true`. Code never joins across the two connections.
- **Schema (brief F5):** `sources`, `harvest_runs`, `raw_records` (unique `source_id, external_id`), `files`, `license_evidence`, `promotions`, with the brief's columns.
- **Workflow:** one catalog job per enabled source on its cadence, incremental (ETag, modified-since, change feeds) → normalize into `raw_records` → **license gate** (download only if the rights statement matches the source's `license_policy`; anything ambiguous goes to review with no download) → download from official URLs, check type by content, size, `ZipGuard` and checksum, then scan (D2-12) → quarantine failures, never silently delete → dedupe against harvest and production (identifiers, normalized title/author, checksum) → enrich from metadata-only providers → expose ready items through an internal API guarded by a service token (`hash_equals`) to the admin Reading files screen → **Verify** runs a promotion job that copies the file to the production disk and creates or updates the media and content-source rows. Auto-approve stays off.
- **Good citizen:** robots and terms respected, per-source rate limits, a User-Agent with a contact address from `HARVEST_CONTACT_EMAIL`, exponential backoff, and a circuit breaker that pauses a source after repeated failures and raises an item in the admin work queue. Per-source caps on file count and bytes, with a projected-cost line in the run report.
- **F3/F4:** the existing Gutenberg and Standard Ebooks providers become the first harvest adapters. Each new source gets a vetting note (official site, machine-readable licence, automation allowed, jurisdictions) and lands with `enabled=false` until the owner approves it in writing. A config deny-list of F4 hosts that the sources table refuses (a test proves an excluded host can't be added or enabled). fmhy.net candidates get vetting notes only.
- **Rollout:** pilot with Comic Book Plus or Digital Comic Museum if one passes vetting for automated access, else Gutenberg; about 50 titles in the local harvest database; then a report and a stop for the owner's approval before more sources, bigger batches, deployment or promotion.
- **F9 (D2-16):** change the Open Library rating display to read "4.1 on Open Library (2,345 ratings)" with a link, kept visually separate from readers' reviews. Nothing is turned on.
- Tests per the brief's Part I: the license gate (an ambiguous record is never downloaded), dedupe, quarantine of bad files, promotion, the excluded-host guard, and the circuit breaker.

## Phase 9: Official platforms and creator licensing [F7, F8]

Tasks: [`mp2-phase-9-platforms-licensing.md`](mp2-phase-9-platforms-licensing.md)

- **F7:** `platform_links` (media_id, platform, series_url, episode_url_template, regions) and `user_follows` (user_id, media_id, platform, last_episode_read, notify, plus `opened_episode`/`opened_at` for the return prompt). Platforms come from config, each `confirmed=false` until its URL structure and terms are checked; unconfirmed platforms stay hidden. "Continue on Webtoon, Episode 43" opens that episode in a new tab, or the series page with a note when there is no stable episode URL. On return, ask once "Did you finish Episode 43?" (one tap updates progress; manual setting allowed). New episodes come from official RSS/APIs or MangaUpdates/AniList release data, never scraped pages; notifications are in-app, plus email for opted-in readers only. Library and Continue Reading show hosted titles, uploads and followed series together, labelled "Reads in Bookflix", "Your file" or "On Tapas". Platform names as text. Existing "Where to read" rows are copied into `platform_links` (D2-20). Add the privacy policy section.
- **F8:** a public "Publish on Bookflix" page with an application form (post/redirect/get to a thank-you page) feeding an admin review tab under Catalog. Add a `content_licenses` table (licensor, works covered via pivot, rights `stream` or `stream_download`, territories as ISO codes, start/end dates, revenue terms text, agreement file on a private disk, approved_by). Licensed files are uploaded by admins only. `ContentAccessGate` checks for an active licence covering the reader's country (D2-21). A scheduled job unpublishes expired works and alerts admins three weeks before expiry (configurable). No contract text is drafted.
- Tests per the brief's Part I: an expired or out-of-region licence blocks reading; followed-series progress updates from the return prompt; unconfirmed platforms never render.

## Phase 10: Motion, design-tells audit and final report [G, H, I]

Tasks: [`mp2-phase-10-motion-report.md`](mp2-phase-10-motion-report.md)

- **G:** duration and easing tokens per the brief (micro 100–150 ms, UI 200–250 ms, pages and modals 250–350 ms; D2-22); hard-coded durations moved onto tokens. Hover/focus/press states: cover tiles scale 1.03–1.05 with a surface step, buttons press to 0.98, touch and keyboard equivalents. Micro-interactions: switch slide, save check mark, chevron rotation, heart fill. `wire:loading` on every Livewire action. Skeletons shaped like shelves, story pages, tables and charts, shown only after about 300 ms. The navigate progress bar recoloured from tokens. A few one-time reveals (8–16 px); parallax on the hero only, off under reduced motion. Dialogs and drawers fade/scale with focus in and back. A cross-fade between `wire:navigate` pages. Nothing animates layout.
- **H (report only):** each design tell with a file or screenshot reference and a recommended fix. Include the font finding (Netflix Sans named but never shipped; Instrument Sans loaded but unused) with a licensed pairing proposal, the hero copy, the live-demo gap, and whether every landing number comes from a query. Change nothing beyond Parts A–G.
- **I:** the cumulative report across all ten phases in the brief's table shape (Fixed / Already OK / Not applicable / Needs decision), then open questions, every `TODO(owner)` left in code, and out-of-scope notes (for example the failing sync and the unavailable ML embed model seen in admin).

---

# Part G: Delivery loop (every phase)

1. **Branch:** `mp2-phase-N-<slug>` from `main`; or from the previous phase's tip while its PR is open (D2-24), rebasing onto `origin/main` before opening the PR.
2. **Ledger first:** `docs/superpowers/plans/mp2-phase-N-ledger.md`. It holds the audit (each brief item, what the code does now, the plan), the conflict scan (one row per task pair sharing a file or interface, one row per task against itself), every ruling with its cost if wrong, every deferred minor, and the commit range per task. The ledger is append-only and is what survives compaction.
3. **Per task:** TDD (failing test, minimal code, pass), Pint, one focused commit. No `Co-Authored-By`, no AI attribution.
4. **Per-task review** by a fresh reviewer naming the specific risk (for example "check the throttle test proves a 429, not a validation redirect"). Fix loop capped at two rounds; the implementer fixes, not the controller.
5. **Phase exit:** `composer test` green; touched browser files green; whole-branch review on the most capable model; one fix wave (a regression the fix wave introduces gets fixed too); the Part I report table in the PR body; PR opened, never merged by the agent.
6. **Screenshots for UI changes:** before touching a page, capture "before" screenshots from the branch's starting point; after the task, capture "after" screenshots with the same seeded data. Use viewports 375×667, 768×1024 and 1280×800, and the Pest browser plugin's screenshot support or the Playwright CLI. Save them as JPEG (quality about 70) to `docs/screenshots/mp2-phase-N/<task>/{before,after}-<page>-<width>.jpg` and commit them. The PR body shows each before/after pair side by side, linked by commit SHA (`https://github.com/NightServant/bookflix/blob/<sha>/<path>?raw=true`) so the links survive the branch being deleted. Factory-seeded data only, never a real account. Backend-only tasks need none.
7. **Two subagents per session, at most:** one implementer (Sonnet), reused for every task through SendMessage, and one reviewer, reused for the per-task reviews and the whole-branch review. The main session coordinates, takes the screenshots, runs the checks and commits. No other subagents.
8. **Never:** call a real AI provider, fetch content files from a source that isn't approved, run destructive database commands, push to `main`, edit `.env`.

**Environment for a cloud session.** PHP 8.4 with the extensions CI uses, Node 22, and PostgreSQL 17 with pgvector (user `postgres`/`postgres`, database `bookflix_test`), as in `.github/workflows/tests.yml`. Run `composer setup` (it copies `.env.example`), then `npx playwright install --with-deps chromium --only-shell` for browser tests. Redis is not needed for tests (`phpunit.xml` uses array/sync drivers).

---

# Part H: Brief coverage matrix

| Brief | Phase / task |
|---|---|
| 0 Decisions, How to work, Priority order | Global Constraints, Part B, Part G, phase order |
| A1 nav, A2 FAQ, A3 settings | Phase 4 |
| A4 tables, A5 overview, A6 sidebar + admin settings | Phase 5 |
| B broken links, logo | 2.1 |
| B buttons, placeholders, phone/email | 2.5 sweep (A.2: OK / N/A) |
| B overflow | 2.2 |
| B mobile menu | 2.3, 2.4 |
| B real phone check | 2.5 |
| C1 error pages, thank-you, breadcrumbs, internal links | Phase 6 |
| C1 privacy page | Phase 3 |
| C2 SEO, images | Phase 6 |
| C3 copyright | Already OK (A.3) |
| C4 CTA above fold, sticky CTA | Phase 4 |
| C4 form messages, analytics | Phase 6 |
| D secrets | 1.5 |
| D auth on routes and Livewire actions | 1.1, 1.2 |
| D database and storage access | 1.4, Phase 1 exit (Needs decision items) |
| D input validation | 1.4, 1.6 |
| D open endpoints, rate limits | 1.3, Phase 1 exit inventory |
| E (all) | Phase 3 (followed-series section in Phase 9) |
| F1 reader, F2 help, F6 uploads | Phase 7 |
| F3 sources, F4 exclusions, F5 harvest, F9 ratings | Phase 8 |
| F7 platforms, F8 licensing | Phase 9 |
| F10 references | Phase 4 (first) |
| G motion, H audit, I report | Phase 10 (each phase also reports its own items) |
| I tests: admin auth, Livewire actions | 1.1, 1.2 |
| I tests: forms, 404 | Phase 6 |
| I tests: FAQ | Phase 4 |
| I tests: signed reader URLs, uploads | Phase 7 (existing tests audited) |
| I tests: AI self-harm | Phase 3 |
| I tests: viewports 375×667, 768×1024, 1280×800, no sideways scroll | 2.2 |
| I tests: licensing, platforms | Phase 9 |
| I tests: harvester | Phase 8 |
| I PHPStan, no secrets in diff | `composer test` every phase; 1.5 CI job |
