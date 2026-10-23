# Phase 1 execution ledger (append-only)

Plan: `2026-10-22-bookflix-master-plan.md` Part D (lines 831–1490). Branch: `phase-1-foundation`, cut from `d9fd4f6`, the tip of PR #2. It gets rebased onto `main` once PR #2 is merged.

## Conflict scan
| Pair / task | Shared file or interface | Ruling |
|---|---|---|
| 1.1 × 1.8 | `.github/workflows/tests.yml` | Run sequentially; 1.8 adds a separate `ml` job |
| 1.1 (self) | step 7 PHP 8.4 bump | Already done in PR #2; skip it |
| 1.1 (self) | step 8 copies the spec into the repo | **Skip.** The repo went public after the plan was written, and copying the spec would publish the user's document. Executors read it from its absolute path |
| 1.2 × 1.3 | layouts, `EmailVerificationTest` | Sequential, so the 1.2 → 1.3 retarget chain holds |
| 1.3 × 1.6 × 1.9 | `bootstrap/app.php` web middleware | Sequential; each change appends |
| 1.4 × 1.5 × 1.6 × 1.7 | `User.php`, `UserFactory.php` | Sequential |
| 1.5 × 1.7 | `profiles` migration holds the privacy columns | Same batch |
| 1.5 × 1.6 | `config/bookflix.php`, `⚡profile.blade.php` include | 1.5 creates the config; 1.6 renames the include |
| 1.8 × 1.9 | `MlClient`, `tests/Pest.php` | Sequential |
| 1.6 (self) | removing the two delete-account tests from `ProfileUpdateTest` | Allowed: deactivation tests replace them, and the user approved D-3 by starting Phase 1 |
| A4 deps | `league/flysystem-aws-s3-v3`, Nightwatch | **Defer.** No Phase 1 test needs them; add at deploy setup |

## Rulings (with the cost if wrong)
- R1. Tasks are dispatched in batches to save tokens: {1.1}, {1.2+1.3}, {1.4}, {1.5+1.7}, {1.6}, {1.8}, {1.9}. Cost: coarser review granularity, so a rejected batch means a bigger redo.
- R2. Spec not copied into the repo (see scan). Cost: executors need the local absolute path.
- R3. S3 adapter and Nightwatch deferred. Cost: one more dependency PR before the first deploy.
- R4. Implementers and task reviewers run on Sonnet; the final branch review runs on Opus.

## Tasks
| Batch | Commits | Status | Open findings |
|---|---|---|---|
| 1.1 | `ffee0c5` | DONE; controller-reviewed (CI env vs phpunit, `down()`, no co-author) | CI Postgres health check is only proven on the PR run |
| 1.2+1.3 | `8c8ff11`, `e81cd5a` | DONE; spot-reviewed (routes, Fortify home, theme forcing) | Finding F1: disabled "Add a book" dead button on Home → sent back to the implementer |

- R5. Dark mode is forced via `localStorage.setItem('flux.appearance','dark')` before `@fluxAppearance` (implementer deviation, accepted). Without it, Flux removes `dark` for users whose OS is in light mode. Cost if wrong: a flash of the wrong theme, or Flux overriding it after an upgrade.
- R6. The 1.2/1.3 implementer is resumed for the F1 fix plus Task 1.4, instead of a fresh agent. That saves a context bootstrap. Cost: no independent context for 1.4, though 1.4 is small and its tests are fully specified.
- F1 fixed in `b47bcd6` (CTA now links to `profile.edit`; checked with a component-level assertion). Task 1.4 is `87fff2a`: DONE, 44 tests pass. Deviation accepted: `User::$attributes = ['is_admin' => false]`, because a fresh model had `is_admin` null and the gate returned 500 instead of 403.
- Tasks 1.5 (`bebc1b7`) and 1.7 (`2caf8f8`): DONE, 54 tests pass. Accepted: enums moved to `app/Enums`, the retention-days config cast to int, `Locale::getDisplayLanguage` labels. Finding F2: avatars were stored on the private `local` disk and never rendered, so they're sent back to the same implementer (it also does 1.6, per R6). The fix uses `FILESYSTEM_DISK=public` locally, `storage:link` in setup, and renders the avatar.
- F2 fixed in `b4cad88` (`User::avatarUrl()`, public disk locally, `storage:link` in setup). Task 1.6 is `99539ce`: DONE, 60 tests pass. Passkey and remember-me logins by deactivated users are caught by the middleware on the next request. Findings F3 (custom `authenticateUsing` skipped the rehash on login) and F4 (no deactivation notice on the middleware redirect) were sent back to the implementer.
- F3 and F4 fixed in `12cfaec` (`rehashPasswordIfRequired` in `authenticateUsing`, with a test proven to fail without it; status flash on the deactivation redirect). 61 tests pass.
- R7. The Task 1.8 `docker build` check is skipped because the disk has only about 3.1 GB free; the service is verified with a local `uv run uvicorn` + `curl` instead. Cost if wrong: Dockerfile errors surface at the first deploy.
- Tasks 1.8 (`9d7e5f3`) and 1.9 (`a37c438`): DONE. 71 Pest tests and 5 pytest tests pass; ruff, mypy (strict) and PHPStan are clean; uvicorn smoke check OK; `docker build` skipped (R7). Accepted: `AssignRequestId` and `SetSecurityHeaders` are appended before `EnsureAccountIsActive`, so the deactivation redirect also carries the request ID and the security headers (test added). mypy covers `tests/`; `ml/.gitignore` added; uv `[dependency-groups]`.
- Deferred minor: the Starlette deprecation warning about the test-client HTTP library. Revisit when the FastAPI deps get upgraded.
- Q5 stands: no reactivation path, and the copy tells users about the 30-day deletion.
- Accepted deviations from 1.2/1.3: the sidebar layout is deleted; the base border is now `--color-hairline`; body classes are `bg-canvas text-ink`; extra `dashboard` retargets in `passkey-verify` and `⚡profile`.
