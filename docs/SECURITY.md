# Security

What Bookflix does to protect readers and the catalog, what does not apply to this app and why, and what the owner has to set up outside the code.
Every measure below has a test; the pointer after it says where.

## Applied

| Area | What is done | Where |
|---|---|---|
| Sign-in and sessions | Fortify email and password, email verification, two-factor and passkeys. The session is checked on the server by `auth`; sign-out and deactivation delete the session rows | `app/Providers/FortifyServiceProvider.php`, `config/fortify.php` |
| Every route is guarded | Each route carries `auth`, or is on a short, commented public list (landing, health check, covers, guest sign-in and reset, Livewire and Flux assets). A new route without `auth` fails the build | `tests/Feature/Security/RouteAuthCoverageTest.php` |
| Admin | Routes under `auth`, `verified` and `can:access-admin`; every admin page and action re-checks the gate; id properties are `#[Locked]` | `routes/admin.php`, `tests/Feature/Security/AdminRouteGuardTest.php`, `tests/Feature/Admin/AdminAccessTest.php` |
| Livewire updates | `verified`, `reader`, `onboarded` and `password.confirm` are replayed on every Livewire update, as the page route ran them | `app/Providers/AppServiceProvider.php`, `app/Http/Middleware/RequirePassword.php`, `tests/Feature/Security/LivewirePersistentMiddlewareTest.php` |
| Passwords | One shared rule set (`PasswordValidationRules`): in production at least 12 characters, mixed case, letters, numbers, symbols and not in a known leak (`uncompromised()`); in every environment at most 72 bytes, because bcrypt ignores the rest | `app/Concerns/PasswordValidationRules.php`, `app/Providers/AppServiceProvider.php`, `tests/Feature/Security/PasswordMaxBytesTest.php` |
| Rate limits | Sign-in, two-factor, passkeys, sign-up, password reset, password confirmation, search, the assistant, uploads, reports, reviews, verification resend and the reader and beacon routes | `app/Providers/FortifyServiceProvider.php`, `app/Providers/AppServiceProvider.php`, `tests/Feature/Security/AuthThrottleTest.php` |
| Form input | A list posted for a text field of an auth form is a validation error, not a crash. Every Livewire action and controller validates or re-checks the typed id it takes | `app/Http/Middleware/RejectNonStringAuthFields.php`, `tests/Feature/Security/AuthFieldTypeTest.php` |
| Request size | The JSON and beacon routes (reader progress, recommendation feedback, library add) refuse a body past 8 KB with 413. The legitimate maximum is about 2.5 KB. PHP's `post_max_size` and Livewire's own payload caps cover everything else | `app/Http/Middleware/LimitRequestBody.php`, `tests/Feature/Security/RequestBodyLimitTest.php` |
| Headers | `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin` (needed for hotlink-protected covers), `Permissions-Policy` denying camera, microphone, geolocation, payment and USB, a nonce-based CSP, and HSTS for two years with `includeSubDomains` on https outside the local environment. No `preload` | `app/Http/Middleware/SetSecurityHeaders.php`, `app/Http/Middleware/SetContentSecurityPolicy.php`, `tests/Feature/Http/SecurityHeadersTest.php` |
| Files | Reader files, comic pages and exports are served by signed, user-bound, throttled routes. The `local` disk is not served. Uploads are typed from their bytes, capped per format and by quota, and archives are checked against traversal, links and zip bombs. SVG is not a preview type | `routes/reader.php`, `config/filesystems.php`, `app/Reader/Archives/ZipGuard.php` |
| Output | Blade escapes. The only unescaped output is the server-made two-factor QR code. User text in reviews goes through `Markdown::safe` | `resources/views/pages/settings/⚡two-factor-setup-modal.blade.php` |
| Database | No raw SQL takes request input; every dynamic value is bound. Models list `$fillable`; none uses `$guarded = []` | checked in the Phase 1 inventory |
| Secrets | Nothing secret in the repository or the JavaScript bundle (only `VITE_APP_NAME`). CI scans the full history with gitleaks | `.github/workflows/tests.yml` (job `security`) |
| Security log | Failed sign-ins, lockouts, 429s and 403s (Livewire updates included) are written to `storage/logs/security.log`, one line each: event, route name, method, IP, status, and the user id when known. A failed sign-in carries a 12-character SHA-256 prefix of the lower-cased email, never the email. Never a password, token, code, header or request body. Kept 30 days (`LOG_SECURITY_DAYS`) | `app/Support/SecurityLog.php`, `config/logging.php`, `bootstrap/app.php`, `tests/Feature/Security/SecurityLogTest.php` |

## Does not apply, and why

| Measure | Why not |
|---|---|
| Row-level security in the database | The app uses one database role and scopes every user-owned read and write in code (policies, `ownChats()`, `$user->collections()`, `visibleTo()`). RLS would need a per-request database role or session variable on every pooled connection. Database-level enforcement is an owner decision |
| A client-side login throttle | The server limiter already gives the "try again in N seconds" message; a client-only layer adds JavaScript and no protection |
| SECURITY DEFINER function hardening | The only database function is `immutable_unaccent`, a plain immutable SQL function |
| SSRF filtering of user URLs | No user-supplied URL is ever fetched. Provider and cover fetches only use `https` URLs on configured hosts, never follow redirects and cap size and type |
| CSV formula-injection guard | The app exports no CSV; the data export is JSON in a zip |
| Open-redirect protection | No request parameter feeds a redirect; the intended URL is kept in the server-side session |
| A `?next=` return URL | Not used |

## Owner-side controls (outside the code)

- **Host and proxy.** Set `trustProxies(at: ...)` to the proxies in front of the app and `SESSION_SECURE_COOKIE=true` in production. Until the proxy list is set, requests behind a TLS-terminating proxy look like plain http, so HSTS is not sent and the secure cookie flag is not applied. This is the first thing to do at deployment.
- **HSTS preload.** The header carries no `preload` directive because preload is hard to undo. Add it, and submit the domain, only when every subdomain is permanently https.
- **WAF or CAPTCHA.** None is built in. Add one at the host (or in front of sign-up and password reset) if abuse shows up in the security log.
- **Database role.** Run the app as a role that owns only its own schema, with no superuser rights, and keep the connection string server-side. Development uses the local `postgres` user.
- **Bucket privacy.** Keep reader files, comic pages and exports on a private disk or bucket and reach them only through the signed routes. Avatars and covers are public by design.
- **Dependency upgrades.** `composer audit` and `npm audit` currently report advisories (listed in the Phase 1 ledger, "Task 1.5 findings"); they are not in CI until the upgrades are approved and done.
- **Leaked-password check.** `uncompromised()` runs in production only, and sends a 5-character hash prefix of the password to the Have I Been Pwned range API (the password never leaves the server). The host must allow that outbound request; if it cannot, sign-up and reset fail.
- **Log shipping.** `storage/logs/security.log` is a daily file kept 30 days. Ship it to wherever logs are watched if alerts on repeated 403s or 429s are wanted.
