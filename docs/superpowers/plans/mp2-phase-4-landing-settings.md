# Phase 4: Design references, landing and settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collect the design references (F10), then give the landing page a header that leaves at the FAQ (A1), a two-column FAQ (A2), a first-screen and sticky "Get started" (C4), and fold Settings' four pages into one `/settings` page shaped like Profile (A3).

**Architecture:** One Alpine store `landingChrome` (`pastFaq`) fed by an IntersectionObserver sentinel drives the header and the phone CTA. `/settings` is one routed Livewire page that embeds the existing four pages as section components (their PHP and tests mostly survive), beside a summary column and a sticky section nav. Toggles save through one trait with rollback; security actions confirm the password in a dialog while the server keeps the same 3-hour freshness rule.

**Tech Stack:** Laravel 13, Livewire 4 single-file pages, Flux free (modal, switch, input), Alpine, Tailwind 4 tokens, Pest 5 + browser plugin, Fortify (2FA, passkeys), Playwright CLI for screenshots.

**Spec:** `docs/superpowers/specs/2026-11-26-cleanup-brief.md` F10, A1, A2, A3, C4 (CTA bullets); `docs/superpowers/plans/2026-11-26-bookflix-masterplan-2.md` Part F "Phase 4", F.0 row 4, D2-3, D2-18, D2-23.
**Branch:** `mp2-phase-4-landing-settings` · **Ledger:** `docs/superpowers/plans/mp2-phase-4-ledger.md` · **Depends on:** Phase 1 (Task 1.1 `RequirePassword` is persistent middleware; Task 1.3 throttles `password.confirm.store`), Phase 2 (Task 2.3 `landingMenu` drawer in the landing header), Phase 3 (Settings › Privacy may carry a policy link: keep it).

## Global Constraints

Every Global Constraint in the masterplan applies. Phase-specific:

- Header hides with `transform` + `opacity` and `inert`, never `display:none`; instant under `prefers-reduced-motion`; the observer is an IntersectionObserver, never a scroll listener.
- Sticky CTA: phones only (`max-md`), guests only, `padding-bottom: max(0.75rem, env(safe-area-inset-bottom))`, hidden (and `inert`) while `pastFaq`, z-index 30 (header is 40).
- FAQ breakpoint is 1024px (`lg`); left column holds questions 0 to 2, right holds 3 and 4; "What is Bookflix?" (index 0) is open on load.
- Settings sections, in order, ids exactly: `profile`, `reading`, `privacy`, `security`, `account`. Existing card ids `ai` and `export` stay inside `privacy`.
- Settings page width follows Profile: `max-w-[75rem]`, grid `lg:grid-cols-[minmax(0,1fr)_22.5rem]`, cards `rounded-card border border-hairline bg-surface-deep`.
- Password freshness stays `config('auth.password_timeout')` (10800 s), read from `session('auth.password_confirmed_at')`. No new config key, no new dependency.
- Toggle status copy: saved "Saved."; failed "We could not save that. Your choice was put back."

## Review Focus

1. **A setting is lost or unreachable in the merge.** The page is four pages in one; a field with no test disappears silently. → Task 4.5 (field inventory test), kept green in 4.6 to 4.8.
2. **A toggle says "Saved" when the save failed, or stays flipped.** → Task 4.6 (forced exception rolls the switch back and shows the error).
3. **A security action runs, or recovery codes reach the browser, without a recent password confirmation** once the page is no longer behind `password.confirm`. → Task 4.7.
4. **An unverified or admin account calls a reader-only section's action directly** (the route now carries only `auth`; Livewire re-runs nothing else). → Task 4.5.
5. **The landing chrome misplaces the page:** an anchor lands under the header, the header is stuck hidden after a reload mid-page, or the phone CTA covers a control. → Tasks 4.2 and 4.4.

## Cross-phase interfaces

**Consumes:** `Livewire::addPersistentMiddleware` incl. `RequirePassword` (1.1); limiter `auth-writes` on `password.confirm.store` (1.3); Alpine `landingMenu`, `data-test="landing-menu-button"`, drawer `id="landing-menu"` (2.3); `x-cta` variants `primary`, `secondary`, `light`, `danger` (as today; Phase 5 renames the set, so use only `primary`, `secondary`, `danger`).

**Produces (registry names, exact):**
- Route `settings.edit` (GET `/settings`, page `pages::settings.index`); `profile.edit`, `preferences.edit`, `privacy.edit`, `security.edit` keep names and redirect to `/settings#profile|reading|privacy|security`.
- `<x-settings.section id title description>` (`resources/views/components/settings/section.blade.php`), `<x-settings.summary-card title>` (`.../summary-card.blade.php`).
- Alpine `settingToggle` (`resources/js/settings.js`); Alpine store `landingChrome` `{ pastFaq: boolean }` (`resources/js/landing.js`); closing section `id="closing"`.
- `docs/design-references/README.md`.
- Also (used by Phase 7 and 9): `<x-settings.summary-card>` slot in `pages::settings.summary`; trait `App\Livewire\Concerns\SavesToggles`; trait `App\Livewire\Concerns\RequiresRecentPassword`.

## Rulings

- **P4-R1: Sections are the existing pages, embedded.** `pages::settings.{profile,preferences,privacy,security}` stay as Livewire components (same names, same PHP, tests mostly intact) rendered inside `pages::settings.index`; their routes, `layout.blade.php` and page headings go away. Cost if wrong: low (a rename later is mechanical); it keeps ~60 existing assertions valid.
- **P4-R2: Languages and reading length move into the Reading section** (`pages::settings.preferences`), because the brief files them there. `updateReadingProfile` on `pages::settings.profile` keeps avatar, headline, bio. Cost: two existing `ReadingProfileTest` cases change component.
- **P4-R3: Explicit-save buttons are `variant="light"` (white), not red.** DESIGN.md ("Settings save buttons are not red", one red per view) and `RedForCtasOnlyTest` contradict the brief's A3 line "brand red for the one main action". The standing design rule wins until the owner says otherwise; the ledger records it and the owner checkpoint (Task 4.7 step 1) asks. Cost if wrong: one `variant` attribute per form.
- **P4-R4: The section nav is one sticky bar under the header block** (all breakpoints; a horizontal strip on phones), not a third column: at 75rem a nav column would squeeze the settings column to about 530px. Cost: low.
- **P4-R5: Header and CTA hide together at `pastFaq`**, which stays true through the closing section and footer. Offset: the observer's root margin is `-(header height + 8)px`, so the header is gone exactly where `scroll-mt-16` lands an anchor, and Phase 2's test ("`#faq` top >= header height - 1") stays valid. Cost: header leaves 8px early.
- **P4-R6: "Active sessions" is built** (brief A3 Security; `SESSION_DRIVER=database`, so the `sessions` table holds `user_id`, `ip_address`, `user_agent`, `last_activity`; no dependency). The Security section lists only the signed-in user's rows: a label from `user_agent` by a fixed rule (browser = first match in order Edge `Edg`, Opera `OPR`, Firefox `Firefox`, Chrome `Chrome`, Safari `Safari`, else "Browser"; system = first match of iPhone, iPad, Android, Mac, Windows, Linux, else "device"; label "Browser on system"), the IP, "Last active" as `diffForHumans`, and "This device" on the row whose id is `session()->getId()`. One action, "Sign out other sessions", behind the Task 4.7 dialog and the `requirePassword()` guard, validates `current_password`, calls `Auth::logoutOtherDevices($password)` and deletes the user's other `sessions` rows; success "Signed out of N other sessions." and a readable error. Cost if wrong: low. Task 4.9.
- **P4-R7: The upload quota card is left to Phase 7** (registry row 7 and the masterplan's Phase 4 bullet disagree; the registry names Phase 7). Phase 4 ships the summary column and two cards. Cost: low.
- **P4-R8: Hash redirects keep their old middleware groups** (`auth`; `auth,verified,reader` for preferences and privacy), so guests, unverified readers and admins get the same answers as today before the redirect. Cost: none.
- **P4-R9: D2-18 fallback.** If the owner has not answered by the time Task 4.7 is built, build the default flow (same server rule, only the prompt moves) and put "Needs decision: password confirm dialog" in the PR. The server guard is never weaker than `password.confirm`.
- **P4-R10: `viewport-fit=cover` is added to the landing layout only** (needed for `env(safe-area-inset-bottom)`), through a `$viewportFit` flag on `partials/head.blade.php`. Phase 6 also edits that partial: keep the flag when rebasing.

## Baseline re-check

Run first; record each result in the ledger.
- [ ] `ls resources/views/components/landing` and `grep -n "scroll-mt\|id=" resources/views/components/landing/*.blade.php`: expect `#top-10`, `#together`, `#faq` with `scroll-mt-16`, closing without id.
- [ ] `grep -n "landingMenu" resources/js/landing.js resources/views/components/landing/header.blade.php`: Phase 2's drawer is present (else stop and ask).
- [ ] `grep -rn "legal.privacy" resources/views/pages/settings`: Phase 3 policy link, keep it in Privacy.
- [ ] `php artisan route:list | grep -E "settings|passkey|confirm"`: `settings`, `settings/{profile,preferences,privacy,security}`, `passkey.*`, `password.confirm*`, `password.confirmation`.
- [ ] `grep -rn "settings-heading\|pages::settings.layout\|x-settings-card" resources app`: `x-settings-card` is also used by `admin/media/⚡edit` and `collections/⚡show` (keep that component).
- [ ] `grep -n "confirmPassword" config/fortify.php`: `true` for two-factor and passkeys (Fortify routes keep `password.confirm`).
- [ ] `docs/design-references/` does not exist; `.gitignore` has no `design-references` line.

## Tasks

### Task 4.1: Design reference library (F10)

**Files:** Create `docs/design-references/README.md`, one note per reference `docs/design-references/<group>-<name>.md`; Modify `.gitignore` (add `/docs/design-references/screenshots/`); Test `tests/Feature/DesignReferencesTest.php`.

- [ ] **Step 1: Write the failing test.**

```php
test('the reference library is complete and carries no assets', function () {
    $dir = base_path('docs/design-references');
    $notes = collect(glob("{$dir}/*.md"))->reject(fn ($f) => basename($f) === 'README.md');

    expect($notes->count())->toBeGreaterThanOrEqual(12);
    foreach ($notes as $note) {
        $text = file_get_contents($note);
        expect($text)->toMatch('/^URL: https:\/\/\S+$/m')->toMatch('/^Date: 2026-\d\d-\d\d$/m')
            ->toMatch('/^Pattern borrowed:/m')->toMatch('/^Why:/m')->toMatch('/^Screenshot: screenshots\/\S+\.png$/m');
        expect(file_get_contents("{$dir}/README.md"))->toContain(basename($note));
    }
    foreach (['Settings', 'Admin tables and dashboards', 'Reader controls', 'Streaming shelves'] as $group) {
        expect(file_get_contents("{$dir}/README.md"))->toContain("## {$group}");
    }
    expect(file_get_contents(base_path('.gitignore')))->toContain('/docs/design-references/screenshots/')
        ->and(glob("{$dir}/*.{png,jpg,jpeg,svg,webp}", GLOB_BRACE))->toBe([]);
});
```

- [ ] **Step 2: Run:** `vendor/bin/pest tests/Feature/DesignReferencesTest.php`. Expected: FAIL (no directory).
- [ ] **Step 3: Collect.** For each reference below open the live public page logged out, confirm it is public, take one viewport screenshot with `npx playwright screenshot --viewport-size=1440,900 "<url>" docs/design-references/screenshots/<note-name>.png` (Playwright is already installed for browser tests; mobile where it matters, `--viewport-size=390,844` into `<note-name>-390.png`), then write the note: `URL:`, `Date:` (today), `Pattern borrowed:` (two or three sentences), `Why:` (which Bookflix screen it serves), `Screenshot:` (relative path). Verify the exact page URL when collecting and write the real one; the hosts below are the starting points. No logos, icons, illustrations, fonts or copy are copied; screenshots are internal and never committed (the new `.gitignore` line).
  - **Settings (A3):** GitHub Primer (`primer.style`: settings pages, toggle switch, dialog), Vercel Geist (`vercel.com/geist`: switch, modal, section layout), Shopify Polaris (`polaris-react.shopify.com`: settings layout, `Layout.AnnotatedSection`, toast), Material 3 (`m3.material.io`: switch, dialogs).
  - **Admin tables and dashboards (A4, A5):** Polaris index table, Primer data table, Plausible public live demo (stat cards beside a chart), one public status page with a work-queue feel (pick one, e.g. a vendor's public status site).
  - **Reader controls (F1):** Readest (`readest.com`), Thorium Reader (`edrlab.org`), Koodo Reader (`koodoreader.com`): their public sites and docs pages only.
  - **Streaming shelves:** Crunchyroll and Webtoon public home pages (shelf rows, cover tiles, hero), Apple TV public landing (hero banner); kept to Bookflix tokens.
  README sections: `## Settings`, `## Admin tables and dashboards`, `## Reader controls`, `## Streaming shelves`, each listing its notes by filename plus one line on which Bookflix item it feeds (A3, A4/A5, F1, shelves), and a "Rules" paragraph (patterns not assets, public pages only, screenshots stay local).
- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/DesignReferencesTest.php`. Expected: PASS. `git status` shows no `screenshots/` files.
- [ ] **Step 5: Commit:** `git commit -m "docs: design reference library for settings, admin, reader and shelves"`

### Task 4.2: The landing header leaves at the FAQ (A1)

**Files:** Modify `resources/js/landing.js`, `resources/views/components/landing/header.blade.php`, `resources/views/components/landing/{top-ten,together,faq,closing}.blade.php`, `resources/views/welcome.blade.php`; Test `tests/Browser/LandingChromeTest.php` (new; style of `tests/Browser/LandingTest.php`), `tests/Feature/LandingTest.php`.

**Interfaces:** Produces `Alpine.store('landingChrome', { pastFaq: false })`; Alpine data `landingSentinel` (on `<div x-data="landingSentinel" data-faq-sentinel aria-hidden="true" class="h-px">` placed immediately before the `faq` section in `welcome.blade.php`); `id="closing"` on the closing section.

- [ ] **Step 1: Write the failing tests.**

```php
test('the header shows over every section above the FAQ, is gone over the FAQ and the closing section, and returns', function (array $viewport) {
    // visit('/', $viewport + popularStories seeded in beforeEach), wait 1.
    // visible = getComputedStyle(header).opacity === '1' && !header.inert && header.getBoundingClientRect().bottom > 0
    // For each of hero, top-ten, scattered, together: scrollIntoView({block:'center'}) → visible true.
    // faq: scrollTo(faq.offsetTop) → wait .5 → visible false, header.inert true, opacity '0'; also with closing scrolled to.
    // scrollTo(together bottom) → visible true again.
})->with(['desktop 1280' => [['viewport' => ['width' => 1280, 'height' => 800]]], 'phone 375' => [['viewport' => ['width' => 375, 'height' => 667]]]]);

test('toggling the header shifts nothing', function () {
    // Record: scrollHeight, #together offsetTop, #faq offsetTop. Scroll to FAQ (hidden) and back (shown); the three numbers are identical.
    // CLS: PerformanceObserver pattern from LandingTest ("hero does not shift"): window.__shift < 0.01 after both toggles.
});

test('with reduced motion the header switches instantly', function () {
    // reducedMotion 'reduce': after scrolling to the FAQ, getComputedStyle(header).transitionDuration is '0s' (and opacity '0' at once).
});

test('in-page links land below the header, and the FAQ link lands where the header is gone', function () {
    // For '#top-10' and '#together': click header nav link, wait 1; section top >= header height - 1 (scroll-margin).
    // For '#faq': after click, header inert true and #faq top between 60 and 70 (scroll-mt-16 kept, P4-R5).
});

test('a reload in the middle of the FAQ starts with the header hidden, and scrolling up brings it back', function () {
    // scrollTo faq, reload via navigate(same url), script scrollTo(faq.offsetTop + 200), wait → header inert true; scrollTo(0) → inert false.
});

test('the hidden header leaves nothing to tab to', function () {
    // At the FAQ: every `header a, header button` has inert ancestor (closest('[inert]') !== null).
});
```
Feature additions in `tests/Feature/LandingTest.php`: closing section has `id="closing"`; `//div[@data-faq-sentinel]` exists exactly once and immediately precedes `//section[@data-section="faq"]` (`following-sibling::*[1]`); the existing six-section and `//body/header` count tests still pass.

- [ ] **Step 2: Run:** `vendor/bin/pest tests/Browser/LandingChromeTest.php tests/Feature/LandingTest.php`. Expected: FAIL (header never hides; no sentinel; no `#closing`).
- [ ] **Step 3: Implement.**
  - `landing.js`: `Alpine.store('landingChrome', { pastFaq: false })` at plugin load. `Alpine.data('landingSentinel', ...)`: in `init`, set `pastFaq = false`, read `header = document.querySelector('body > header')`, create `new IntersectionObserver(([entry]) => { chrome.pastFaq = !entry.isIntersecting && entry.boundingClientRect.top < 0 }, { rootMargin: `-${header.offsetHeight + 8}px 0px 0px 0px`, threshold: [0, 1] })`, observe `this.$el`; `destroy()` disconnects and resets the store (Livewire navigate leaves a clean state). The observer's first callback covers a reload mid-page.
  - `header.blade.php`: keep Phase 2's `x-data` untouched; add to `<header>`: `:inert="$store.landingChrome.pastFaq"` and `:class` entries `'-translate-y-full opacity-0 pointer-events-none'` when `pastFaq`; classes `motion-safe:transition-[transform,opacity,background-color,border-color] motion-safe:duration-(--motion-base)`. Nothing else changes.
  - Sections: `top-ten`, `together`, `faq` keep `scroll-mt-16` but make it `scroll-mt-[calc(4rem+1px)]` on all three (header is 64px + 1px border); `closing` gets `id="closing"` and `scroll-mt-[calc(4rem+1px)]`. Add the sentinel div in `welcome.blade.php` before `<x-landing.faq />`.
- [ ] **Step 4: Run:** `vendor/bin/pest tests/Browser/LandingChromeTest.php tests/Feature/LandingTest.php tests/Browser/LandingTest.php tests/Browser/LandingMenuTest.php`. Expected: PASS (Phase 2's drawer test still green). If the Phase 2 assertion `#faq top >= header height - 1` fails by 1px, fix the margin here, not the test.
- [ ] **Step 5: Commit:** `git commit -m "feat: landing header leaves at the FAQ and returns above it"`

### Task 4.3: Two-column FAQ with one open card (A2)

**Files:** Modify `resources/views/components/landing/faq.blade.php`, `resources/js/landing.js` (`landingFaq`), `resources/css/landing.css` (FAQ block); Test `tests/Browser/LandingFaqTest.php` (new), `tests/Browser/LandingTest.php` (replace its two FAQ tests), `tests/Feature/LandingTest.php`.

- [ ] **Step 1: Write the failing tests** (`LandingFaqTest.php`; helper `expanded` and `focusedId` as in `LandingTest.php`).

```php
test('"What is Bookflix?" is open on load, in the HTML and after Alpine boots', function () {
    // $html = $this->get('/')->getContent(): button #faq-question-0 has aria-expanded="true", panel #faq-answer-0 has data-open (works without JS);
    // browser at 1280×800 after wait 1: expanded === 'true,false,false,false,false'; answer 0 height > 40.
});

test('from 1024px the FAQ is two independent flex columns: three questions left, two right', function () {
    // 1024×768 and 1280×800: [data-faq-column] count 2; column 0 contains faq-question-0..2, column 1 contains 3..4;
    // both getComputedStyle(col).display === 'flex' and flexDirection 'column'; column tops equal (±1); left.left < right.left.
    // Opening question 1 changes the left column's height only: right column height and right items' tops are unchanged.
    // Below 1024 (1023×768 and 375×667): one column: every question's left is equal and tops ascend 0→4 in order.
});

test('one card is open at a time across both columns, and clicking the open card closes it', function () {
    // click q3 (right column): expanded 'false,false,false,true,false'; click q1 (left): 'false,true,false,false,false';
    // click q1 again: 'false,false,false,false,false'. Click q0 (default) opens it; click q4 → only q4.
});

test('the keyboard works: Enter and Space toggle, arrows move across the columns in order, focus shows the token ring', function () {
    // focus q0; Space → closes; Enter → opens; ArrowDown ×3 lands on faq-question-3 (first of the right column), End → 4, Home → 0.
    // Focused button: getComputedStyle(btn).boxShadow contains 'rgb(64, 97, 231)' (accent-electric) and not 'rgb(0, 95, 204)'-style default outline: outlineStyle 'none'.
    // Every button is a <button>, aria-controls resolves to an element with role=region whose aria-labelledby is the button's id.
});

test('touch works: a tap opens a card and a second tap closes it', function () {
    // visit with ['viewport'=>[375,667], 'hasTouch'=>true]; dispatch pointerdown/pointerup (pointerType 'touch') then click on #faq-question-2
    // (pattern of ReaderChromeTest "a tap on the middle third"); expanded → 'false,false,true,false,false'; tap again → all false.
});

test('the page passes the accessibility audit with the default card open', function () { /* desktop and phone: assertNoAccessibilityIssues, assertNoJavaScriptErrors */ });
```
Update `tests/Browser/LandingTest.php`: in its two FAQ tests the initial state is `'true,false,false,false,false'` and Enter on q0 now closes it (rewrite the sequences accordingly, or delete them as superseded by `LandingFaqTest` and say so in the commit). `tests/Feature/LandingTest.php`: five items, each `h3/button[@data-faq-button]`; two `[data-faq-column]` wrappers holding 3 and 2 items.

- [ ] **Step 2: Run:** `vendor/bin/pest tests/Browser/LandingFaqTest.php`. Expected: FAIL (all closed, single grid).
- [ ] **Step 3: Implement.** In `faq.blade.php` split `$faqs` into `array_slice($faqs, 0, 3, true)` and `array_slice($faqs, 3, null, true)` (keep the global `$index` so ids and `open` stay global), rendered in `<div class="flex flex-col gap-2 lg:flex-row lg:items-start">` holding two `<div data-faq-column class="flex min-w-0 flex-col gap-2 lg:flex-1">`; widen the container to `lg:max-w-[72rem]`. `landingFaq`: `open: 0`; `toggle` unchanged (it already closes the open one across all items); `move()` unchanged (DOM order is left column then right). Server-render item 0 open: `aria-expanded="true"`, `data-open`, icon open class, so there is no flash before Alpine. Space is native on a `<button>`; keep `focus-ring` (token ring), and in `landing.css` size the button text at `lg` to `clamp(1.0625rem, 1.6vw, 1.375rem)` so half-width cards read well.
- [ ] **Step 4: Run:** `vendor/bin/pest tests/Browser/LandingFaqTest.php tests/Browser/LandingTest.php tests/Feature/LandingTest.php`. Expected: PASS. Take a 1280 and a 375 screenshot and check them against DESIGN.md (no grey on the featured gradient; the FAQ is not on it).
- [ ] **Step 5: Commit:** `git commit -m "feat: two-column FAQ, first question open, one card open across columns"`

### Task 4.4: Get started above the fold and a sticky CTA for guests on phones (C4)

**Files:** Create `resources/views/components/landing/sticky-cta.blade.php`; Modify `resources/views/layouts/landing.blade.php`, `resources/views/partials/head.blade.php` (`$viewportFit`, P4-R10), `resources/css/landing.css`; Test `tests/Browser/LandingChromeTest.php`, `tests/Feature/LandingTest.php`.

- [ ] **Step 1: Write the failing tests.**

```php
test('Get started is visible without scrolling at 375×667 and 1280×800', function (array $viewport) {
    // visible Get started links in `header a[href$="/register"]`: rect.top >= 0 && rect.bottom <= innerHeight, height >= 44, visibility 'visible'.
})->with([...]);

test('a guest on a phone has a sticky Get started that respects the safe area', function () {
    // Feature: guest HTML has exactly one [data-test="sticky-cta"] linking to route('register'), inside a div (not a <header>), meta viewport contains viewport-fit=cover;
    //   a signed-in user never reaches the page (redirect) so no second assertion.
    // Browser 375×667 at scrollY 600 (together section): sticky rect.bottom === innerHeight, width === innerWidth, opacity '1';
    //   computed padding-bottom >= 12px; stylesheet rule text includes 'safe-area-inset-bottom' (read via document.styleSheets or the element's style attribute).
    // 768×1024 and 1280×800: sticky display 'none'.
});

test('the sticky CTA hides while the FAQ or the closing section is on screen, and returns above', function () {
    // 375×667: scroll to #faq → sticky inert true, opacity '0'; scroll to #closing → same; scroll to together → inert false, opacity '1'.
});

test('the sticky CTA never covers a control the reader can reach', function () {
    // 375×667: for each `main a, main button` fully scrolled into view with focus() (keyboard-like), its rect.bottom <= sticky.getBoundingClientRect().top + 1
    //   (html scroll-padding-bottom keeps focused elements above the bar). Assert getComputedStyle(documentElement).scrollPaddingBottom >= 72px.
    // With the Phase 2 drawer open the sticky CTA is inert (x-trap.inert) and sits under the drawer (z-index 30 < 40).
});
```
- [ ] **Step 2: Run:** `vendor/bin/pest tests/Browser/LandingChromeTest.php tests/Feature/LandingTest.php`. Expected: FAIL (no sticky element). The first test may already pass (the header carries Get started): keep it as the regression guard.
- [ ] **Step 3: Implement.** `sticky-cta.blade.php` (rendered in the landing layout inside `@guest`, after `<x-landing.footer />`): `<div data-test="sticky-cta" x-data :inert="$store.landingChrome.pastFaq" :class="$store.landingChrome.pastFaq && 'translate-y-full opacity-0 pointer-events-none'" class="fixed inset-x-0 bottom-0 z-30 border-t border-hairline bg-canvas/90 px-gutter pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur-md motion-safe:transition-[transform,opacity] motion-safe:duration-(--motion-base) md:hidden">` containing `<x-cta :href="route('register')" class="w-full">{{ __('Get started') }}</x-cta>`. In `landing.css` add, under `max-width: 47.99rem`, `html { scroll-padding-bottom: calc(4.75rem + env(safe-area-inset-bottom)); }` (landing.css is global, so scope it with `html:has([data-test=sticky-cta])`). `partials/head.blade.php`: viewport content gets `, viewport-fit=cover` when `$viewportFit ?? false`; the landing layout passes `viewportFit: true`.
- [ ] **Step 4: Run:** `vendor/bin/pest tests/Browser/LandingChromeTest.php tests/Browser/LandingTest.php tests/Browser/LandingMenuTest.php tests/Feature/LandingTest.php`. Expected: PASS; the existing "call to action buttons go to register" Feature test still counts one register link in the header and one in the closing section (the sticky link sits outside both).
- [ ] **Step 5: Commit:** `git commit -m "feat: guest sticky Get started on phones, hidden at the FAQ and closing"`

### Task 4.5: One `/settings` page: shell, sections, redirects, nothing lost (A3)

**Files:**
- Create: `resources/views/pages/settings/⚡index.blade.php` (via `php artisan make:livewire pages::settings.index --no-interaction`, then single-file), `resources/views/components/settings/section.blade.php`, `resources/views/components/settings/summary-card.blade.php`, `resources/js/settings.js`
- Modify: `routes/settings.php`, `app/Http/Middleware/EnsureOnboarded.php` (`EXEMPT` gets `settings.edit`), `config/bookflix.php:68` (`active` gets `settings.edit`), `resources/js/app.js` (register `settings`), the four section pages (drop `<x-pages::settings.layout>`, `settings-heading` and `#[Title]`; wrap in `x-settings.section`), callers of the old names (see Step 3), `routes/settings.php` passkey well-known
- Delete: `resources/views/pages/settings/layout.blade.php`, `resources/views/partials/settings-heading.blade.php` (after the grep shows no other caller)
- Test: `tests/Feature/Settings/SettingsPageTest.php` (new), and updates to the tests listed in Step 3

**Interfaces:** Produces route `settings.edit`; `<x-settings.section id title description>`; `<x-settings.summary-card title>`; Alpine `settingsNav` (sets `aria-current="true"` on the link of the section nearest the top, IntersectionObserver with `rootMargin: '-25% 0px -65% 0px'`).

**Today's fields (the inventory the test enumerates).**
- *Profile page:* `name`, `email`, unverified-email notice with the resend link, `avatar` (file), `headline`, `bio`, `preferredLanguages` (one checkbox per `config('bookflix.languages')`), `readingLength` (radios from `ReadingLength`), Save buttons `update-profile-button` and `update-reading-profile-button`, `deactivate-account-button` and its modal (`password`, `confirm-deactivate-account-button`). An admin sees only `name`, `email`, deactivate.
- *Preferences:* `mediaTypes` (`[data-test=types]`), `genres` (`[data-test=genres]`), `themes` (`[data-test=themes]`), `update-preferences-button`, `retake-onboarding` link.
- *Privacy:* `personalizationEnabled`, `aiExplanationsEnabled` (only when `bookflix.ai.enabled`), the "What we send" list (`#ai`), `showMature`, `confirmAdult`, `export-data-button`, `export-download-link`, status region (`#export`).
- *Security:* active sessions list and "Sign out other sessions" (new, P4-R6), `current_password`, `password`, `password_confirmation`, two-factor enable and disable, recovery codes (show, regenerate), the setup modal, passkey list, remove-passkey modal, "Add passkey".

- [ ] **Step 1: Write the failing tests** (`SettingsPageTest.php`, Fortify 2FA and passkeys configured like `SecurityTest.php`'s `beforeEach`; `config(['bookflix.ai.enabled' => true])`).

```php
dataset('reader fields', [
    'name', 'email', 'avatar', 'headline', 'bio', 'preferredLanguages', 'readingLength', 'mediaTypes', 'genres', 'themes',
    'personalizationEnabled', 'aiExplanationsEnabled', 'showMature', 'confirmAdult', 'current_password', 'password', 'password_confirmation', 'password',
]);
dataset('reader controls', [
    'update-profile-button', 'update-reading-profile-button', 'update-preferences-button', 'retake-onboarding', 'export-data-button',
    'deactivate-account-button', 'update-password-button',
]);

function modelNames(string $html): array
{
    return collect(htmlQuery($html, '//*[@*[starts-with(name(),"wire:model")]]'))->map(fn ($n) => collect($n->attributes)->first(fn ($a) => str_starts_with($a->name, 'wire:model'))->value)->all();
}

test('every setting that exists today is on the one page for a reader', function (string $field) {
    $html = $this->actingAs(User::factory()->create())->get(route('settings.edit'))->assertOk()->getContent();
    expect(modelNames($html))->toContain($field);
})->with('reader fields');

test('every control and text from today is on the page', function (string $dataTest) {
    $html = $this->actingAs(User::factory()->create())->get(route('settings.edit'))->getContent();
    expect(htmlQuery($html, '//*[@data-test="'.$dataTest.'"]')->length)->toBe(1);
})->with('reader controls');

test('the page names its sections in order and the nav links to each', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('settings.edit'))->getContent();
    expect(collect(htmlQuery($html, '//main//section[@data-settings-section]/@id'))->pluck('value')->all())->toBe(['profile', 'reading', 'privacy', 'security', 'account'])
        ->and(collect(htmlQuery($html, '//nav[@aria-label="Settings"]//a/@href'))->pluck('value')->all())->toBe(['#profile', '#reading', '#privacy', '#security', '#account'])
        ->and(htmlQuery($html, '//*[@id="ai"]')->length)->toBe(1)->and(htmlQuery($html, '//*[@id="export"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//main//h1')->length)->toBe(1);
});

test('an admin sees profile, security and account only, with no reader fields', function () { /* sections ['profile','security','account']; modelNames lacks bio, genres, personalizationEnabled, showMature */ });
test('an unverified reader sees the profile section only', function () { /* sections ['profile']; no security, no account */ });

test('the old pages keep their names and redirect to their section', function (string $name, string $anchor) {
    $this->actingAs(User::factory()->create())->get(route($name))->assertRedirect("/settings#{$anchor}");
})->with([['profile.edit', 'profile'], ['preferences.edit', 'reading'], ['privacy.edit', 'privacy'], ['security.edit', 'security']]);

test('the old routes keep their guards', function () {
    // guest → route('login'); unverified → '/email/verify' for preferences.edit, privacy.edit, security.edit; admin → 403 on preferences.edit and privacy.edit, redirect on profile.edit and security.edit.
    // `/settings` itself: guest → login; passkey well-known 'enroll' and 'manage' end with '/settings#security'.
});

test('reader-only section actions refuse an admin and an unverified account called directly', function (string $component, string $method) {
    // Livewire::actingAs(admin)->test($component)->call($method)->assertForbidden() for preferences.save, privacy.updatePrivacy/updateAi/updateMature/exportData;
    // Livewire::actingAs(User::factory()->unverified()->create())->test(same)->assertForbidden() (the guard runs in boot(), so mounting already refuses), and security refuses unverified.
});
```
- [ ] **Step 2: Run:** `vendor/bin/pest tests/Feature/Settings/SettingsPageTest.php`. Expected: FAIL (route `settings.edit` does not exist).
- [ ] **Step 3: Implement.**
  - `routes/settings.php`: `Route::livewire('settings', 'pages::settings.index')->name('settings.edit')` in the `auth` group; the four old URLs become `Route::redirect('settings/profile', '/settings#profile')->name('profile.edit')` etc. inside their current groups (P4-R8), the `security.edit` one without `password.confirm`; the bare `settings` redirect line is removed; well-known endpoints use `route('settings.edit').'#security'`.
  - `⚡index.blade.php`: `#[Computed] sections(): array` (id => label) by account (rules in Global Constraints and Step 1), `<x-page-header :title="__('Settings')" :description=...>`, the sticky nav `<nav aria-label="Settings" x-data="settingsNav" class="sticky top-14 z-20 ... bg-canvas/90 backdrop-blur-md">` (an admin has no top header: `top-0` when `can('access-admin')`) with links `#id`, `min-h-11`, current = `bg-surface-card text-ink`, a scrolling strip on phones; then the Profile grid: left column the sections, right column `<livewire:pages::settings.summary />` (created in 4.8; in this task render an empty `<aside>`). Each section is `<x-settings.section id=... title=... description=...>` wrapping its component; `x-settings.section` renders `<section id data-settings-section aria-labelledby class="scroll-mt-32 rounded-card border border-hairline bg-surface-deep p-5 sm:p-6">` with an `h2` and the description (visually the Profile page's cards). `x-settings.summary-card` is the same card without `id`, `bg-surface-deep`, `h2 text-lead`.
  - Section pages: add `boot()` guards (`Gate::authorize('use-reader-side')` plus `abort_unless(Auth::user()->hasVerifiedEmail(), 403)` on preferences and privacy; the verified check on security and the deactivate pieces). Move languages and reading length from `profile` into `preferences` (P4-R2: properties `preferredLanguages`, `readingLength`, `PreferenceChoices` rules plus `readingProfileRules()` subset, saved in `save()`); `profile.updateReadingProfile` validates avatar, headline, bio only. Keep every `data-test` and `id`.
  - Callers (point at the new route, anchors stay): `desktop-user-menu`, `empty-catalog`, `stories/gated`, `layouts/admin` (two links), `assistant/⚡index` (`#ai`), `profile/⚡show` (all `profile.edit`, `preferences.edit`, `privacy.edit` links with `#profile`, `#reading`, `#privacy`, `#export`).
  - Update tests that assumed the old pages (this list is the whole set found by grep): `tests/Feature/Admin/AdminOnlyAccessTest.php` (lines 36-37, 60-61, 100-111, 165-180: use `settings.edit`; `/settings` no longer redirects), `Admin/AdminShellTest.php` (75-101), `NavigationTest.php` (23, 61), `PageGuidesTest.php` (25-27), `Library/PreferencesTest.php` (92-102: nav is `aria-current="true"` after highlight, so assert the link exists), `Library/OnboardingTest.php` (102-127), `HomeTest.php:26`, `Discovery/HomeV1Test.php:54`, `Settings/ProfileUpdateTest.php:9`, `Settings/PrivacySettingsTest.php:8,89`, `Settings/SecurityTest.php` (the render and gate tests move to the new page; the password-confirmation redirect test becomes Task 4.7's), `Settings/ReadingProfileTest.php` (languages and length cases now call `pages::settings.preferences` `save`), `tests/Browser/LayoutTest.php:22`, `PrivacyPageTest.php`, `PreferencesPageTest.php` (visit `route('settings.edit')`, scope selectors under `#privacy` and `#reading`).
- [ ] **Step 4: Run:** `vendor/bin/pest tests/Feature/Settings tests/Feature/Admin tests/Feature/NavigationTest.php tests/Feature/PageGuidesTest.php tests/Feature/Library tests/Feature/HomeTest.php tests/Feature/Discovery/HomeV1Test.php tests/Feature/Access`. Expected: PASS. Then `vendor/bin/pint --dirty --format agent`.
- [ ] **Step 5: Commit:** `git commit -m "feat: one settings page with five sections; old settings routes redirect to them"`

### Task 4.6: Toggles that save at once, roll back on failure; explicit saves for the rest (A3)

**Files:** Create `app/Livewire/Concerns/SavesToggles.php`, `resources/views/components/settings/toggle-status.blade.php`; Modify `resources/js/settings.js` (`settingToggle`), `resources/views/pages/settings/⚡privacy.blade.php`, `⚡profile.blade.php`, `⚡preferences.blade.php`, `tests/Feature/RedForCtasOnlyTest.php`; Test `tests/Feature/Settings/ToggleSavingTest.php` (new), `tests/Feature/Settings/PrivacySettingsTest.php`, `tests/Browser/SettingsTogglesTest.php` (new).

**Interfaces:** Produces `trait SavesToggles { public array $toggleResult = []; protected function saveToggle(string $property, mixed $previous, Closure $persist): void }` (runs `$persist`; success sets `$toggleResult[$property] = ['ok' => true]` and dispatches `setting-saved`; any `Throwable` is reported, `$this->{$property} = $previous`, and `$toggleResult[$property] = ['ok' => false]`); `<x-settings.toggle-status :result="$toggleResult['x'] ?? null">` (a `role="status" aria-live="polite"` line with the two copy strings); Alpine `settingToggle` (clears a "Saved." after 3 s; `x-data="settingToggle"` on the toggle's wrapper).

- [ ] **Step 1: Write the failing tests.**

```php
test('turning personalization off saves at once and says so', function () {
    Livewire::actingAs($user = User::factory()->create())->test('pages::settings.privacy')
        ->set('personalizationEnabled', false)->assertSet('toggleResult.personalizationEnabled.ok', true)->assertDispatched('setting-saved');
    expect($user->refresh()->profile->personalization_enabled)->toBeFalse();
});

test('a failed save puts the switch back and shows the error, and nothing is stored', function () {
    Profile::saving(fn () => throw new RuntimeException('disk full'));   // forced exception in the save
    Livewire::actingAs($user = User::factory()->create())->test('pages::settings.privacy')
        ->set('personalizationEnabled', false)
        ->assertSet('personalizationEnabled', true)->assertSet('toggleResult.personalizationEnabled.ok', false)
        ->assertSee('We could not save that. Your choice was put back.');
    expect($user->profile()->value('personalization_enabled'))->toBeTrue();
});
// Same two cases for aiExplanationsEnabled (ai on; with personalization off the switch is disabled and the value is saved off, as today)
// and for showMature: turning it on without the 18+ confirmation saves nothing and shows 'Confirm that you are 18 or older to show mature and adult titles.';
// ticking the confirmation then saves both and stores adult_confirmed_at; turning it off saves at once and clears adult_confirmed_at.
```
Browser (`SettingsTogglesTest.php`, style of `PrivacyPageTest.php`, desktop and 375): click the personalization switch → `[role=status]` inside its card reads "Saved." and disappears after about 3 s; reload keeps it off; with `Profile::saving` throwing (registered in the test before `visit`, as `PrivacyPageTest` does for its fakes) the switch is back on and the error is visible and announced (`aria-live`); `assertNoAccessibilityIssues`, `assertNoJavaScriptErrors`, no sideways scroll; the switch is 24 × 40 and has a 44px row.
- [ ] **Step 2: Run:** `vendor/bin/pest tests/Feature/Settings/ToggleSavingTest.php`. Expected: FAIL (`toggleResult` undefined; the old forms need a Save click).
- [ ] **Step 3: Implement.** In `privacy`: use the trait; each switch is `wire:model.live` and an `updated<Property>` hook calls `saveToggle($property, ! $value, fn () => $this->updatePrivacy()/updateAi()/updateMature())`; drop their Save buttons and their `Flux::toast` calls (the inline status replaces them); `updateMature` keeps its validation and is only called when `showMature` is false or `confirmAdult` is true (hook on `confirmAdult` too). Keep the methods' names (existing tests call them). `exportData` stays a button. `profile` and `preferences` keep explicit Save forms: `variant="light"` (P4-R3), one per form, success keeps the Flux toast plus an inline `role=status` line (C4 "visible confirmation"), validation errors inline. Update `RedForCtasOnlyTest`'s dataset: remove `update-privacy-button`; keep the others.
- [ ] **Step 4: Run:** `vendor/bin/pest tests/Feature/Settings tests/Feature/RedForCtasOnlyTest.php tests/Browser/SettingsTogglesTest.php tests/Browser/PrivacyPageTest.php tests/Browser/PreferencesPageTest.php`. Expected: PASS; the inventory test from 4.5 still passes (fields unchanged).
- [ ] **Step 5: Commit:** `git commit -m "feat: settings toggles save immediately and roll back on failure"`

### Task 4.7: Security section with a password-confirm dialog (A3, D2-18)

**Files:** Create `app/Livewire/Concerns/RequiresRecentPassword.php`, `resources/views/components/settings/password-dialog.blade.php`; Modify `resources/js/settings.js` (store `passwordGate`), `resources/views/pages/settings/⚡security.blade.php`, `⚡two-factor-setup-modal.blade.php`, `two-factor/⚡recovery-codes.blade.php`, `resources/views/components/passkey-registration.blade.php`; Test `tests/Feature/Settings/SecurityTest.php`, `tests/Browser/SettingsSecurityTest.php` (new), `docs/superpowers/plans/mp2-phase-4-flow/` (storyboard screenshots, committed: they are Bookflix's own UI).

**Interfaces:** Produces `trait RequiresRecentPassword { protected function passwordIsFresh(): bool; protected function requirePassword(): bool }` (`passwordIsFresh`: `time() - (int) session('auth.password_confirmed_at', 0) < config('auth.password_timeout')`; `requirePassword` returns true when fresh, else dispatches browser event `password-confirmation-required` and returns false); Alpine store `passwordGate` with `run(callback): Promise<void>` (asks `GET password.confirmation`, shows the dialog if `confirmed` is false, runs `callback` after a 201 from `POST password.confirm.store`); `<x-settings.password-dialog />` (a `flux:modal name="confirm-password"` with `@csrf` form, `data-test="confirm-password-input"`, `data-test="confirm-password-submit"`, an error line).

- [ ] **Step 1: Owner checkpoint (D2-18, P4-R9).** Build the four frames as a throwaway static page (not in the repo, in the scratchpad, using the built CSS from `public/build`) and screenshot them with the Playwright CLI at 1280×800 and 375×667 into `docs/superpowers/plans/mp2-phase-4-flow/`: (1) Security section with "Turn off two-factor" and "Add passkey" buttons, (2) the dialog "Confirm your password to continue" with a password field and Cancel / Confirm, (3) wrong password error "That password is not right.", (4) success then the action running. Add the file list to the ledger and the PR body and ask the owner in chat: "Confirm the dialog flow, and should Save buttons stay white (P4-R3)?" Continue with the default if no answer yet (P4-R9).
- [ ] **Step 2: Write the failing tests.**

```php
test('the page no longer demands the password to load, and the recovery codes are not in the HTML until confirmed', function () {
    $user = userWithTwoFactor();   // helper in this file: forceFill two_factor_secret, recovery codes (encrypt(json_encode(['aaaa-bbbb'])), confirmed_at now)
    $html = $this->actingAs($user)->get(route('settings.edit'))->assertOk()->getContent();
    expect($html)->not->toContain('aaaa-bbbb')->not->toContain('two-factor-qr');
});

test('security actions do nothing without a fresh confirmation, and ask for it', function (string $component, string $method, array $args) {
    $user = userWithTwoFactor();
    Livewire::actingAs($user)->test($component)->call($method, ...$args)->assertDispatched('password-confirmation-required');
    expect($user->refresh()->two_factor_secret)->not->toBeNull();   // for disable; passkey and codes cases assert their own row/codes unchanged
})->with([['pages::settings.security', 'disable', []], ['pages::settings.security', 'deletePasskey', []],
          ['pages::settings.two-factor-setup-modal', 'startTwoFactorSetup', []], ['pages::settings.two-factor.recovery-codes', 'regenerateRecoveryCodes', []],
          ['pages::settings.two-factor.recovery-codes', 'showCodes', []]]);

test('with a fresh confirmation the same actions run', function () {
    // withSession(['auth.password_confirmed_at' => time()]): disable clears two_factor_secret; showCodes puts the codes in `recoveryCodes`; startTwoFactorSetup fills qrCodeSvg.
    // A confirmation older than config('auth.password_timeout') is stale: set it to time() - 10801 → assertDispatched again.
});

test('the password endpoint answers JSON, reports a wrong password and a throttle readably', function () {
    // postJson(route('password.confirm.store'), ['password' => 'wrong']) → 422 with errors.password; correct → 201 and session has auth.password_confirmed_at;
    // GET route('password.confirmation') (json) → ['confirmed' => false] then true. The throttled case (429 after the auth-writes limit from Task 1.3) is asserted in the browser test.
});

test('changing the password still needs the current one, and the admin keeps this section', function () { /* existing 'password can be updated' / 'correct password must be provided' tests stay; an admin's page has #security */ });
```
Browser (`SettingsSecurityTest.php`, 1280 and 375, with 2FA on): click "Turn off two-factor" with a stale session → dialog opens, focus lands in the password field, Escape closes it and focus returns to the button; wrong password → inline "That password is not right." and the dialog stays; correct password → dialog closes and 2FA is off (toast or status line); a 429 (fire the limiter first by posting wrong passwords through `page.request` until throttled) shows "Too many attempts. Try again in a minute."; "Add passkey" runs through the gate (the Fortify routes still carry `password.confirm`, so the server answers 423 without it); change-password opens in its own dialog, Save closes it with "Password updated."; the 2FA setup modal (QR and recovery codes) still opens after confirmation; `assertNoAccessibilityIssues`, no sideways scroll.
- [ ] **Step 3: Run:** `vendor/bin/pest tests/Feature/Settings/SecurityTest.php`. Expected: FAIL (page still behind `password.confirm`; actions run unguarded).
- [ ] **Step 4: Implement.** The `security.edit` redirect is already free of `password.confirm` (4.5). In `security`, `two-factor-setup-modal` and `recovery-codes` use `RequiresRecentPassword`: `disable`, `deletePasskey`, `startTwoFactorSetup`, `confirmTwoFactor`, `regenerateRecoveryCodes` start with `if (! $this->requirePassword()) { return; }`. Recovery codes: remove the load in `mount()`; add `showCodes()` (guarded) that loads them into the locked `recoveryCodes`, so nothing secret is serialized on first render. Buttons call `$store.passwordGate.run(() => $wire.disable())` (and the same for the setup trigger, which also opens the modal, regenerate, show codes, delete passkey, and `x-passkey-registration`'s `register()` before `Passkeys.register`). Change password moves into `flux:modal name="change-password"` (the existing form unchanged; `Flux::modal('change-password')->close()` on success). `password-dialog` and `passwordGate` are described in Interfaces; the dialog posts the form with `fetch` and `Accept: application/json`, shows `errors.password[0]` on 422 and the throttle text on 429. `Features` config stays as is, so Fortify's routes keep `password.confirm`. Add `#[On('password-confirmation-required')]`-free design: the event is handled by the Alpine store (open the dialog; after success, re-run nothing automatically, the person clicks again, which avoids replaying a stale action).
- [ ] **Step 5: Run:** `vendor/bin/pest tests/Feature/Settings tests/Feature/Auth tests/Feature/Security tests/Browser/SettingsSecurityTest.php`. Expected: PASS (Task 1.1's persistent `RequirePassword` tests still pass: nothing here removes them). Commit: `git commit -m "feat: security section on the settings page with a password confirmation dialog"`

### Task 4.8: Account section, summary column, final checks (A3)

**Files:** Create `resources/views/pages/settings/⚡summary.blade.php`; Modify `⚡index.blade.php`, `⚡deactivate-account-form.blade.php`, `⚡deactivate-account-modal.blade.php`; Test `tests/Feature/Settings/SettingsSummaryTest.php` (new), `tests/Feature/Settings/DeactivateAccountTest.php`, `tests/Browser/SettingsPageTest.php` (new), `tests/Browser/NoHorizontalScrollTest.php` if Phase 2 created it (add `route('settings.edit')`).

**Interfaces:** Produces Livewire component `pages::settings.summary` (`#[On('setting-saved')] refresh(): void`) holding `<x-settings.summary-card>`s.

- [ ] **Step 1: Write the failing tests.**

```php
test('the summary column shows the account and the current privacy choices, and follows a saved toggle', function () {
    // Livewire::actingAs($user)->test('pages::settings.summary')->assertSee($user->name)->assertSee($user->email)->assertSee('Personalization on');
    // then $user->profile->update(['personalization_enabled' => false]); ->dispatch('setting-saved')->assertSee('Personalization off').
    // 2FA status line: 'Two-factor off' vs 'Two-factor on'. An admin's summary shows the account card only.
});

test('deactivating still needs the password and still explains what happens', function () {
    // existing DeactivateAccountTest cases pass unchanged against pages::settings.deactivate-account-modal;
    // GET route('settings.edit') (reader) contains the modal copy 'Please enter your password to confirm.' and the retention days from config.
});
```
Browser (`SettingsPageTest.php`, viewports 1280×800, 1024×768, 375×667): layout (at ≥1024 settings column and summary column side by side with the summary 360px wide, one column below; sections stack in order); the sticky nav stays at the top after scrolling (rect.top within 0 to 64) and its `aria-current="true"` link follows the section in view (scroll to `#security` → the Security link is current, scroll to `#profile` → Profile); clicking a nav link lands its section below the header (rect.top >= header + nav height - 2); `/settings#privacy` opened directly lands on Privacy; the old `/settings/privacy` URL ends at `/settings#privacy` in a real browser with Privacy in view; deactivate opens its dialog, wrong password shows an inline error, Escape closes and returns focus to the button (do not submit the correct password); `assertNoJavaScriptErrors`, `assertNoAccessibilityIssues`, `scrollWidth <= innerWidth` at 320, 375 and 1440; every setting card has the same surface as Profile (`getComputedStyle(card).backgroundColor` equals the profile page card's).
- [ ] **Step 2: Run:** `vendor/bin/pest tests/Feature/Settings/SettingsSummaryTest.php`. Expected: FAIL (no summary component).
- [ ] **Step 3: Implement.** `⚡summary`: card 1 "Your account" (avatar, name, email, verified or not, two-factor on or off, link to `#security`), card 2 "Your choices" (Personalization, AI explanations when AI is on, Mature titles shown or hidden, each linking to `#privacy`), for readers; an admin gets card 1 only (Phase 5 builds the admin settings). The Account section holds the existing deactivate form unchanged in behavior (dashed danger card, `danger` variant, password dialog); move its "Danger Zone" label into the section's description. Phase 7 adds its quota card and Phase 9 its country field by adding a summary-card or a field here: leave a Blade comment `{{-- Phase 7: storage quota card; Phase 9: country field --}}` in `⚡summary` and in the Reading section.
- [ ] **Step 4: Run:** `vendor/bin/pest tests/Feature/Settings tests/Browser/SettingsPageTest.php tests/Browser/SettingsTogglesTest.php tests/Browser/SettingsSecurityTest.php tests/Browser/LayoutTest.php`. Expected: PASS. Take 1280 and 375 screenshots of `/settings` next to `/profile` and check them against DESIGN.md and `PRODUCT.md` (premium bar: no AI-template look, no grey on gradient, tokens only); fix and re-run. Commit: `git commit -m "feat: settings summary column, account section and page checks"`

### Task 4.9: Active sessions (A3 Security, P4-R6)

**Files:** Create `app/Support/DeviceLabel.php` (static `fromUserAgent(?string): string`, the P4-R6 rule); Modify `resources/views/pages/settings/⚡security.blade.php` (`sessions()` computed, `signOutOtherSessions(string $password)`, a "Sessions" card); Test `tests/Feature/Settings/ActiveSessionsTest.php`, `tests/Browser/SettingsSecurityTest.php`.

- [ ] **Step 1: Write the failing tests** (insert rows with `DB::table('sessions')->insert([...])`; `withSession(['auth.password_confirmed_at' => time()])` where a fresh confirmation is needed).

```php
test('the list shows only this user\'s sessions', function () { /* two rows for the user, one for another user: the other user's IP and agent are absent from the HTML */ });
test('the current session is marked "This device"', function () { /* row with id = session()->getId() carries the label; the other does not */ });
test('signing out other sessions keeps this one and deletes the rest', function () { /* password 'password'; ->call('signOutOtherSessions', 'password'); this row remains, others and only this user's others are gone; success text shown */ });
test('a wrong password changes nothing and shows the error', function () { /* assertHasErrors; all rows remain */ });
test('it is refused without a recent confirmation', function () { /* no session stamp: assertDispatched('password-confirmation-required'); rows unchanged */ });
test('device labels', function () { /* DeviceLabel: Chrome/Mac UA => 'Chrome on Mac'; iPhone Safari => 'Safari on iPhone'; null => 'Browser on device' */ });
```
Browser: with a stale confirmation the button opens the Task 4.7 dialog; after confirming, the others vanish from the list and "Signed out of 1 other session." appears; 375 and 1280, `assertNoAccessibilityIssues`, no sideways scroll (IPs and labels wrap).
- [ ] **Step 2: Run:** `vendor/bin/pest tests/Feature/Settings/ActiveSessionsTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** as P4-R6: `requirePassword()` first, then `validate(['password' => currentPasswordRules()])`, `Auth::logoutOtherDevices`, `DB::table('sessions')->where('user_id', ...)->where('id', '!=', session()->getId())->delete()`, count shown in the success line; the button runs through `$store.passwordGate`.
- [ ] **Step 4: Run:** `vendor/bin/pest tests/Feature/Settings tests/Browser/SettingsSecurityTest.php`. Expected: PASS. Commit: `git commit -m "feat: list active sessions and sign out the others"`

## Phase exit

- [ ] `composer test` passes; run explicitly: `vendor/bin/pest tests/Browser/LandingTest.php tests/Browser/LandingChromeTest.php tests/Browser/LandingFaqTest.php tests/Browser/LandingMenuTest.php tests/Browser/SettingsPageTest.php tests/Browser/SettingsTogglesTest.php tests/Browser/SettingsSecurityTest.php tests/Browser/PrivacyPageTest.php tests/Browser/PreferencesPageTest.php tests/Browser/LayoutTest.php tests/Browser/AdminShellTest.php tests/Browser/AccessibilityTest.php`.
- [ ] Whole-branch review on the most capable model against brief F10, A1 to A3, the C4 bullets and this plan; one fix wave.
- [ ] PR body (Part I report table), one row each: F10 (Fixed: `docs/design-references/*`, `.gitignore`); A1 (Fixed: `landing.js`, `landing/header`, `welcome`); A2 (Fixed: `landing/faq`, `landing.css`); A3 (Fixed incl. active sessions: `pages/settings/*`, `routes/settings.php`, `components/settings/*`; Needs decision: Save button colour P4-R3, password dialog P4-R9; quota card moved to Phase 7); C4 main CTA above the fold (Already OK, now tested: `landing/header`); C4 sticky CTA (Fixed: `landing/sticky-cta`, `partials/head`). Name the references used (Primer, Geist, Polaris, Material 3) and why, as A3 asks. Link the storyboard screenshots from `docs/superpowers/plans/mp2-phase-4-flow/`.
- [ ] Open the PR against `main` (stack per D2-24 if earlier phases are unmerged); no `Co-Authored-By`; never merge.
