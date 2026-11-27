# Masterplan 2, Phase 1 ledger: Security [D]

Append-only. Branch `mp2-phase-1-security` from `main` 43ef54d. Plan: `docs/superpowers/plans/2026-11-26-bookflix-masterplan-2.md` Part D. Brief: `docs/superpowers/specs/2026-11-26-cleanup-brief.md` Part D.

Session: cloud container, PHP 8.4.28, PostgreSQL 17.12 + pgvector (`bookflix_test`), Node 22, Playwright 1.64.1 (Chromium headless shell 1248). Two subagents for the whole session (Part G step 7): one Sonnet implementer, one reviewer.

## Audit (2026-11-27, re-checked against `main` 43ef54d)

| Brief [D] item | What the code does now | Plan |
|---|---|---|
| Secrets in client code or the repo | `.env` gitignored; only `VITE_APP_NAME` carries `VITE_`; no secret scan, `composer audit` or `npm audit` in CI | 1.5: gitleaks over tree + history, audits, CI `security` job |
| Admin routes behind auth, verified, admin gate | All 13 `admin.*` routes in `routes/admin.php` share `auth, verified, can:access-admin`; every admin page calls `Gate::authorize('access-admin')` in `boot()` | 1.2: a test that enumerates every `admin.*` route, guest + reader per page |
| Livewire actions authorize on their own | Admin pages re-check the gate in `boot()` (runs on every update). Livewire re-runs only `auth` and `can:` on `/livewire/update`; `verified`, `reader`, `onboarded`, `password.confirm` are not persistent | 1.1: add the four to persistent middleware; 1.2: per-action tests for a demoted admin |
| IDs locked with `#[Locked]` | Unlocked: classifications `rowId`, `mediaId`; content `verifying`; duplicates `comparing`; staff-picks `storyIds` (staff-picks `editingId` is already locked) | 1.2: lock the five unless the browser writes them |
| Pest tests for admin routes and actions as guest and reader | Hand-listed datasets in `tests/Feature/Admin/AdminOnlyAccessTest.php`; no enumeration, no per-action tests for moderation, models, staff-picks | 1.2 |
| Least-privilege DB role, preview creds, no connection string on the client | No production deployment in the repo; dev uses local Postgres as `postgres` | Needs decision (D2-6), with a recommended role in the PR |
| Private buckets, short-lived signed URLs | Reader files and comic pages: signed 10 min, user-bound, throttled, `ContentAccessGate`; exports signed + owner check. `local` disk has `'serve' => true` with no caller found | 1.4: `serve` off; buckets Needs decision (no bucket yet) |
| Every form/upload/query param validated | Uploads check type from bytes, per-format caps, quota, hourly limit, server names; `ZipGuard` covers traversal, symlink, entry count, ratio, size. Livewire `preview_mimes` includes `svg` | 1.4: drop `svg`; 1.6: archive tests + validation inventory |
| No `{!! !!}` for user content | One: the server-made 2FA QR SVG (locked prop) | Already OK; re-confirm in 1.6 |
| `$fillable`, whitelisted sort/filter | No `$guarded = []`; library sort whitelisted; `KeywordLeg::similar()` interpolates only the class constants `TITLE` / `ALTERNATIVE_TITLES` (lines 35-36, 83-92) | 1.6: record OK after tracing |
| Open endpoints listed; user data needs auth | No `routes/api.php`. Public: landing, `/up`, passkey well-known, covers proxy, Fortify auth. `_boost/browser-logs` exists only with Boost in local. ML service needs a >= 32-char bearer token | Inventory in the PR body |
| Rate limits: login, registration, reset, search, Ask | OK: login, two-factor, passkeys, search, Ask, uploads, reports, reviews, verification resend, reader stream/progress. Missing: `register.store`, `password.email`, `password.update`, `password.confirm.store` | 1.3 |
| Headers | nosniff, Referrer-Policy, X-Frame-Options, Permissions-Policy, nonce CSP. No HSTS. No TrustProxies (no host) | 1.4: HSTS on secure requests; TrustProxies + secure cookie Needs decision |

## Conflict scan

| Pair | Shared file or interface | Resolution |
|---|---|---|
| 1.1 × 1.1 | `AppServiceProvider::boot()` only | none |
| 1.1 × 1.2 | Both exercise admin Livewire pages; 1.1 edits `AppServiceProvider`, 1.2 edits admin page files and tests | Run 1.1 first so 1.2's tests run with the new persistent middleware |
| 1.1 × 1.3 | 1.1 adds `RequirePassword` to persistent middleware; 1.3 throttles `password.confirm.store` | Different files; no ordering need |
| 1.2 × 1.2 | Four admin page files + `AdminAccessTest.php` | none |
| 1.3 × 1.3 | `FortifyServiceProvider`, `config/fortify.php` | none |
| 1.4 × 1.4 | `SetSecurityHeaders`, `config/filesystems.php`, `config/livewire.php`, `tests/Feature/Http/SecurityHeadersTest.php` (existing; extended, not duplicated) | none |
| 1.4 × 1.6 | Both touch upload surface (1.4 preview mimes; 1.6 archive tests) | Different files |
| 1.5 × 1.5 | `.github/workflows/tests.yml`, maybe `.gitleaks.toml` | none |
| 1.5 × others | None share files | none |
| 1.6 × 1.6 | `tests/Feature/Reader/ZipGuardTest.php` (exists), maybe `KeywordLeg.php` | none |

## Rulings

| # | Ruling | Cost if wrong |
|---|---|---|
| P1-R1 | The plan names `tests/Feature/Admin/AdminAccessTest.php` for the per-action dataset; it exists (alongside `AdminOnlyAccessTest.php`), so the dataset goes there. | None. |
| P1-R2 | Task 1.4's headers test extends the existing `tests/Feature/Http/SecurityHeadersTest.php` instead of creating `tests/Feature/Security/SecurityHeadersTest.php` (the plan says extend if one exists). | None. |
| P1-R3 | Composer could not download dist zips for public repos outside this session's GitHub scope (the egress proxy answers 403); dependencies were installed with `--prefer-source` and `phpstan/phpstan` from a `git archive` of the locked commit. Setup only; nothing in the repo changed. | None for the code; CI installs normally. |

## Deferred minors

(none yet)

## Needs decision

(filled in as tasks run)

## Task log

| Task | Status | Commits | Notes |
|---|---|---|---|
| 1.1 Livewire persistent middleware | pending | | |
| 1.2 Admin routes, actions, locked ids | pending | | |
| 1.3 Auth-form throttles | pending | | |
| 1.4 HSTS, private disk, SVG previews | pending | | |
| 1.5 Secret scan, CI security job | pending | | |
| 1.6 Input surface checks | pending | | |

## Task 1.5 findings (2026-11-27)

gitleaks v8.30.1, built with `go install github.com/zricethezav/gitleaks/v8@v8.30.1` (module checksum `h1:PmEvCfVI7ti9dV3s5aMZUY7sS2GxRvG3yzih7E+cS3w=`, verified by Go's checksum database). The release tarball could not be used: this session's egress proxy only serves GitHub repositories attached to the session, and attaching gitleaks was not allowed, so its `checksums.txt` could not be read to pin.

| Scan | Scope | Result |
|---|---|---|
| `gitleaks git --redact --log-opts=--all .` | Full history after `git fetch --unshallow` plus every remote branch (6) and every PR head (41): 616 commits | No leaks |
| `gitleaks dir --redact .` | Working tree, 1.08 GB including gitignored `vendor/` and `node_modules/` | 185 findings, all in gitignored third-party `vendor/` files (package test fixtures and docs). Tracked files: 0 |

No real secret, so no rotation item. No `.gitleaks.toml` needed (no false positive in tracked files or history).

Dependency audits (Step 3), not clean, so both stay out of the CI job (Needs decision; upgrading needs owner approval):

`composer audit --locked`: 27 advisories in 9 packages.
- laravel/framework 13.38.0 (low, CVE-2026-128083, Concurrency process output injection, fixed in 13.40.0).
- phpunit/phpunit (medium, CVE-2026-119193, shared temp dir, dev only).
- symfony/http-foundation (5), http-kernel (2), mailer (2), mime (1, medium), routing (3), serializer (5), yaml (1): fixed in Symfony 8.1.11/8.1.12.
- web-auth/webauthn-lib 5.3.9 (6 advisories, no CVE, fixed in 5.3.13; passkeys use it).

`npm audit --omit=dev --audit-level=high`: 7 (3 critical, 4 moderate), all through the build tool `vite-plus` (a direct dependency): tinypool (prototype pollution to RCE in worker options), oxfmt, vitest, @vitest/browser, @vitest/browser-preview, @vitest/mocker. The fix is a breaking `vite-plus` upgrade. Build-time tooling, not shipped to browsers.

## Rulings (continued, 2026-11-27)

| # | Ruling | Cost if wrong |
|---|---|---|
| P1-R4 | (1.1) `EnsureReaderAccount` answers 403 when the request carries `X-Livewire`. Livewire replays persistent middleware on a duplicate of the update request with the page's GET method, and turns a redirect into an aborted 302, so without this an admin's replayed reader snapshot got a redirect instead of the refusal the class docblock promises. The header is always present on `/livewire/update` (`RequireLivewireHeaders`); `wire:navigate` sends a different header. | Low: a replayed admin update on a reader route 403s instead of redirecting, which is the intent. |
| P1-R5 | (1.1) The admin-replay test also passes through the existing `guardReaderComponentsFromAdmins` hydrate hook; with the change the 403 comes earlier, from `EnsureReaderAccount` (snapshot-verified fires before hydrate). The unverified-reader replay is the test that fails without the persistent middleware. | None; two layers. |
| P1-R6 | (1.1 review, blocking) Livewire's `Utils::applyMiddleware` stops an update only when middleware throws or redirects. The framework `RequirePassword` returns a plain 423 JSON response to JSON clients, which Livewire discards, so a replayed security-page snapshot with `Accept: application/json` and a lapsed confirmation could still turn 2FA off. Fix: `App\Http\Middleware\RequirePassword` extends the framework class and aborts 423 for JSON clients; `password.confirm` is aliased to it and it is the class registered as persistent (the plan's interface named the framework class). | Low: Fortify's own JSON clients still get 423. |
| P1-R7 | (1.2) The demoted-admin rows seed real rows lazily, though `boot()` refuses before any lookup (the reviewer confirmed bogus ids also 403). `addStory`/`removeStory`/`moveStory` rely on the `boot()` gate, which runs on every update; no per-method gate added. | Low: a future refactor that drops `boot()`'s gate is caught by the dataset. |
| P1-R8 | (1.2) All five admin id properties are locked; nothing in the browser writes them (grep of views and `resources/js`, confirmed by review), and every action still re-finds its row. | None. |
| P1-R9 | (1.3) Each `auth-writes` limit has its own key prefix; GET/HEAD and every route with its own limiter get `Limit::none()` (Laravel's `ThrottleRequests` skips `Unlimited` entirely: no count, no headers). The reset broker's once-a-minute wait for existing accounts is a validation error, not a 429. | Low. |
| P1-R10 | (1.3, flagged by review) Sign-up is 5 posts per minute per IP and counts failed validations, so a school, office or carrier NAT could see a 429 on the sixth sign-up post in a minute from anyone behind it. The limit is the plan's; kept. | Medium for shared networks; easy to raise in one line. |

## Task 1.6 findings (2026-11-27)

**Raw SQL.** `KeywordLeg::similar()` is private with two callers (lines 35-36) passing the class constants `TITLE` and `ALTERNATIVE_TITLES`; the query is always a bound `?`. Every other raw fragment in `app/` is a constant or literal (Deduplicator `sprintf(self::NORMALIZE, ...)` over constant columns; Media `TRENDING_BLEND`; `Genre::TREE_IDS_SQL`; Series `window()` interpolates a subquery's own `toSql()` with bound values). No `orderBy($var)` with a variable column; the library sort is a whitelisted `match`. **OK.**

**Archives.** `ZipGuardTest` covers the guard itself; upload-level traversal was already tested. Added upload-level tests for too many entries, unpacking past `max_uncompressed_mb`, and a symlink entry: each is refused with "This file is not a safe archive". All passed at once: no ZipGuard bug.

**Found and fixed:** a list posted for a text field of a Fortify form (`email[]=x`) answered 500 from Fortify's own controllers (`Str::lower` on an array, `lowercase_usernames` on). `App\Http\Middleware\RejectNonStringAuthFields` (Fortify middleware, after `throttle:auth-writes`) turns any present non-string `name, email, password, password_confirmation, current_password, token, code, recovery_code` on a POST into the normal `validation.string` error. Passkey routes post `credential` and are untouched.

**`{!! !!}`**: still only the 2FA QR SVG (`R/pages/settings/⚡two-factor-setup-modal.blade.php:244`). **`$guarded`**: no model uses it.

**Validation inventory** (Step 4). No action lacked validation; every one validates, takes a typed id it re-checks, or only touches the caller's own data.

| Page or controller | Action | How it is validated |
|---|---|---|
| admin/classifications | accept, reject, remove, openChange, openAdd | typed ids, `findOrFail`, `Gate::authorize` |
| admin/classifications | change, add, save | `$code` looked up in `dewey_classes` (no match is a no-op); `$type` via `ClassificationType::tryFrom` or 422 |
| admin/content | save | `VerifyContentSource` runs `Validator::make`; `verifying` locked; row re-read from `listed()` |
| admin/content | edit, unverify | typed id + `listed()->findOrFail` |
| admin/duplicates | compare, dismiss | typed id, `pending()->findOrFail` |
| admin/duplicates | merge | winner id must be one of the pair, else 422 |
| admin/media/edit | save | `validate()` on title, description, age rating, status |
| admin/media/edit | unlock | `$field` only removes a key from `locked_fields` |
| admin/media/edit | regenerate/clear AI summary, remove | no input |
| admin/models | runNow | key must exist in `RunMlJob::PIPELINES` |
| admin/moderation | hide, dismiss, restore | typed id, `findOrFail`, Actions authorize |
| admin/providers | toggleEnabled, syncNow | `syncNow` key must be an enabled provider |
| admin/staff-picks | create, edit, toggle, delete | typed ids re-checked in a scoped query |
| admin/staff-picks | save | `SaveStaffPick` runs `Validator::make`; `storyIds` locked |
| admin/staff-picks | addStory / removeStory, moveStory | `findOrFail` through a visibility scope / typed ints on a locked list |
| assistant | send, ask / rename / addPdfText | `validate()` max length / `Validator::make` / name cleaned, text scrubbed and capped |
| assistant | archive, restore, delete | `ownChats()->findOrFail` |
| collections index, show | create, save | `validate()` |
| collections show | addItem, removeItem, reorder, delete | owner check + typed ids re-checked |
| library-controls | logProgress, saveRating, createCollection | `validate()` |
| library-controls | setStatus / toggleCollection | `ReadingStatus::tryFrom` or validation error / `$user->collections()->find` or 404 |
| library-listener | add | int or digit string, then a visibility-scoped find |
| story-reviews | save, sendReport | `validate()` (reason enum, details max); report target via `match` |
| library/upload | save / delete | `validate()` + `UploadContentSource` (type from bytes, caps, quota) / owner-checked action |
| onboarding | next, finish | `validate()` |
| search | dismissIntent, addIntentFilter, toggleGenre | must match offered chips or genres |
| library index | removeFilter | `match` over three keys |
| settings/profile, preferences, privacy, security | every save | `validate()` |
| settings/privacy | exportData | no input, cooldown limiter |
| settings/security | disable, deletePasskey | Fortify action / typed id owned by the user |
| settings/two-factor-setup | confirmTwoFactor | `#[Validate('required\|string\|size:6')]` |
| settings/deactivate | deactivateAccount | `validate()` current password |
| LibraryController | store | `$request->validate` |
| RecommendationFeedbackController | click, impressions / dismiss, undo | `$request->validate` / route-constrained id + `owned($user, id)` |
| ProgressController | `__invoke` | access gate, then `ProgressWriter` runs `Validator::make` |
| Fortify routes | all | `RejectNonStringAuthFields` + Fortify's own rules |

## Task log (updates)

| Task | Status | Commits | Notes |
|---|---|---|---|
| 1.1 | done, reviewed twice | e0fc43c, fix 189f2fb | Review found the JSON RequirePassword bypass (P1-R6) |
| 1.2 | done, reviewed | 13184dd, nits db69c85, test fix in dd73fcb | 1.2 left `AdminContentVerificationTest` red (forged locked id); fixed in dd73fcb. Lesson: run the full suite per task |
| 1.3 | done, reviewed | b2419b3, fixes db69c85 | Array-email 500 in both limiters fixed |
| 1.4 | done, reviewed | dd73fcb | No HSTS `preload` (owner's call) |
| 1.5 | done | 75d70d3 | Job not required; audits left out (Needs decision) |
| 1.6 | done | 0a24304 | Fortify array-field 500 fixed; inventory above |

**Correction (Task 1.6 review):** in the inventory, admin/classifications `change`/`add` with an unknown code is not a silent no-op: `classWithCode()` adds a `code` error ("Choose a class from the list."), nothing is written, and the dialog stays open. Also `admin/providers toggleEnabled` takes a typed id through `providers()->findOrFail`.

**Security review (Phase 1 exit), done inline by the coordinator** (the built-in `/security-review` was not used because it may start its own agents and this session is limited to two). Scope: the branch's code diff (15 files, 162 lines). No exploitable findings: limiter keys are prefixed and include the IP for email buckets, so nobody can lock someone else out of reset; reset tokens make email rotation pointless on `password.update`; the auth-field guard runs only on POST and only on Fortify routes; the `RequirePassword` subclass keeps browser behaviour; HSTS only on secure requests; the CI job has a read-only token, exposes no secrets to fork PRs, and pins the gitleaks module checksum.

## Whole-branch review (Phase 1 exit, origin/main...591a438)

No blocking findings. Checked: middleware order on Fortify routes (`web` → `throttle:auth-writes` → `RejectNonStringAuthFields` → route middleware; nothing counted twice); end-to-end password reset; passkey registration and login through the new stack; 2FA setup and challenge; verification; onboarding; deactivation; export; uploads; reader stream/progress; assistant. Reader-side Livewire writes sampled (collections, library-controls, story-reviews, assistant, upload delete, library-listener, reader page URLs): all scoped or authorized. CI `security` job: safe for fork PRs (`pull_request`, read-only token, no secrets, `--redact`); relies on the runner image's Go plus `GOTOOLCHAIN=auto` (not a required check).

Fix wave (one, per Part G): admin actions replayed over HTTP as a guest and as a reader (lock-in tests: Livewire's default persistent list already has `auth` and `can:`); PR report wording corrected (open-endpoint table: preview-file has no throttle, passkey login and static asset rows added; the demoted-admin bullet names the actions it covers).

Gate note: the first full run stalled because two `composer test` processes ended up on the same database (an earlier run survived a `pkill`); both were killed and the gate re-run in a separate worktree at 666473d. Not a code problem.

## Task 1.7: Worktrack security measures (added by the owner, 2026-11-27)

The owner asked to apply the security measures another session (Worktrack, a Supabase/Next.js app) listed, where they fit Bookflix (Laravel 13, Fortify, Postgres, Livewire). Audit first, against 9132e22:

| # | Measure | Bookflix today | Decision |
|---|---|---|---|
| 1 | Row-level security on user-owned tables | One app database role; every user-owned read and write is scoped in code (policies for Collection, ContentSource, Review; `ownChats()`, `$user->collections()`, `visibleTo()`), checked in the Phase 1 inventory | Not applicable as written (RLS needs a per-request Postgres role or session variable on every pooled connection). Needs decision if the owner wants database-level enforcement too |
| 2 | Server-side route guard that verifies the session | `auth` middleware checks the session against the server-side session store; `EnsureAccountIsActive` in the `web` group; signed-out users go to `login` | Already OK |
| 3 | One shared authenticate() on every API route, plus a source-reading test | No API routes (no `routes/api.php`); the ML service checks a bearer token | Mapped: a route-enumeration test that fails when any route outside an explicit public allow-list lacks `auth` (task 1.7a) |
| 4 | Open-redirect protection on `?next=` / return URLs | No request parameter feeds a redirect anywhere (grep); Laravel's intended URL is stored server-side in the session | Already OK |
| 5 | Credential rules in one module, enforced on the server | Fortify `lowercase_usernames` + the global `TrimStrings` middleware normalise email; `PasswordValidationRules` is the one module; production requires min 12, mixed case, letters, numbers, symbols and `uncompromised()`. No maximum: bcrypt (the default hasher, no `limit`) silently ignores everything past 72 bytes | Fix: a 72-byte maximum in the shared rules (task 1.7b). The composition rules already exceed the Worktrack list in production |
| 6 | Email confirmation on sign-up with a server-side resend limit | Fortify email verification; `verified` on reader routes; `verification.send` throttled 6/min | Already OK |
| 7 | Client-side login throttle (UX only) | Server-side `login` limiter (5/min per email + IP) with the "try again in N seconds" message | Not applied: the server limit already gives the message; a client-only layer adds JS and no protection |
| 8 | SECURITY DEFINER functions pinned and revoked | The only database function is `immutable_unaccent` (plain `language sql immutable`, not SECURITY DEFINER); account deactivation runs through an app action | Not applicable |
| 9 | No public buckets; signed URLs | Phase 1: reader files, comic pages and exports are signed and user-bound; the `local` disk is no longer served; avatars and covers are public by design | Already OK (buckets: Needs decision, Phase 1) |
| 10 | SSRF guard on user-supplied URLs | No user-supplied URL is ever fetched. The cover proxy and `FetchContentFile` only fetch `https` URLs on configured provider hosts, never follow redirects, and cap size and type; the AI and ML clients use URLs from config | Already OK (a host allow-list is stricter than IP filtering) |
| 11 | Per-user rate limits and size caps on external-fetch and AI routes | Ask: `ai-assistant` limiter; covers: `throttle:600,1` + `MAX_BYTES` + image check; outside-review refresh is debounced; Laravel's 429 carries `Retry-After` | Already OK |
| 12 | Upload size limits and magic-byte checks | Reader uploads: type from bytes, per-format caps, quota; avatars: `image` + `mimes` (content-sniffed) + `avatar_max_kb` | Already OK |
| 13 | Request-body cap (413) on JSON/text routes | `ValidatePostSize` (global) answers 413 past `post_max_size`; Livewire caps its payload at 1 MB, 50 calls, depth 10. The JSON beacon routes (reader progress, recommendation feedback) have no cap of their own | Fix: a small body cap on those routes (task 1.7c) |
| 14 | CSV formula-injection guard | No CSV export (the data export is JSON in a zip; the only CSV files are seed data read by the app) | Not applicable |
| 15 | Escape user text in generated formats | Blade escapes; reviews go through `Markdown::safe`; exports are JSON | Already OK |
| 16 | Security headers, with a test | nosniff, X-Frame-Options DENY, nonce CSP, Permissions-Policy (camera, microphone, geolocation), HSTS 1 year on https (Phase 1); `tests/Feature/Http/SecurityHeadersTest.php`. Referrer-Policy is `same-origin`, stricter than `strict-origin-when-cross-origin` and needed for hotlink-protected cover hosts | Fix: Permissions-Policy also denies `payment` and `usb`; HSTS `max-age` two years. Preload stays the owner's call (task 1.7d) |
| 17 | No raw HTML sinks with user content | `{!! !!}` only for the server-made 2FA QR SVG; the one `x-html` renders a server-escaped `<template>` (search filters) | Already OK |
| 18 | No secrets in client bundles; history scan | Phase 1 (gitleaks over 616 commits; only `VITE_APP_NAME`) | Already OK |
| 19 | A security log for auth rejections and throttling, never tokens or bodies | Nothing dedicated | Fix: a `security` log channel and `SecurityLog` helper, fed by failed sign-ins, lockouts, 429s and 403s (task 1.7e) |
| 20 | Auth-config push scripts refuse empty env vars | No such scripts in the repo | Not applicable |
| docs | `docs/SECURITY.md` | None | Fix: applied, not applicable and why, owner-side controls (task 1.7f) |
