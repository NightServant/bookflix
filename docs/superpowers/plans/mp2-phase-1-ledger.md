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
