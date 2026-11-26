# Phase 3: Legal and Compliance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish a privacy policy and terms written from an inventory of what the code stores and sends, and make the Ask assistant answer messages about self-harm with a warm fixed reply and regional crisis resources instead of a model call or a book search.

**Architecture:** One data inventory (ledger first, then `config('bookflix.legal.*')` and a small `App\Legal\ThirdParties` class) feeds the `/privacy` and `/terms` Blade pages, the Settings "What we send" panel and a guard test, so a new table or third party cannot go undisclosed. A deterministic `App\Ai\Safety\SafetyCheck` runs first inside `AssistantRunner`, and its reply comes from `App\Ai\Safety\CrisisResources` (resources in `config('bookflix.crisis_resources')`).

**Tech Stack:** Laravel 13, Blade (landing layout), Livewire 4 single-file pages, Pest 5 (Feature plus one Browser smoke), Postgres `information_schema` for the inventory guard.

**Spec:** `docs/superpowers/specs/2026-11-26-cleanup-brief.md` Part E (all) and Part C1 (privacy page, "help and FAQ link to the privacy policy") · `docs/superpowers/plans/2026-11-26-bookflix-masterplan-2.md` Part F "Phase 3", Part B D2-13 and D2-19, registry row 3.

**Branch:** `mp2-phase-3-legal` · **Ledger:** `docs/superpowers/plans/mp2-phase-3-ledger.md` · **Depends on:** Phase 1 (throttles and security headers exist; nothing here edits them) and Phase 2 (landing header and footer links already work on phones). Both are consumed as they landed; no registry name from them is used.

## Global Constraints

Every Global Constraint in the masterplan applies. Phase-specific:

- No owner name, email, phone, address or jurisdiction is invented. Unknown values are `config('bookflix.legal.*')` (null by default) or a Blade `{{-- TODO(owner): ... --}}` / PHP `// TODO(owner): ...` comment, and each is listed in the ledger.
- Crisis numbers and URLs are never typed from memory or copied from this plan. They come from the service's own official site at implementation time, with the page URL recorded next to the entry (Task 3.7).
- No code in this phase deletes, hides or anonymises an account or its data. `PurgeDeactivatedAccounts`, `deactivated_retention_days` (30) and the deactivation modal's behaviour stay as they are unless the owner's recorded answer says otherwise (Task 3.5).
- Subscriptions and auto-renewal: no code, no page text. Report rows only.
- The legal pages carry no claim the code does not keep. Every factual sentence cites a row of the ledger inventory (Task 3.1) in a Blade comment.
- Legal links are plain `text-ink` links, never red (`tests/Feature/RedForCtasOnlyTest.php`).
- Tests never call a real AI provider (`Http::fake` or `Http::preventStrayRequests`). Run commands with `env -u AI_AGENT -u CLAUDECODE` on the Mac.
- Copy: plain, specific, few em dashes. Draft with `design:ux-copy` and `humanizer` *(Mac)*; where they are missing, follow this plan's text.

## Review Focus

1. **A person in crisis gets "not available", a book list, or nothing.** The check must run before the hourly limit, the length cap, the kill switch, the Prohibited-content refusal and any tool call, so an exhausted limiter or a dead provider still yields the crisis reply. → Task 3.7 (`ordering` tests).
2. **A book request misfires as a crisis, or a real first-person message slips through.** "Books like 13 Reasons Why" and "a novel about grief after a suicide" must reach normal book help; "I want to kill myself" in any casing or with a curly apostrophe must not. → Task 3.6 (positive and false-positive datasets).
3. **The policy says one retention period and the code does another.** Days on `/privacy`, in the deactivation modal and in `PurgeDeactivatedAccounts` all read `bookflix.privacy.deactivated_retention_days`, and what the purge keeps (search history rows, token counts) is stated, not hidden. → Task 3.5.
4. **A new table, provider host, mail or storage setup goes undisclosed.** A Feature test fails when a table with a foreign key to `users` is missing from the inventory, and when a configured third party host is missing from `/privacy`. → Tasks 3.1 and 3.3.
5. **The crisis exchange leaks later or shows the wrong help.** The message must never go back to the model in a later turn, never reach `Log` or `ai_usage`, show no "Go to Search" nudge, and a reader with an `en-AU` browser must get Australian resources while an unknown region gets the directory entry. → Task 3.7.

## Cross-phase interfaces

**Consumes:** the landing layout `x-layouts::landing` and `x-landing.footer` as they stand after Phase 2; `AssistantRunner`, `AssistantReply`, `AssistantConversation::record()` and the `fixed` flag (Phase 11 of the first plan); `bookflix.privacy.deactivated_retention_days`; `bookflix.ai.*`. Phase 4 later rebuilds Settings, so the shared partial in Task 3.2 is what it must include again.

**Produces (registry row 3, exact):**

- Routes `legal.privacy` (GET `/privacy`) and `legal.terms` (GET `/terms`), public, landing layout, views `resources/views/legal/privacy.blade.php` and `terms.blade.php`.
- Shared sections `resources/views/legal/sections/*.blade.php` (Phases 7 and 9 add to them): `collected`, `ai`, `third-parties`, `deactivation`, `uploads`, `rights`, `contact`, plus the partial `ai-what-we-send`.
- Config `bookflix.legal.operator_name` (env `BOOKFLIX_LEGAL_OPERATOR`, default null), `bookflix.legal.contact_email` (env `BOOKFLIX_LEGAL_CONTACT_EMAIL`, default null), `bookflix.crisis_resources` (keys `US`, `GB`, ... and `default`), `bookflix.crisis_resources_reviewed_at`.
- `App\Ai\Safety\SafetyCheck::assess(string $message): App\Ai\Safety\SafetyVerdict`; `SafetyVerdict::isCrisis(): bool`.
- Additions (not in the registry, later phases may use them): config `bookflix.legal.collected`, `bookflix.legal.ai_sends`, `bookflix.legal.hosting_provider` (env `BOOKFLIX_LEGAL_HOSTING`), `bookflix.legal.updated_at`; `App\Legal\ThirdParties::all()`; `App\Ai\Safety\CrisisResources::regionFor(?Request $request = null): string` (Phase 9 changes only this method to prefer `profiles.country`); `AssistantReply::$crisis`.

## Rulings

- **P3-R1 Fonts are self-hosted.** The masterplan lists Bunny Fonts as a third party, but `laravel-vite-plugin` downloads the font at build time (`public/build/fonts-manifest.json`, `public/build/assets/instrument-sans-*.woff2`) and the CSP is `font-src 'self' data:` (locked in `tests/Feature/CspTest.php`). The policy says readers' browsers contact no font host; no Bunny entry. *Cost if wrong:* low; the CSP test already guards the claim.
- **P3-R2 Catalog providers get no reader data.** The masterplan says "search terms only", but providers run only as scheduled server jobs (`SyncProvider`, `FetchProviderPage`, `RefreshOutsideReviews`) and no code passes reader input to them. The policy says so and names them. What does reach third parties from a reader's browser is cover images: the CSP allows `img-src https:` and covers load from the provider's CDN unless the source's cover policy is `cache` (served by `CoverController`). Those hosts (`bookflix.providers.*.allowed_hosts`, providers with a class) are listed. *Cost:* low.
- **P3-R3 The pages read config at render.** `ThirdParties::all()` names the AI provider by the host of `bookflix.ai.base_url` only while AI is enabled and configured, the mail host only when `mail.default` is not `log` or `array`, and storage only when a private disk's driver is `s3`. *Cost:* low; a stale static list is the bigger risk.
- **P3-R4 One list of what is sent to the AI service**, `config('bookflix.legal.ai_sends')`, rendered by one partial in Settings and on `/privacy`. *Cost:* low.
- **P3-R5 Detector bias.** SafetyCheck only matches first-person wording (`myself`, `my life`, `I am suicidal`, `I want to die`). Bare words ("suicide"), third person ("she wants to end her life") and titles never match. A hyperbole such as "I'll kill myself if the volume is late" does match and gets the gentle reply. *Cost:* an occasional unneeded gentle reply, preferred over a miss.
- **P3-R6 Only the reader's typed message is assessed**, not attachment text (a pasted story can hold anything). *Cost:* a crisis hidden in an attachment alone goes to normal handling; accepted.
- **P3-R7 Crisis replies reuse the existing chat row.** They are saved like any fixed line (`fixed = true`, so `AssistantConversation::history()` never returns them to the model), with `mode = 'safety'` on the answer so the page can skip the "Go to Search" nudge. No migration. The chat's title still comes from the first message (`AssistantConversation::titleFor`); the reader can rename or delete the chat. *Cost:* the title may show the words on a shared screen; fixing needs a page change the brief does not ask for.
- **P3-R8 The region falls back with a directory.** Browser language is not location, so the reply always adds the `default` entries (a helpline directory) after the region's own. *Cost:* one extra line.
- **P3-R9 The register form says "Read the Terms and the Privacy Policy" with links, not "you agree".** Acceptance wording is a legal commitment the owner has not asked for (`TODO(owner)` in the view). *Cost:* low.
- **P3-R10 Reactivation is stated as operator-assisted.** No self-service reactivation exists (`User::booted` mentions it, no page does), so the policy tells a reader to write to the contact address within the retention window, with `TODO(owner)` to confirm. *Cost:* low.
- **P3-R11 `search_queries` and `ai_usage` keep no retention job.** The purge leaves their rows (`user_id` becomes null: search text and a keyed session hash, token counts). The policy states this. Adding a prune is a retention-behaviour change, recorded "Needs decision" (Task 3.5), not built.

## Baseline re-check

Run first; record each result in the ledger.

- [ ] `git log -1 --format=%h main` and compare with `08bf8ec`; `php artisan route:list --path=privacy` and `--path=terms` both empty (Phase 3 creates them).
- [ ] `grep -n "deactivated_retention_days" -r app config resources` shows the command, config and modal only; `routes/console.php` schedules `bookflix:purge-deactivated-accounts` daily, `prune-exports` daily, `prune-uploads` hourly, `prune-covers` weekly, `model:prune` daily.
- [ ] `grep -rn "Privacy\|Terms" resources/views/components/landing/footer.blade.php resources/views/pages/auth/register.blade.php` is empty; `ls app/Ai/Safety resources/views/legal` fails.
- [ ] `sed -n 140,160p app/Ai/Assistant/AssistantRunner.php` still shows `answer()` starting with the empty-message check, then `withinLimit`, length, `ProhibitedContent`.
- [ ] `grep -n "font-src" tests/Feature/CspTest.php` shows `font-src 'self' data:` (P3-R1); `ls public/build/fonts-manifest.json` exists after a build.
- [ ] `grep -rn "personalizationEnabled" app/Discovery/Search/SearchLogger.php` shows `user_id` is nulled, not the row skipped (P3-R11).

## Tasks

### Task 3.1: Data inventory in the ledger, owner answers, and the inventory guard

**Files:**
- Create: `docs/superpowers/plans/mp2-phase-3-ledger.md` (inventory tables below, owner answers, conflict scan, rulings)
- Modify: `config/bookflix.php` (add `'legal' => [...]` after `'privacy'`; add `.env.example` lines `BOOKFLIX_LEGAL_OPERATOR=`, `BOOKFLIX_LEGAL_CONTACT_EMAIL=`, `BOOKFLIX_LEGAL_HOSTING=`)
- Test: `tests/Feature/Legal/DataInventoryTest.php`

**Interfaces:**
- Produces: `config('bookflix.legal.operator_name'|'contact_email'|'hosting_provider'|'updated_at')`, `config('bookflix.legal.collected')`: list of `['key' => string, 'title' => string, 'what' => string, 'why' => string, 'kept' => string, 'tables' => list<string>]`.

- [ ] **Step 1: Ask the owner, then write the inventory.** In chat ask Q1 (keep the 30-day hard delete, or keep deactivated accounts?) and Q3 (operator name for the policy, contact and takedown address, hosting provider, governing jurisdiction). Record the answers, or "no answer: default" (Q1 default: no change; Q3 default: `TODO(owner)`), in the ledger under "Owner answers" with the date. In the ledger write these inventory tables from the code (re-verify each row with the greps in its last column; the rows below are the audit as of 2026-11-26):

  1. **Personal data tables** (table, columns of note, deleted on purge?): `users` (name, email, password hash, two-factor secrets, `is_admin`, `deactivated_at`; yes), `password_reset_tokens` (email; expires), `sessions` (`user_id`, IP, user agent; `SESSION_LIFETIME` 120 min; deleted at deactivation), `passkeys`, `profiles` (avatar path, bio, headline, languages, personalization and AI flags, `adult_confirmed_at`), `user_library`, `collections` (+items), `ratings`, `reading_progress`, `reading_history`, `user_interactions`, `user_preferences`, `recommendations` (+events), the `ltr_*` tables, `reviews` (soft deletes), `reports` (`reporter_id` cascades), `assistant_conversations` and `assistant_messages` (message text, mode, attachment file names only), `content_sources` (uploads; `owner_user_id` null on delete, the purge deletes uploads and files explicitly), `search_queries` (query text, keyed session hash; written even with personalization off, then with `user_id` null; `nullOnDelete`), `ai_usage` (token counts per feature; `nullOnDelete`).
  2. **Files:** avatars on `bookflix.avatar_disk` (default `public`: readable by anyone with the URL), uploads on `bookflix.reader.disk` (private, signed 10-minute URLs), exports on `bookflix.exports.disk` (private; link 24 h; file 7 days), Livewire temporary uploads (assistant attachments; pruned after 1 hour; only names are saved), cover cache under `covers/` (not personal).
  3. **Logs and caches:** `storage/logs/laravel.log` (`LOG_STACK=single`, `LOG_LEVEL=debug`; exceptions can include an email), AI payload debug log off (`bookflix.ai.log_payloads=false`; with it on and personalization on, message text is logged), rate-limiter keys (IP, email) in the cache, cookies (`laravel_session`, `XSRF-TOKEN`, `remember_web_*`), `localStorage` key `flux.appearance`.
  4. **Retention jobs** (`routes/console.php`): purge deactivated accounts (daily, 30 days), `prune-exports` (7 days), `prune-uploads` (1 hour), `prune-covers`, `model:prune` (recommendations 90 days, `bookflix.recommendations.retention_days`). **No job** for `search_queries`, `ai_usage`, `user_interactions`, `reading_history`.
  5. **Third parties:** AI provider (host of `BOOKFLIX_AI_BASE_URL`; off by default); mail (`MAIL_MAILER`, default `log`; verify-email, reset-password and export-ready mails carry the address); storage (`s3` disk optional); ML service (`ML_SERVICE_URL`, self-hosted FastAPI that reads the same database and receives search words for embeddings); catalog providers (server-side syncs, no reader data, hosts in `bookflix.providers.*.allowed_hosts`); image hosts (covers load in the reader's browser from those hosts, P3-R2); fonts (self-hosted, P3-R1); analytics, error tracking, CDN: none in the repo; hosting and database provider: unknown (`TODO(owner)`).

- [ ] **Step 2: Write the failing guard test** (follow the style of `tests/Feature/Settings/DeactivateAccountTest.php`):

```php
use Illuminate\Support\Facades\DB;

test('every table that points at users is covered by a group of the privacy inventory', function () {
    $linked = collect(DB::select(<<<'SQL'
        select distinct tc.table_name as t from information_schema.table_constraints tc
        join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name and ccu.constraint_schema = tc.constraint_schema
        where tc.constraint_type = 'FOREIGN KEY' and ccu.table_name = 'users' and tc.table_schema = current_schema()
        SQL))->pluck('t')->merge(['users', 'sessions', 'password_reset_tokens'])->unique()->sort()->values()->all();

    $listed = collect(config('bookflix.legal.collected'))->pluck('tables')->flatten()->unique()->sort()->values()->all();

    expect(array_values(array_diff($linked, $listed)))->toBe([]);
});

test('each inventory group says what, why and how long, in plain words', function () {
    expect(config('bookflix.legal.collected'))->not->toBeEmpty();

    foreach (config('bookflix.legal.collected') as $group) {
        expect($group)->toHaveKeys(['key', 'title', 'what', 'why', 'kept', 'tables'])
            ->and(array_filter([$group['what'], $group['why'], $group['kept']], fn ($v) => trim($v) === ''))->toBe([]);
    }
});

test('the operator, contact and hosting values default to unset and are never invented', function () {
    expect(config('bookflix.legal.operator_name'))->toBeNull()
        ->and(config('bookflix.legal.contact_email'))->toBeNull()
        ->and(config('bookflix.legal.hosting_provider'))->toBeNull();
});
```

- [ ] **Step 3: Run to verify it fails.** `vendor/bin/pest tests/Feature/Legal/DataInventoryTest.php`. Expected: FAIL, `config('bookflix.legal.collected')` is null (the diff lists nothing to compare until the key exists).
- [ ] **Step 4: Implement.** Add `bookflix.legal` with the four scalars (`env('BOOKFLIX_LEGAL_OPERATOR')`, `env('BOOKFLIX_LEGAL_CONTACT_EMAIL')`, `env('BOOKFLIX_LEGAL_HOSTING')`, `'updated_at' => '2026-11-26'`) and `collected` as groups: `account` (users, password_reset_tokens, passkeys), `sessions` (sessions), `profile` (profiles, user_preferences), `library` (user_library, collections, collection_items, ratings), `reading` (reading_progress, reading_history, user_interactions), `searches` (search_queries), `recommendations` (recommendations, recommendation_events and the `ltr_*` tables), `community` (reviews, reports), `assistant` (assistant_conversations, assistant_messages), `uploads` (content_sources), `ai_usage` (ai_usage). Re-run the test; the failing diff names any table the list above missed (run `\dt` through `database-schema` if unsure): add it to the right group until green. The `what`, `why`, `kept` strings come from ledger inventory 1-4, in plain words, with "kept" naming the exact days from config values in a comment, not hard-coded numbers that could drift (use `'kept' => 'Until you delete your account.'` style, and leave the 30, 7 and 90 days to the view, which reads config).
- [ ] **Step 5: Run to verify it passes.** `vendor/bin/pest tests/Feature/Legal/DataInventoryTest.php`. Expected: PASS (3).
- [ ] **Step 6: Commit**: `git commit -m "docs: phase 3 data inventory; feat: legal config and inventory guard test"`

### Task 3.2: Third parties and "What we send" from one place

**Files:**
- Create: `app/Legal/ThirdParties.php`, `resources/views/legal/sections/ai-what-we-send.blade.php`
- Modify: `config/bookflix.php` (`legal.ai_sends`), `resources/views/pages/settings/⚡privacy.blade.php` (lines 192-200: replace the hard-coded list with `@include('legal.sections.ai-what-we-send')`)
- Test: `tests/Feature/Legal/ThirdPartiesTest.php`; extend `tests/Feature/Settings/PrivacySettingsTest.php`

**Interfaces:**
- Produces: `App\Legal\ThirdParties::all(): list<array{key: string, name: string, hosts: list<string>, role: string, data: string}>`; `config('bookflix.legal.ai_sends')`: list of `['feature' => string, 'sends' => string]`.

- [ ] **Step 1: Write the failing tests**

```php
use App\Legal\ThirdParties;

function party(string $key): ?array
{
    return collect(ThirdParties::all())->firstWhere('key', $key);
}

test('the AI service is named by the host of its configured address, and only while AI is on', function () {
    config(['bookflix.ai.enabled' => true, 'bookflix.ai.base_url' => 'https://llm.example.test/v1', 'bookflix.ai.api_key' => 'x', 'bookflix.ai.models.fast' => 'm']);
    expect(party('ai')['hosts'])->toBe(['llm.example.test']);

    config(['bookflix.ai.enabled' => false]);
    expect(party('ai'))->toBeNull();
});

test('mail is named only when it leaves the app', function () {
    config(['mail.default' => 'log']);
    expect(party('mail'))->toBeNull();

    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
    expect(party('mail')['hosts'])->toBe(['smtp.example.test']);
});

test('object storage is named when a private disk is s3, with its endpoint host', function () {
    config(['bookflix.reader.disk' => 's3', 'filesystems.disks.s3.endpoint' => 'https://s3.example.test']);
    expect(party('storage')['hosts'])->toBe(['s3.example.test']);

    config(['bookflix.reader.disk' => 'local']);
    expect(party('storage'))->toBeNull();
});

test('the ML service, the catalog sources and the cover hosts always come from config', function () {
    config(['services.ml.url' => 'http://ml.internal.test:8001']);

    expect(party('ml')['hosts'])->toBe(['ml.internal.test'])
        ->and(party('catalog')['hosts'])->toContain('api.mangadex.org', 'openlibrary.org')
        ->and(party('covers')['hosts'])->toContain('uploads.mangadex.org', 'covers.openlibrary.org');
});

test('a provider with no class is not named', function () {
    config(['bookflix.providers.wikidata_test' => ['class' => null, 'allowed_hosts' => ['nope.example.test']]]);

    expect(collect(ThirdParties::all())->pluck('hosts')->flatten()->all())->not->toContain('nope.example.test');
});
```

```php
// PrivacySettingsTest.php: the settings panel renders every configured item
test('the What we send panel lists exactly the configured AI items', function () {
    config(['bookflix.ai.enabled' => true]);
    $this->actingAs(User::factory()->create());

    $page = Livewire::test('pages::settings.privacy');

    foreach (config('bookflix.legal.ai_sends') as $item) {
        $page->assertSee($item['feature'])->assertSee($item['sends']);
    }
});
```

- [ ] **Step 2: Run to verify it fails.** `vendor/bin/pest tests/Feature/Legal/ThirdPartiesTest.php tests/Feature/Settings/PrivacySettingsTest.php`. Expected: FAIL, class `App\Legal\ThirdParties` not found and `ai_sends` null.
- [ ] **Step 3: Implement.** `ThirdParties::all()` builds entries per P3-R3; hosts come from `parse_url(..., PHP_URL_HOST)`. Keys: `ai` (role "Answers Ask, helps with search, writes summaries and explanations"; data from `ai_sends`), `mail` (host `mail.mailers.smtp.host` for `smtp`, else the mailer name), `storage` (distinct disks of `ContentSource::disk()`, `bookflix.exports.disk`, `bookflix.avatar_disk` whose driver is `s3`; host from the disk `endpoint`, else `AWS_URL`/bucket region as `s3.{region}.amazonaws.com` is NOT assumed: use the endpoint, else name only), `ml` (self-hosted: `hosts` = host of `services.ml.url`, role "Runs on Bookflix's own infrastructure"), `catalog` (hosts of providers with a class, role "Catalog data. Bookflix fetches it on a schedule; no reader data is sent"), `covers` (same hosts plus `uploads.*` CDN entries already in `allowed_hosts`, role "Cover images load directly in your browser from these hosts unless Bookflix serves them itself"). Role and data strings go through `__()`. `ai_sends` is a four-item config list copied word for word from the current panel (Ask, Search, Summaries, Explanations); the partial renders them as the `<ul>` plus the closing sentence "We never send your name or email." (same classes as today's markup, so the panel looks the same).
- [ ] **Step 4: Run to verify it passes**, then the settings and browser privacy page: `vendor/bin/pest tests/Feature/Legal tests/Feature/Settings && vendor/bin/pest tests/Browser/PrivacyPageTest.php`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: one source for third parties and what the AI service receives"`

### Task 3.3: The privacy policy and terms pages

**Files:**
- Create: `resources/views/legal/privacy.blade.php`, `resources/views/legal/terms.blade.php`, `resources/views/legal/sections/{collected,ai,third-parties,deactivation,uploads,rights,contact}.blade.php`
- Modify: `routes/web.php` (public group, after `landing`)
- Test: `tests/Feature/Legal/LegalPagesTest.php` (follow `tests/Feature/LandingTest.php`)

**Interfaces:**
- Consumes: `config('bookflix.legal.*')`, `ThirdParties::all()`, `config('bookflix.legal.collected')`, `config('bookflix.legal.ai_sends')`, `config('bookflix.privacy.deactivated_retention_days')`, `config('bookflix.exports.*')`, `config('bookflix.reader.upload.quota_mb')`.
- Produces: routes `legal.privacy`, `legal.terms`; sections listed in Cross-phase interfaces.

- [ ] **Step 1: Write the failing tests**

```php
use App\Models\User;

test('both pages are public and signed-in readers are not redirected away', function (string $route) {
    $this->get(route($route))->assertOk()->assertSee('Bookflix');
    $this->actingAs(User::factory()->create())->get(route($route))->assertOk();
})->with(['legal.privacy', 'legal.terms']);

test('the policy names every configured third party', function () {
    config([
        'bookflix.ai' => array_merge(config('bookflix.ai'), ['enabled' => true, 'base_url' => 'https://llm.example.test/v1', 'api_key' => 'x', 'models' => ['fast' => 'm', 'smart' => 'm', 'vision' => null]]),
        'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test',
        'bookflix.reader.disk' => 's3', 'filesystems.disks.s3.endpoint' => 'https://s3.example.test',
        'services.ml.url' => 'http://ml.internal.test:8001',
    ]);

    $html = $this->get(route('legal.privacy'))->assertOk()->getContent();

    foreach (['llm.example.test', 'smtp.example.test', 's3.example.test', 'ml.internal.test'] as $host) {
        expect($html)->toContain($host);
    }
    foreach (\App\Legal\ThirdParties::all() as $party) {
        foreach ($party['hosts'] as $host) {
            expect($html)->toContain($host);
        }
    }
});

test('the policy says fonts are served by Bookflix and the CSP lets no outside font host in', function () {
    $response = $this->get(route('legal.privacy'))->assertSee('fonts', false);

    preg_match('/font-src ([^;]+)/', $response->headers->get('Content-Security-Policy'), $match);
    expect(trim($match[1]))->toBe("'self' data:");
});

test('the AI section shows exactly the items the Settings panel shows', function () {
    config(['bookflix.ai.enabled' => true]);

    $html = $this->get(route('legal.privacy'))->getContent();

    foreach (config('bookflix.legal.ai_sends') as $item) {
        expect($html)->toContain(e($item['feature']))->toContain(e($item['sends']));
    }
});

test('with AI off the policy says no message leaves for an AI service', function () {
    config(['bookflix.ai.enabled' => false]);

    $this->get(route('legal.privacy'))->assertSee('AI features are switched off')->assertDontSee(config('bookflix.legal.ai_sends')[0]['sends']);
});

test('operator and contact appear when configured and nothing placeholder-like shows when they are not', function () {
    $html = $this->get(route('legal.privacy'))->getContent();
    expect($html)->not->toContain('TODO')->not->toContain('mailto:')->not->toContain('null');

    config(['bookflix.legal.operator_name' => 'Example Operator', 'bookflix.legal.contact_email' => 'privacy@example.test']);
    $this->get(route('legal.privacy'))->assertSee('Example Operator')->assertSee('mailto:privacy@example.test', false);
    $this->get(route('legal.terms'))->assertSee('Example Operator')->assertSee('mailto:privacy@example.test', false);
});

test('the policy covers each inventory group, export, switching AI off, uploads and deactivation', function () {
    $html = $this->get(route('legal.privacy'))->getContent();

    foreach (config('bookflix.legal.collected') as $group) {
        expect($html)->toContain(e($group['title']));
    }
    expect($html)->toContain('Export my data')->toContain(route('privacy.edit'))
        ->and($html)->toContain((string) config('bookflix.exports.retention_days'))
        ->and($html)->toContain((string) config('bookflix.reader.upload.quota_mb'));
});
```

- [ ] **Step 2: Run to verify it fails.** `vendor/bin/pest tests/Feature/Legal/LegalPagesTest.php`. Expected: FAIL, route `legal.privacy` not defined.
- [ ] **Step 3: Implement.** Routes: `Route::view('privacy', 'legal.privacy')->name('legal.privacy'); Route::view('terms', 'legal.terms')->name('legal.terms');` in `routes/web.php` (no auth). Each page is `<x-layouts::landing :title="__('Privacy Policy')" :description="...">` with a `max-w-[52rem]` prose column, headings `h1` then `h2` per section, "Last updated" from `config('bookflix.legal.updated_at')`, `pt-32` clears the fixed landing header. Section content (each a Blade comment citing its ledger row; plain text, no cursive, tokens `text-ink` / `text-ink-subtle`):
  - `collected`: loops `config('bookflix.legal.collected')` showing title, what, why, kept. Retention figures are written in the view from config (exports `retention_days`, recommendations `bookflix.recommendations.retention_days`, deactivation `deactivated_retention_days`, upload quota `bookflix.reader.upload.quota_mb`). States the cookies and the `flux.appearance` localStorage key, that searches are logged without the account when Personalization is off, and that search history and token counts have no deletion schedule (P3-R11).
  - `ai`: when `config('bookflix.ai.enabled')` is true, names the provider by `ThirdParties` host, includes the `ai-what-we-send` partial, says Ask, "Why this?" explanations and AI-assisted search can be turned off in Settings › Privacy (note: search has no separate switch, as the panel says) and personalisation off stops activity-based recommendations; when false, "AI features are switched off on this Bookflix."
  - `third-parties`: loops `ThirdParties::all()` (name, hosts, role, data); then fixed statements: fonts are served by Bookflix itself (P3-R1), no analytics or error-tracking service is used (re-check in the ledger before each release), hosting provider `config('bookflix.legal.hosting_provider')` when set, else `{{-- TODO(owner): hosting and database provider --}}`.
  - `deactivation`: written in Task 3.5.
  - `uploads`: private to the reader, stored on the private disk, per-format size caps and the quota from config, removed with the account (see deactivation), takedown requests to the contact address; ends with `{{-- Phase 7 adds the jurisdiction and DRM lines here. --}}`; Phase 9 adds the followed-series paragraph in a marked comment `{{-- Phase 9: followed series --}}`.
  - `rights`: export (Settings › Privacy › Export my data, link valid `link_hours`, file kept `retention_days`), correction in Settings, and, as plain statement, that people in some places may have rights to access, correct or erase data and should write to the contact address; the sentence about erasure on request is `{{-- TODO(owner): confirm how erasure requests are handled --}}` unless the owner answered.
  - `contact`: operator name and `mailto:` link only when configured, else only `{{-- TODO(owner): operator name and contact address --}}`.
  - `terms.blade.php`: sections who runs Bookflix (operator, `TODO(owner)` jurisdiction comment), accounts (email and password, keep them safe, deactivation), what you may read and upload (public-domain, openly licensed or your own files; do not upload what you have no right to; nothing that sexualises minors), copyright and takedown (contact address), AI answers can be wrong, the service is provided as is (`TODO(owner): counsel review of warranty and liability wording`), changes to the terms, contact. No subscription, price or refund text.
- [ ] **Step 4: Run to verify it passes.** `vendor/bin/pest tests/Feature/Legal tests/Feature/CspTest.php tests/Feature/RedForCtasOnlyTest.php tests/Feature/TranslationKeysTest.php`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: privacy policy and terms pages written from the data inventory"`

### Task 3.4: Link the policy from the footer, sign-up, Settings and the FAQ

**Files:**
- Modify: `resources/views/components/landing/footer.blade.php` (a "Legal" column next to Account), `resources/views/pages/auth/register.blade.php` (a line above the submit button), `resources/views/pages/settings/⚡privacy.blade.php` (a link in the page heading text and in the export card), `resources/views/components/landing/faq.blade.php` (a link in "Is my reading private?" only)
- Test: `tests/Feature/Legal/LegalLinksTest.php`; extend `tests/Browser/LandingTest.php` with one assertion

**Interfaces:**
- Consumes: `route('legal.privacy')`, `route('legal.terms')`.

- [ ] **Step 1: Write the failing tests**

```php
use App\Models\User;
use Livewire\Livewire;

test('the landing footer links the privacy policy and the terms', function () {
    $html = $this->get(route('landing'))->getContent();

    expect(htmlQuery($html, '//footer//a[@href="'.route('legal.privacy').'"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//footer//a[@href="'.route('legal.terms').'"]')->length)->toBe(1);
});

test('the register form links both pages and never says you agree', function () {
    $html = $this->get(route('register'))->assertOk()->getContent();

    expect(htmlQuery($html, '//form//a[@href="'.route('legal.privacy').'"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//form//a[@href="'.route('legal.terms').'"]')->length)->toBe(1)
        ->and($html)->not->toContain('you agree');
});

test('Settings privacy links the policy', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.privacy')->assertSeeHtml('href="'.route('legal.privacy').'"');
});

test('the FAQ answer about privacy links the policy and no other answer does', function () {
    $html = $this->get(route('landing'))->getContent();

    expect(htmlQuery($html, '//section[@id="faq"]//a[@href="'.route('legal.privacy').'"]')->length)->toBe(1);
});
```

```php
// tests/Browser/LandingTest.php: at 375 and 1280 wide the footer's Privacy link is visible, at least 44px tall and navigates to /privacy
```

- [ ] **Step 2: Run to verify it fails.** `vendor/bin/pest tests/Feature/Legal/LegalLinksTest.php`. Expected: FAIL (0 links).
- [ ] **Step 3: Implement.** Footer: add `__('Legal') => [[route('legal.privacy'), __('Privacy Policy')], [route('legal.terms'), __('Terms')]]` to `$columns` (grid is `sm:grid-cols-3`: it becomes four columns, so change to `sm:grid-cols-2 lg:grid-cols-4` and keep every link `min-h-11`). Register: a `<p class="text-center text-meta text-ink-subtle">` before the submit row: "Read the Terms and the Privacy Policy." with `<flux:link>` links plus `{{-- TODO(owner): acceptance wording if counsel wants one --}}`. Settings privacy: a sentence under the heading and one in the export card pointing to `route('legal.privacy')` (inside the `ai-what-we-send` partial's callers is not needed; keep the partial neutral). FAQ: the `$faqs` rows take an optional third element (a URL); when set, a link "Read the privacy policy" is rendered after the answer `<p>` inside the same panel. Only the privacy row sets it. Phase 4 changes the FAQ layout later; the third element is data, so it survives.
- [ ] **Step 4: Run to verify it passes**, plus the touched browser file: `vendor/bin/pest tests/Feature/Legal tests/Feature/Auth/RegistrationTest.php tests/Feature/LandingTest.php && vendor/bin/pest tests/Browser/LandingTest.php`. Expected: PASS.
- [ ] **Step 5: Commit**: `git commit -m "feat: link the privacy policy and terms from the footer, sign-up, Settings and the FAQ"`

### Task 3.5: Deactivation: the policy matches the code (owner answer first)

**Files:**
- Create: `resources/views/legal/sections/deactivation.blade.php` content (file exists from 3.3 as a stub: fill it here)
- Modify: `resources/views/pages/settings/⚡deactivate-account-modal.blade.php` (a "What happens" link to `legal.privacy#deactivation`, same days from config; behaviour untouched)
- Test: `tests/Feature/Legal/DeactivationPolicyTest.php`; extend `tests/Feature/Settings/DeactivateAccountTest.php` only if a case below is missing

**Interfaces:**
- Consumes: ledger "Owner answers" from Task 3.1, `bookflix.privacy.deactivated_retention_days`.

- [ ] **Step 1: Read the owner's answer in the ledger.** **Answer A or no answer (default): keep the 30-day hard delete.** Continue with this task as written. **Answer B (keep deactivated accounts, never delete):** change no code in this phase. Write in the ledger "Needs decision: purge change approved but not built; follow-up task: stop scheduling `bookflix:purge-deactivated-accounts` or add a retention value that means never, then rewrite the deactivation section", ship the section describing today's behaviour, and flag it in the PR. Nothing in this task edits `PurgeDeactivatedAccounts` under any answer.
- [ ] **Step 2: Write the failing tests**

```php
use App\Models\ContentSource;
use App\Models\SearchQuery;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the policy, the modal and the purge use one number of days', function () {
    config(['bookflix.privacy.deactivated_retention_days' => 45]);
    $this->actingAs($user = User::factory()->create());

    $this->get(route('legal.privacy'))->assertSee('45 days');
    Livewire::test('pages::settings.deactivate-account-modal')->assertSee('45 days');

    $user->forceFill(['deactivated_at' => now()->subDays(44)])->save();
    $this->artisan('bookflix:purge-deactivated-accounts')->assertSuccessful();
    expect($user->fresh())->not->toBeNull();

    $user->forceFill(['deactivated_at' => now()->subDays(46)])->save();
    $this->artisan('bookflix:purge-deactivated-accounts')->assertSuccessful();
    expect($user->fresh())->toBeNull();
});

test('the policy lists what deactivation does, in the order the code does it', function () {
    $html = $this->get(route('legal.privacy'))->getContent();

    expect($html)->toContain('signed out everywhere')->toContain('cannot sign in')
        ->and($html)->toContain('uploaded books')->toContain('avatar')
        ->and($html)->toContain('search history')->toContain('token counts');
});

test('what the purge leaves behind is exactly what the policy says it leaves', function () {
    $user = User::factory()->create(['deactivated_at' => now()->subDays(31)]);
    DB::table('search_queries')->insert(['user_id' => $user->id, 'session_hash' => str_repeat('a', 64), 'query' => 'dune', 'normalized' => 'dune', 'results_count' => 1, 'created_at' => now()]);
    DB::table('ai_usage')->insert(['user_id' => $user->id, 'feature' => 'assistant', 'model' => 'm', 'created_at' => now()]);

    $this->artisan('bookflix:purge-deactivated-accounts')->assertSuccessful();

    expect(DB::table('search_queries')->whereNull('user_id')->count())->toBe(1)
        ->and(DB::table('ai_usage')->whereNull('user_id')->count())->toBe(1)
        ->and(ContentSource::query()->where('owner_user_id', $user->id)->count())->toBe(0);
});

test('a reader is told how to get a deactivated account back inside the window', function () {
    config(['bookflix.legal.contact_email' => 'privacy@example.test']);

    $this->get(route('legal.privacy'))->assertSee('before the days are up')->assertSee('privacy@example.test');
});
```

- [ ] **Step 3: Run to verify it fails.** `vendor/bin/pest tests/Feature/Legal/DeactivationPolicyTest.php`. Expected: FAIL, the section is a stub and the modal lacks the link.
- [ ] **Step 4: Implement.** The `deactivation` section states, from the ledger: on deactivation the reader is signed out everywhere and cannot sign in; the account, library, uploaded books and avatar stay on disk and in the database, unreachable to anyone but the operator, until `{{ $days }} days` after deactivation; then the daily job deletes the account and everything tied to it: profile, library, collections, ratings, reading progress and history, activity, reviews, reports, assistant chats, uploaded books and their files, the avatar and any export file; two kinds of rows stay with the account link removed: search history (words searched and a keyed session code) and token counts of AI use (no text). A reader who wants the account back writes to the contact address "before the days are up" (P3-R10, `TODO(owner)`). Add the `id="deactivation"` anchor. Keep the sentence about GDPR and CCPA out of the public copy; it goes in the PR report (Phase exit). The modal gets one added link `{{ __('What happens to my data') }}` to `route('legal.privacy').'#deactivation'`.
- [ ] **Step 5: Run to verify it passes**, then the related suites. `vendor/bin/pest tests/Feature/Legal tests/Feature/Settings tests/Feature/Library/DataExportTest.php tests/Feature/Reader/UploadContentSourceTest.php`. Expected: PASS; ledger gets "Needs decision: retention for search_queries, ai_usage, user_interactions, reading_history; erasure on request".
- [ ] **Step 6: Commit**: `git commit -m "feat: deactivation section states what the purge really does"`

### Task 3.6: SafetyCheck, the deterministic detector

**Files:**
- Create: `app/Ai/Safety/SafetyCheck.php`, `app/Ai/Safety/SafetyVerdict.php`
- Test: `tests/Feature/Ai/SafetyCheckTest.php` (plain Pest, no database needed; follow `tests/Feature/Ai/TitleVerifierTest.php`)

**Interfaces:**
- Produces: `SafetyCheck::assess(string $message): SafetyVerdict`; `final readonly class SafetyVerdict { public function __construct(private bool $crisis) {} public function isCrisis(): bool }`.

- [ ] **Step 1: Write the failing tests**

```php
use App\Ai\Safety\SafetyCheck;

test('first-person messages about self-harm or suicide are a crisis', function (string $message) {
    expect(app(SafetyCheck::class)->assess($message)->isCrisis())->toBeTrue();
})->with([
    'I want to kill myself',
    "i'm going to end my life tonight",
    'I don’t want to be alive anymore',
    "I've been thinking about suicide lately",
    'i am suicidal',
    'I keep hurting myself and cannot stop',
    'I wish I was dead',
    'how do I commit suicide',
    'help me find ways to hurt myself',
    'I want to end it all',
    'I just want to die',
    'I WANT TO KILL MYSELF!!!',
    "recommend something light.\n\nactually I'm going to kill myself",
]);

test('book requests, titles and fiction about the subject are never a crisis', function (string $message) {
    expect(app(SafetyCheck::class)->assess($message)->isCrisis())->toBeFalse();
})->with([
    'books like 13 Reasons Why',
    'Thirteen Reasons Why was hard to read, anything lighter?',
    'a novel about grief after a suicide',
    "I'm looking for a memoir about surviving suicide loss",
    'manga with a character who tries to kill herself and survives',
    'The Virgin Suicides',
    'Suicide Club',
    'she wants to end her life in the opening of Norwegian Wood, is that the one?',
    'I want a story about self-harm recovery for my teenager',
    'novels about people who hurt themselves and heal',
    'I could kill myself laughing at this comedy, more like it?',
    'I want to die laughing, find me a funny manga',
    "I don't want to live in a dystopia, recommend a hopeful one",
    'dark fantasy manga with political intrigue',
    '',
]);
```

- [ ] **Step 2: Run to verify it fails.** `vendor/bin/pest tests/Feature/Ai/SafetyCheckTest.php`. Expected: FAIL, class not found.
- [ ] **Step 3: Implement.** `assess()` lowercases (`mb_strtolower`), maps `’` and `‘` to `'`, collapses whitespace, then returns `new SafetyVerdict(true)` when any pattern in a `private const PATTERNS` list matches (`preg_match` with `iu`). Patterns, all first-person by construction (P3-R5):
  1. `\b(?:kill|killing|hurt|hurting|harm|harming|cut|cutting)\s+myself\b(?!\s+(?:laughing|with\s+laughter))`
  2. `\b(?:end|ending|take|taking)\s+my\s+(?:own\s+)?life\b`
  3. `\bi(?:'m| am| have been|'ve been| feel| keep)\s+(?:so\s+|really\s+)?(?:suicidal|self[- ]harming)\b`
  4. `\bi\s+(?:really\s+|just\s+)?(?:want|wanna|need)(?:\s+to)?\s+die\b(?!\s+(?:laughing|of\s+(?:laughter|embarrassment)))`
  5. `\bi\s+(?:don't|do not|dont)\s+want\s+to\s+(?:be\s+alive|live\s+any\s*more|be\s+here\s+any\s*more)\b`
  6. `\bwish\s+i\s+(?:was|were)\s+dead\b`
  7. `\bi(?:'m| am| keep| have been|'ve been)\s+thinking\s+(?:about|of)\s+(?:suicide|ending\s+(?:it|things))\b`
  8. `\bi\s+(?:want|wanna|need)(?:\s+to)?\s+end\s+it\s+all\b`
  9. `\bhow\s+(?:do\s+i|can\s+i|to)\s+commit\s+suicide\b`
  
  Add `// ponytail: a phrase list, no typo or slang tolerance; add a pattern and a dataset row when a real miss is found.` Adjust a pattern only with a new dataset row proving why; never loosen a pattern to catch a bare noun.
- [ ] **Step 4: Run to verify it passes.** `vendor/bin/pest tests/Feature/Ai/SafetyCheckTest.php`. Expected: PASS (28). Phpstan: `vendor/bin/phpstan analyse app/Ai/Safety` clean.
- [ ] **Step 5: Commit**: `git commit -m "feat: deterministic self-harm detector for the Ask assistant"`

### Task 3.7: Crisis resources and the Ask reply (runs before anything else)

**Files:**
- Create: `app/Ai/Safety/CrisisResources.php`
- Modify: `config/bookflix.php` (`crisis_resources`, `crisis_resources_reviewed_at`), `app/Ai/Assistant/AssistantReply.php` (`public bool $crisis = false`), `app/Ai/Assistant/AssistantRunner.php` (constructor, `reply()` mode, `answer()`), `resources/views/pages/assistant/⚡index.blade.php` (line ~605), `resources/views/legal/sections/ai.blade.php` (one sentence)
- Test: `tests/Feature/Ai/CrisisResourcesTest.php`, `tests/Feature/Ai/AssistantSafetyTest.php` (helpers `chatOf`, `asks` as in `tests/Feature/Ai/AssistantRunnerTest.php`: copy them locally), page case in `tests/Feature/Ai/AssistantPageTest.php`

**Interfaces:**
- Consumes: `SafetyCheck::assess()`, `AssistantConversation::record()`.
- Produces: `CrisisResources::regionFor(?Request $request = null): string` (upper-case ISO region present in config, else `'default'`; the only place the region is read), `CrisisResources::for(string $region): list<array{name: string, contact: string, url: string}>` (the region's entries, then `default`'s, no duplicates), `CrisisResources::reply(string $region): string`; `AssistantReply::$crisis`.

- [ ] **Step 1: Write the failing tests**

```php
// CrisisResourcesTest.php
use App\Ai\Safety\CrisisResources;
use Illuminate\Http\Request;

test('the list is complete and reviewed', function () {
    $resources = config('bookflix.crisis_resources');

    expect($resources)->toHaveKey('default')->and($resources['default'])->not->toBeEmpty()
        ->and(\Illuminate\Support\Carbon::parse(config('bookflix.crisis_resources_reviewed_at'))->lte(now()))->toBeTrue();

    foreach ($resources as $region => $entries) {
        expect($region)->toMatch('/^(?:[A-Z]{2}|default)$/');
        foreach ($entries as $entry) {
            expect($entry)->toHaveKeys(['name', 'contact', 'url'])
                ->and(trim($entry['name'].$entry['contact']))->not->toBe('')
                ->and($entry['url'])->toStartWith('https://');
        }
    }
});

test('the region comes from the browser language, else default', function (?string $header, string $expected) {
    config(['bookflix.crisis_resources' => ['US' => [['name' => 'a', 'contact' => 'b', 'url' => 'https://a.test']], 'AU' => [['name' => 'c', 'contact' => 'd', 'url' => 'https://c.test']], 'default' => [['name' => 'e', 'contact' => 'f', 'url' => 'https://e.test']]]]);
    $request = Request::create('/', 'POST', server: $header === null ? [] : ['HTTP_ACCEPT_LANGUAGE' => $header]);

    expect(CrisisResources::regionFor($request))->toBe($expected);
})->with([
    'australian' => ['en-AU,en;q=0.9', 'AU'],
    'lower-case region' => ['en-us', 'US'],
    'no region in the tag' => ['fr,en;q=0.5', 'default'],
    'a region with no entry' => ['pt-BR', 'default'],
    'no header' => [null, 'default'],
]);

test('the reply names the region first, the directory last, and is not a lecture', function () {
    $reply = CrisisResources::reply('US');
    $us = config('bookflix.crisis_resources.US')[0];
    $directory = config('bookflix.crisis_resources.default')[0];

    expect($reply)->toContain($us['name'])->toContain($us['contact'])->toContain($directory['url'])
        ->and(strpos($reply, $us['name']))->toBeLessThan(strpos($reply, $directory['name']))
        ->and($reply)->toContain('emergency number')->toContain('book assistant')
        ->and(mb_strlen($reply))->toBeLessThan(900);
});
```

```php
// AssistantSafetyTest.php
it('answers a crisis message itself: no model call, no search, no log, no usage row, one saved exchange', function () {
    Http::preventStrayRequests();
    Log::listen(function (MessageLogged $event) use (&$logged): void { $logged[] = $event->message; });
    $conversation = chatOf($this->user);
    $before = [DB::table('search_queries')->count(), DB::table('ai_usage')->count()];

    $reply = asks($this->user, 'I want to kill myself', $conversation, 'find:thorough');

    expect($reply->crisis)->toBeTrue()->and($reply->fixed)->toBeTrue()->and($reply->fallback)->toBeFalse()
        ->and($reply->stories)->toBeEmpty()
        ->and($reply->text)->toContain(config('bookflix.crisis_resources.default')[0]['name'])
        ->and([DB::table('search_queries')->count(), DB::table('ai_usage')->count()])->toBe($before)
        ->and($logged ?? [])->toBe([])
        ->and($conversation->messages()->count())->toBe(2)
        ->and($conversation->messages()->where('role', 'assistant')->value('mode'))->toBe('safety');
    Http::assertNothingSent();
});

it('still answers when the hourly limit is spent, the kill switch is on, the provider is down or AI is unconfigured', function (Closure $break) {
    $break($this);
    Http::preventStrayRequests();

    $reply = asks($this->user, "i'm going to end my life tonight");

    expect($reply->crisis)->toBeTrue()->and($reply->fallback)->toBeFalse();
})->with([
    'limit spent' => [fn ($t) => collect(range(1, 25))->each(fn () => RateLimiter::hit('ai-assistant:'.$t->user->id, 3600))],
    'kill switch' => [fn () => app(KillSwitch::class)->kill()],
    'unconfigured' => [fn () => config(['bookflix.ai.base_url' => '', 'bookflix.ai.api_key' => ''])],
]);

it('answers a message over 2000 characters that contains a crisis phrase', function () {
    $reply = asks($this->user, str_repeat('word ', 450).' I want to kill myself');

    expect($reply->crisis)->toBeTrue();
});

it('never sends the crisis exchange to the model in a later turn', function () {
    $conversation = chatOf($this->user);
    asks($this->user, 'I want to kill myself', $conversation);
    assistantScript(assistantText('Try Crimson Court.'));

    asks($this->user, 'something with throne politics', $conversation);

    expect(json_encode(sentBodies()))->not->toContain('kill myself')->not->toContain(config('bookflix.crisis_resources.default')[0]['name']);
});

it('does not change how normal book requests are answered', function (string $message) {
    assistantScript(assistantText('Try Crimson Court.'));

    expect(asks($this->user, $message)->crisis)->toBeFalse();
    Http::assertSentCount(1);
})->with(['books like 13 Reasons Why', 'a novel about grief after a suicide']);
```

```php
// AssistantPageTest.php: a crisis reply on the open chat shows the resources and not the Search nudge
test('a crisis reply shows its resources without the Go to Search link', function () {
    $chat = AssistantConversation::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)->test('pages::assistant.index', ['conversation' => $chat->id])
        ->set('message', 'I want to kill myself')->call('send')
        ->assertSee(config('bookflix.crisis_resources.default')[0]['name'])->assertDontSee('Go to Search');
});
```

Add one more case to `CrisisResourcesTest`: the browser's region wins in `AssistantSafetyTest` via `$this->withHeader('Accept-Language', 'en-AU')` on a request that calls the runner (use `app()->instance('request', Request::create(...))`), asserting the reply contains the AU entry's `contact`.

- [ ] **Step 2: Run to verify it fails.** `vendor/bin/pest tests/Feature/Ai/CrisisResourcesTest.php tests/Feature/Ai/AssistantSafetyTest.php`. Expected: FAIL, class `CrisisResources` not found, `crisis` property missing.
- [ ] **Step 3: Implement the resources (official sources only).** For each region take the service name, number or text keyword, and the service's own page URL **only from that service's official website, read at implementation time**, never from memory or from this plan. Start from these official sites and confirm each is still the service's own: the United States Lifeline (988lifeline.org), Samaritans for GB and IE (samaritans.org), Canada's 988 (988.ca), Lifeline Australia (lifeline.org.au), and 1737 New Zealand (1737.org.nz); for `default` use an international directory run by a recognised suicide-prevention body (IASP's crisis centres page or the findahelpline.com directory), with `contact` set to "Find a helpline in your country" and `url` that directory. Each entry in `config/bookflix.php` is `['name' => ..., 'contact' => ..., 'url' => '<the official page the number was read from>']`, followed by a comment `// source read 2026-MM-DD`. Set `crisis_resources_reviewed_at` to the date of that reading and put `// TODO(owner): review this list and the date before launch; update when a service changes` above the array. A region whose official page cannot be read in the session is left out (it falls back to `default`) and recorded in the ledger. The list ledger row shows every URL.
- [ ] **Step 4: Implement the reply and the wiring.** `CrisisResources::regionFor`: read the first `Accept-Language` tag with a region subtag (`en-AU` gives `AU`), upper-case it, return it only when `config('bookflix.crisis_resources')` has that key, else `'default'`; `$request ?? request()`. `for()`: region entries then `default` entries, skipping duplicates. `reply()`: the text, with `__()`: "I'm really sorry you're going through this, and I'm glad you said it. You deserve support from a person right now. If you might act on these thoughts or you are in danger, call your local emergency number now." then "You can talk to someone who is trained to help:" then one line per resource `- {name}: {contact} ({url})`, then "I'm a book assistant, so I can't give you that kind of support. If it would help to talk about something else, or to find a gentle story to keep you company, I'm here for that too." `AssistantRunner`: add `private SafetyCheck $safety` as the fourth constructor argument (autowired); in `answer()` make this the first branch after `$message === ''` (before `withinLimit`, length, `ProhibitedContent`, attachments): `if ($this->safety->assess($message)->isCrisis()) { return new AssistantReply(CrisisResources::reply(CrisisResources::regionFor()), new Collection, fixed: true, crisis: true); }`. In `reply()` save with `$reply->crisis ? 'safety' : $mode->key()` as the mode. Page: change line ~605 to `@if ($entry->fixed && $entry->mode !== 'safety')`. `ai.blade.php`: add "Messages that look like they are about suicide or self-harm are answered by Bookflix itself, with a list of helplines, and are not sent to the AI service." (the tests above prove it).
- [ ] **Step 5: Run to verify it passes**, then the Ask suites and browser file: `vendor/bin/pest tests/Feature/Ai tests/Feature/Legal && vendor/bin/pest tests/Browser/AssistantTest.php`. Expected: PASS; existing `AssistantRunnerTest` unchanged and green (the fourth constructor argument is autowired).
- [ ] **Step 6: Commit**: `git commit -m "feat: Ask answers self-harm messages itself, with regional crisis resources"`

## Phase exit

- [ ] `composer test` passes (Pint, PHPStan level 7, Unit and Feature). Run `env -u AI_AGENT -u CLAUDECODE vendor/bin/pest tests/Browser/LandingTest.php tests/Browser/PrivacyPageTest.php tests/Browser/AssistantTest.php` and record the result.
- [ ] Whole-branch review (most capable model) against Part E, the ledger inventory and Review Focus; check specifically that a crisis message cannot reach `LlmClient` by any path (`grep -rn "assess(" app`), that no figure in the legal views is hard-coded where config has it, and that no real name, email or number is in the diff. `/code-review` as the second pass. One fix wave.
- [ ] Ledger complete: inventory, owner answers, every `TODO(owner)` left (operator name, contact, hosting provider, jurisdiction, counsel review, acceptance wording, erasure handling, crisis list review), the official URL of every crisis entry, each region left out and why.
- [ ] PR (against `main`, never merged by the agent; no Co-Authored-By or AI attribution; Part G rules, D2-24 stacking if Phase 2 is unmerged) body: the Part I table with one row per Part E item and the C1 privacy rows:

| Item | Expected status | Files |
|---|---|---|
| E what's collected, why, how long, export | Fixed | `config/bookflix.php`, `legal/sections/collected.blade.php`, `rights.blade.php` |
| E AI use (provider named, what is sent, switching off) | Fixed | `App\Legal\ThirdParties`, `legal/sections/ai.blade.php`, `ai-what-we-send.blade.php`, Settings privacy |
| E third parties (analytics, storage, mail, AI, error tracking, CDN or font host) | Fixed (analytics, error tracking: none; fonts: self-hosted) | `ThirdParties.php`, `third-parties.blade.php` |
| E uploads after deactivation | Fixed (policy matches the 30-day purge) or Needs decision (owner answer B) | `deactivation.blade.php`, modal |
| E user uploads (F6) | Fixed (Phase 7 extends) | `uploads.blade.php` |
| E followed series (F7) | Needs decision until Phase 9 | marker in `uploads.blade.php` |
| E subscription cancelling, auto-renewal reminders | Not applicable (no billing code) | none |
| E AI chat and self-harm | Fixed | `app/Ai/Safety/*`, `AssistantRunner`, `config/bookflix.php` |
| E GDPR and CCPA erasure on request | Needs decision (no hard-deletion built; search history and token counts have no retention) | ledger |
| C1 privacy page, footer and registration links, help and FAQ links | Fixed (the help page link lands with Phase 7) | `routes/web.php`, `legal/*`, footer, register, FAQ |

Then list the open questions (Q1 purge, Q3 operator and contact), the contradictions found (Bunny Fonts, catalog providers), and the `TODO(owner)` list.
