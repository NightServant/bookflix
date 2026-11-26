# Phase 5: Admin redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every admin table, the Overview and the sidebar follow one pattern (stat cards, chart, toolbar, 10-row table, four button variants), and an admin settings page exists whose email cannot be changed even by a forged request.

**Architecture:** Shared Blade components and one Livewire trait (`AdminTable`) carry the pattern; a pure `DailySeries` helper turns existing timestamps into chart series. The Overview reads a `WorkQueue` fed by registered `WorkQueueSource` classes, so later phases add rows by adding a class to config. The sidebar shrinks to five destinations whose pages keep their URLs and gain link tabs (D2-9).

**Tech Stack:** Laravel 13, Livewire 4 single-file pages, Flux free (table, modal, badge), Alpine, Tailwind v4, Chart.js 4.5.1 (lazy chunk), Pest 5 + pest-plugin-browser, Postgres.

**Spec:** `docs/superpowers/specs/2026-11-26-cleanup-brief.md` sections A4, A5, A6; `docs/superpowers/plans/2026-11-26-bookflix-masterplan-2.md` Part F "Phase 5: Admin redesign", Part F.0 row 5, Part B (D2-9, D2-18).
**Branch:** `mp2-phase-5-admin` · **Ledger:** `docs/superpowers/plans/mp2-phase-5-ledger.md` · **Depends on:** Phase 1 (`#[Locked]` admin ids, `AdminRouteGuardTest`), Phase 2 (admin drawer fixes, no-sideways-scroll helpers), Phase 4 (settings registry: `x-settings.section`, `x-settings.summary-card`, route `settings.edit`).

## Global Constraints

Every Global Constraint in the masterplan applies. Phase-specific:

- Rows per page: 10 on every admin table; page, search (`q`), sort and every filter live in the query string.
- Sort is a whitelist per page; an unknown `sort` value falls back to the page default and is never put in SQL.
- Admin pages use only the four `x-cta` variants (`primary`, `secondary`, `outline`, `danger`); no `<flux:button` in `resources/views/pages/admin/**` (the back link in `x-admin-header` is a component, not a page). One `primary` per page.
- No `wire:confirm` in `resources/views/pages/admin/**`. Danger actions open `x-confirm-dialog`.
- Chart colours come from tokens (`--color-accent-electric`, `--color-ink-subtle`, `--color-success`, `--color-ink-muted`); brand red is never a series colour. Every chart has an `sr-only` table with the same numbers.
- Stat cards and charts read real queries only. A metric with no data source is omitted. No new tracking columns or migrations in this phase.
- Days in every series are UTC calendar days (`config('app.timezone')` is `UTC`), 30 days ending today, zero-filled.
- Below 768px a table becomes stacked cards (each `<td>` carries `data-label`); at 375, 768, 1024 and 1280 `document.documentElement.scrollWidth <= window.innerWidth`.
- The admin email is read-only: no input for it on `admin.settings`, and the server rejects a change to an admin's own email from any path.
- No new Composer or npm dependency. Chart.js is already installed; register only the controllers a chart uses.

## Review Focus

1. **A filter, search or sort change leaves the person on page 3 of a result that now has one page**, or a search of `%` or `_` matches everything. → Task 5.2 (trait tests) and Task 5.6 (Users search `%`).
2. **A forged email change from an admin account succeeds** through the Livewire page or the Fortify profile path. → Task 5.8.
3. **One failing work-queue source (for example a later harvest database that is down) blanks the whole Overview.** → Task 5.7.
4. **A danger action runs without the dialog, or the dialog of row A deletes row B.** → Task 5.4 (staff-pick delete, browser) and Task 5.6 (Reports Hide, per-row dialog ids).
5. **A long unbroken title or 60-character email pushes an admin page sideways at 375px, or the stacked cards lose their labels.** → Task 5.9.

## Cross-phase interfaces

**Consumes:** Phase 1: `#[Locked]` on admin id props, `tests/Feature/Security/AdminRouteGuardTest.php` (every `admin.*` route must carry `auth,verified,can:access-admin`; `admin.settings` is added inside the same route group). Phase 2: the admin drawer behaviour in `layouts/admin.blade.php` (keep it when editing the sidebar), `tests/Browser/NoHorizontalScrollTest.php` helpers if present. Phase 4: `x-settings.section` (`id`, `title`, `description`), `x-settings.summary-card`, route `settings.edit`, and the password-confirmation dialog used by its security actions (D2-18).

**Produces (exact):**
- `x-admin.stat-card` (props `title`, `value`, `subtext`, optional `tone` = `default|error`), `x-admin.chart-card` (`title`, `type` = `line|bar`, `labels` list of string, `datasets` list of `{label, data, stack?}`, optional `description`), `x-admin.tabs` (`items` list of `{label, route, badge?, active?, current?}`), `x-confirm-dialog` (`name`, `title`, `body?`, `confirm`, `action`, `danger` default true, optional slot `trigger`), upgraded `x-admin-toolbar` (prop `search` = Livewire property name, `placeholder`) and `x-admin-table` (props `label`, `paginator?`, `noun?`).
- `x-cta` variants `primary`, `secondary`, `outline`, `danger` (`light` kept for reader pages, P5-R2).
- `App\Livewire\Concerns\AdminTable` (below), `App\Admin\Metrics\DailySeries::count(Builder $query, string $column, int $days = 30): array<string, int>` and `::backlog(int $current, array $opened, array $resolved): array<string, int>`.
- `App\Admin\WorkQueue` (`items(): Collection<int, WorkQueueItem>`, `filtered(?string $area, ?string $severity): Collection`), `App\Admin\WorkQueueItem` (readonly: `area`, `severity`, `label`, `count`, `oldestAt`, `status`, `ctaLabel`, `ctaUrl`), `App\Admin\Contracts\WorkQueueSource` (`items(): iterable`), config `bookflix.admin_work_queue_sources`, `App\Admin\Metrics\OverviewMetrics::get(): array`.
- Route `admin.settings` (GET `/admin/settings`, page `pages::admin.settings`), `App\Http\Middleware\SendAdminsToAdminSettings`, scope `ContentSource::awaitingVerification()`.
- Config `bookflix.admin_navigation`: five entries `{label, icon, route, active: list<string>, count?: list<string>, tabs: list<{label, route, active, count?}>}`.

## Rulings

| # | Ruling | Cost if wrong |
|---|---|---|
| P5-R1 | The brief's "one task" for the shared pattern is split in two (5.1 components and metrics, 5.2 trait) so each can be reviewed alone. | None. |
| P5-R2 | `x-cta` keeps `variant="light"` because 7 reader-side views use it; admin pages use only the four variants (a static test enforces it). | Low; reader buttons are Phase 4's. |
| P5-R3 | Native `wire:confirm` is replaced by `x-confirm-dialog` (Flux modal) on every admin page, including `media/⚡edit`. Row menus open it client-side with `$flux.modal('<name>').show()`, so no server state is added. | Low; one modal per row, at most 10 rows. |
| P5-R4 | **Contradiction:** the masterplan says to test "Fortify's profile update action", but `Features::updateProfileInformation()` is off in `config/fortify.php`, there is no `UpdateUserProfileInformation` class and no `/user/profile-information` route. The only real email path is the Livewire `updateProfileInformation` on the settings profile page. The guard therefore sits on the `User` model (`updating` hook: an admin changing their own email throws a `ValidationException`), which every path (Livewire, a future Fortify action) ends in; a test also pins the Fortify route as absent. | Low; if the owner enables the Fortify feature the model guard still holds. |
| P5-R5 | Admins stop using settings for email by routing: `SendAdminsToAdminSettings` redirects an admin from `settings.edit` and `profile.edit` to `admin.settings`. The model guard is the real protection; the redirect is the courtesy. | Low. |
| P5-R6 | Stacked cards below 768px (not a pinned first column): every table has at most 7 columns and data-labelled cells. | Low. |
| P5-R7 | A page with two lists paginates its primary table with the trait; the secondary list is a fixed "latest 10" without controls (Sync: runs primary, sources secondary since the 11 configured providers fit one view; Models: versions primary, job runs secondary). | Low. |
| P5-R8 | Charts use these columns: duplicates `created_at` and `updated_at` where `status != pending` (the row only changes when resolved, and is only re-scored while pending); classification `reviewed_at` by `review_status`; media `created_at`; reading files `verified_at`; Sync `ingestion_runs.started_at` by `status`; Models `ml_job_runs.started_at` by `status`; Users `created_at`; Reports `created_at` and `handled_at`. Staff picks has no timestamp worth charting (a handful of rows), so its chart is stories per pick (`withCount('items')`, top 10). "Active readers" is omitted from the Overview: no last-seen column exists and none is added; the fourth chart is sign-ups. | Medium if the owner wants active readers; needs a tracking decision. |
| P5-R9 | Users stays read-only: no row actions exist, accounts are never hard-deleted, and an admin deactivating readers is not in the brief beyond an example. Danger is exercised on Reports (Hide), Staff picks (Delete), Classification (Remove) and Media edit (Remove). | Low. |
| P5-R10 | Work-queue sources reuse `AdminCounts` (same numbers as the sidebar badges). A failing sync means: an enabled provider whose latest `IngestionRun` has `status = failed`; its `oldestAt` is the oldest such `started_at`. No "stale source" threshold is invented. | Low. |
| P5-R11 | `admin.settings` carries name dialog, read-only email, change password and two-factor (reusing the existing Livewire two-factor components). Passkeys are not in A6 and are not rebuilt: **Needs decision** (admins lose passkey management once `security.edit` redirects to `/settings`; the executor records it). | Medium; the owner may want passkeys for admins. |
| P5-R12 | A sidebar destination links to its group's first tab; the Review badge is duplicates plus classifications, each tab keeps its own badge. | Low. |

## Baseline re-check

Run first, record in the ledger:
1. `php artisan route:list` (full): confirm the 13 `admin.*` routes in `routes/admin.php` and that `settings.edit` exists (Phase 4). If the old `profile.edit` is still a page, note it.
2. Open `config/bookflix.php` `admin_navigation` (ten items, four groups) and `resources/views/layouts/admin.blade.php`, `layouts/admin-item.blade.php`: re-read any Phase 2 drawer edits and keep them.
3. `grep -rn "wire:confirm\|<flux:button" resources/views/pages/admin` and `grep -rhn "x-cta" resources/views | grep -o 'variant="[a-z]*"' | sort | uniq -c` (Part A: 5 danger, 7 light, 18 outline, 73 secondary).
4. Confirm `config/fortify.php` features has no `updateProfileInformation` and `app/Actions/Fortify` has only `CreateNewUser` and `ResetUserPassword` (P5-R4).
5. Find Phase 4's settings page and its profile save method name (today `updateProfileInformation` on `pages::settings.profile`), and how its security actions confirm a password.
6. `grep -n "25\|paginate" tests/Feature/Admin/AdminUsersTest.php tests/Feature/Admin/ClassificationReviewTest.php` (the 25-per-page tests Task 5.3 and 5.6 update).
7. Confirm `ls tests/Browser/NoHorizontalScrollTest.php` (Phase 2); Task 5.9 adds its own file either way.

## Tasks

### Task 5.1: Metrics helper and the shared components

**Files:**
- Create: `app/Admin/Metrics/DailySeries.php`, `resources/views/components/admin/stat-card.blade.php`, `resources/views/components/admin/chart-card.blade.php`, `resources/views/components/admin/tabs.blade.php`, `resources/views/components/confirm-dialog.blade.php`, `resources/js/admin-chart.js`, `resources/js/admin-charts.js`
- Modify: `resources/views/components/cta.blade.php` (add `outline`), `resources/views/components/admin-toolbar.blade.php`, `resources/views/components/admin-table.blade.php`, `resources/css/app.css` (`.admin-table`, `.admin-truncate`), `resources/js/app.js` (register `adminChart`)
- Test: `tests/Feature/Admin/DailySeriesTest.php`, `tests/Feature/Components/AdminComponentsTest.php`, `tests/Feature/Components/CtaTest.php` (add `outline` to the variants dataset), `tests/Browser/Components/CtaTest.php`

**Interfaces:** Produces the component and `DailySeries` names in the registry above.

- [ ] **Step 1: Write the failing tests**

```php
// DailySeriesTest.php
test('count returns one zero-filled key per UTC day, oldest first, ending today', function () {
    User::factory()->count(2)->create(['created_at' => now()->subDays(2)]);
    User::factory()->create(['created_at' => now()->subDays(45)]);   // outside the window

    $series = DailySeries::count(User::query(), 'created_at', 30);

    expect(array_keys($series))->toHaveCount(30)
        ->and(array_key_last($series))->toBe(now()->toDateString())
        ->and(array_key_first($series))->toBe(now()->subDays(29)->toDateString())
        ->and($series[now()->subDays(2)->toDateString()])->toBe(2)
        ->and(array_sum($series))->toBe(2);
});

test('count does not change the query it is given and refuses a column that is not an identifier', function () {
    $query = User::query()->where('is_admin', false);
    DailySeries::count($query, 'created_at');
    expect($query->toBase()->columns)->toBeNull();
    expect(fn () => DailySeries::count($query, 'created_at; drop table users'))->toThrow(InvalidArgumentException::class);
});

test('backlog works backwards from today so the last day equals the real pending count', function () {
    $opened = ['2026-11-24' => 3, '2026-11-25' => 0, '2026-11-26' => 1];
    $resolved = ['2026-11-24' => 0, '2026-11-25' => 2, '2026-11-26' => 0];

    expect(DailySeries::backlog(10, $opened, $resolved))->toBe(['2026-11-24' => 11, '2026-11-25' => 9, '2026-11-26' => 10]);
});
```

```php
// AdminComponentsTest.php (use renderedComponent() and htmlQuery() from tests/Pest.php)
test('a stat card shows title, big number and subtext; tone error colours the number', ...)   // //*[@data-test="stat-card"], value inside .text-3xl; tone=error adds text-red-400
test('a chart card ships labels and datasets to Alpine and an sr-only table with the same numbers', function () {
    $html = renderedComponent('<x-admin.chart-card title="Found and resolved" type="bar" :labels="[\'Mon\',\'Tue\']" :datasets="[[\'label\' => \'Found\', \'data\' => [3, 0]], [\'label\' => \'Resolved\', \'data\' => [1, 2]]]" />');
    expect($html)->toContain('x-data="adminChart(')->toContain('data-test="admin-chart"')
        ->and(htmlQuery($html, '//table[contains(@class,"sr-only")]//tr')->length)->toBe(3)   // header + 2 days
        ->and(htmlQuery($html, '//canvas[@aria-hidden="true"]')->length)->toBe(1);
});
test('admin tabs render a labelled nav of links with badges; the current one has aria-current page', ...)  // items with explicit current => true
test('the toolbar search is a debounced live input bound to the named property', function () {
    $html = renderedComponent('<x-admin-toolbar search="search" placeholder="Search names" />');
    expect(htmlQuery($html, '//input[@type="search"][@aria-label="Search names"][@*[name()="wire:model.live.debounce.300ms"]="search"]')->length)->toBe(1);
});
test('the table footer says which rows are shown, of how many', function () {
    $page = new LengthAwarePaginator(range(1, 10), 43, 10, 2);
    expect(renderedComponent('<x-admin-table label="Users" :paginator="$p" noun="accounts">x</x-admin-table>', ['p' => $page]))
        ->toContain('Showing 11–20 of 43 accounts')->toContain('admin-table');
});
test('a confirm dialog puts the action behind a Cancel and a danger Confirm and never uses wire:confirm', function () {
    $html = renderedComponent('<x-confirm-dialog name="hide-5" title="Hide this review?" body="It leaves every page." confirm="Hide review" action="hide(5)" />');
    expect(htmlQuery($html, '//*[@data-test="confirm-hide-5"][@*[name()="wire:click"]="hide(5)"]')->length)->toBe(1)
        ->and($html)->toContain('Hide this review?')->toContain('Cancel')->not->toContain('wire:confirm');
});
```
CtaTest browser: add an `outline` case asserting computed `border-top-color` is the hairline `rgb(65, 65, 65)` and `background-color` is `rgba(0, 0, 0, 0)`.

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/pest tests/Feature/Admin/DailySeriesTest.php tests/Feature/Components/AdminComponentsTest.php tests/Feature/Components/CtaTest.php`
Expected: FAIL (class `DailySeries` not found; components `x-admin.stat-card` etc. not found; `outline` renders as primary).

- [ ] **Step 3: Implement**
  - `DailySeries::count`: clone the query, `whereBetween` the first and last UTC day, `selectRaw("to_char({$wrapped}, 'YYYY-MM-DD') as day, count(*) as total")` grouped by day, merged over a zero-filled `Carbon` range. Wrap the column with `$query->getQuery()->getGrammar()->wrap($column)` after checking it against `/^[a-z_]+$/` (else `InvalidArgumentException`). `backlog($current, $opened, $resolved)`: iterate days newest to oldest, `value[d] = running`, then `running -= opened[d] - resolved[d]`; return oldest first.
  - `x-admin.stat-card`: `surface-card` card, title `text-meta text-ink-subtle`, number `text-3xl font-semibold text-ink tabular-nums`, subtext `text-meta text-ink-subtle`. `data-test="stat-card"`.
  - `x-admin.chart-card`: surface card, title, `wire:ignore` wrapper `x-data="adminChart(@js([...]))"`, a fixed-height box with `<canvas aria-hidden="true">`, then an `sr-only` `<table>` (caption = title). `data-test="admin-chart"`.
  - `admin-chart.js` (Alpine plugin, same shape as `version-chart.js`): `init()` does `await import('./admin-charts.js')` then draws; `destroy()` destroys the chart. `admin-charts.js` imports from `chart.js` only `BarController, BarElement, LineController, LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Legend` and registers them; series colours are read from the four tokens by index; `animation: false` under `prefers-reduced-motion`; stacked when a dataset has `stack`; y axis integer ticks, `beginAtZero`. Register `Alpine.plugin(adminChart)` in `app.js`. No inline script, no nonce needed (the chunk loads from `'self'`; `tests/Browser/CspTest.php` stays green).
  - `x-admin.tabs`: `<nav aria-label="{{ __('Section pages') }}">` of link tabs (`wire:navigate`, min 44px, `aria-current="page"` on the current one, badge as a small pill). Current = `$item['current']` if set, else `request()->routeIs(...($item['active'] ?? [$item['route']]))`.
  - `x-confirm-dialog`: optional `$trigger` slot wrapped in `<flux:modal.trigger :name>`, then `<flux:modal :name class="max-w-md">` with title, body, a `Cancel` (`x-cta outline` inside `<flux:modal.close>`) and the confirm `x-cta` (`danger` by default, else `secondary`) with `wire:click="{{ $action }}"`, `x-on:click="$flux.modal('{{ $name }}').close()"`, `data-test="confirm-{{ $name }}"`.
  - `x-cta`: map `outline` to Flux `outline` with `border-hairline! bg-transparent! text-ink! hover:bg-surface-card!`; `primary`, `secondary`, `danger` unchanged.
  - `x-admin-toolbar`: when `search` is set, render the debounced search input first; the slot holds tabs and selects. `x-admin-table`: wrapper class `admin-table`; when `paginator` is given, a footer with "Showing :from–:to of :total :noun" and `<x-pager>`.
  - `app.css`: `.admin-table` header cells `text-ink-muted`, 12px semibold uppercase-free, hairline bottom border; row hover step; `.admin-truncate` single-line ellipsis (`max-width: 24rem`); at `max-width: 767px` table parts become blocks, `thead` hidden, each `tr` a `bg-surface-card` rounded card, `td::before { content: attr(data-label) }` in `ink-muted`, `.admin-truncate` wraps with `overflow-wrap: anywhere`.

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/pest tests/Feature/Admin/DailySeriesTest.php tests/Feature/Components && vendor/bin/pest tests/Browser/Components/CtaTest.php tests/Browser/CspTest.php`
Expected: PASS. `npm run build` succeeds and shows a separate `admin-charts` chunk.

- [ ] **Step 5: Commit**: `git commit -m "feat: admin stat cards, chart cards, tabs, confirm dialog, outline button and daily series"`

### Task 5.2: The AdminTable trait

**Files:**
- Create: `app/Livewire/Concerns/AdminTable.php`, `tests/Support/AdminTableProbe.php`, `tests/Feature/Admin/AdminTableTraitTest.php`

**Interfaces:** Produces `App\Livewire\Concerns\AdminTable`: `use WithPagination`; `public const PER_PAGE = 10`; `#[Url(as: 'q')] public string $search = ''`; `#[Url] public string $sort = ''` (`name` ascending, `-name` descending); `abstract protected function sortable(): array` (list of column names); `protected function defaultSort(): string` (default `''`); `protected function filterProperties(): array` (names of the page's `#[Url]` filter properties, default `[]`); `public function sortBy(string $column): void`; `public function updatedSort(): void`; `public function updated(string $name, mixed $value): void` (reset page when `search`, `sort` or a filter property changes); `public function sortedBy(string $column): bool`; `public function sortDirection(): string`; `protected function ordered(Builder $query, ?string $tieBreaker = 'id'): Builder`; `protected function paginated(Builder $query): LengthAwarePaginator` (10 per page, jumps to the last page if the requested one is empty); `protected function like(): ?string` (`%term%` with `% _ \` escaped, or null when blank); `protected function choice(string $value, array $allowed, string $default): string`.

- [ ] **Step 1: Write the failing tests.** `tests/Support/AdminTableProbe.php` is a class-based Livewire component (`Tests\Support\AdminTableProbe`) using the trait over `User`: `#[Url] public string $role = ''`, `filterProperties(): ['role']`, `sortable(): ['name', 'created_at']`, `defaultSort(): '-created_at'`, `rows()` = `paginated(ordered(User::query()->when($this->like(), fn ($q, $like) => $q->where('name', 'ilike', $like))->when($this->choice($this->role, ['admin', 'reader'], '') === 'admin', fn ($q) => $q->where('is_admin', true))))`, and a `render()` that prints each row's name.

```php
function probe(array $query = []) { return Livewire::withQueryParams($query)->test(AdminTableProbe::class); }

test('search, a filter and the page work together and the total is exact', function () {
    User::factory()->count(12)->create(['name' => 'Ada Match', 'is_admin' => true]);
    User::factory()->count(5)->create(['name' => 'Ada Reader']);
    User::factory()->count(20)->create(['name' => 'Someone Else']);

    $page = probe(['q' => 'ada', 'role' => 'admin', 'page' => 2])->instance()->rows();

    expect($page->total())->toBe(12)->and($page->count())->toBe(2)->and($page->perPage())->toBe(10);
});

test('changing the search, the sort or a filter returns to page 1', function (string $property, string $value) {
    User::factory()->count(25)->create();
    probe(['page' => 3])->set($property, $value)->assertSet('paginators.page', 1);
})->with([['search', 'x'], ['sort', 'name'], ['role', 'admin']]);

test('a page past the end shows the last page instead of an empty table', function () {
    User::factory()->count(25)->create();
    expect(probe(['page' => 9])->instance()->rows()->currentPage())->toBe(3);
});

test('an unknown sort falls back to the default and never reaches SQL', function () {
    User::factory()->count(3)->create();
    probe(['sort' => 'password'])->assertSet('sort', '')->call('sortBy', 'two_factor_secret')->assertSet('sort', '');
    probe()->call('sortBy', 'name')->assertSet('sort', 'name')->call('sortBy', 'name')->assertSet('sort', '-name');
});

test('percent and underscore in a search are literal', function () {
    User::factory()->create(['name' => 'Plain Name']);
    expect(probe(['q' => '%'])->instance()->rows()->total())->toBe(0);
});

test('search, sort and every filter are in the query string', function (string $property) {
    $attributes = (new ReflectionProperty(AdminTableProbe::class, $property))->getAttributes(Url::class);
    expect($attributes)->toHaveCount(1);
})->with(['search', 'sort', 'role']);
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest tests/Feature/Admin/AdminTableTraitTest.php`
Expected: FAIL, trait `App\Livewire\Concerns\AdminTable` not found.

- [ ] **Step 3: Implement.** `sortBy`: ignore a column not in `sortable()`; if `$sort === $column` set `-$column`, else `$column`. `updatedSort`: if `ltrim($sort, '-')` is not whitelisted, set `''`. `ordered`: effective sort = `$sort` if whitelisted else `defaultSort()`; `orderBy(column, direction)` then `orderBy($tieBreaker)` when not null. `paginated`: `paginate(self::PER_PAGE)`; if empty and page greater than 1, `setPage($last)` and query again (same pattern as `classifications/⚡index` today). `updated`: `resetPage()` when `$name` is `search`, `sort` or in `filterProperties()`. Run `vendor/bin/pint --dirty --format agent`.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/pest tests/Feature/Admin/AdminTableTraitTest.php && composer analyse` (use the PHPStan script name in `composer.json`)
Expected: PASS, PHPStan level 7 clean.

- [ ] **Step 5: Commit**: `git commit -m "feat: AdminTable trait for url-backed search, filters, sort whitelist and 10-row pages"`

### Task 5.3: Review group: Duplicates and Classification

**Files:**
- Modify: `resources/views/pages/admin/duplicates/⚡index.blade.php`, `resources/views/pages/admin/classifications/⚡index.blade.php`
- Create: `tests/Feature/Admin/AdminTablePatternTest.php` (the shared per-page pattern checks; later tasks extend its datasets)
- Test: `tests/Feature/Admin/AdminDuplicatesTest.php`, `tests/Feature/Admin/ClassificationReviewTest.php` (update the 25-per-page expectations at lines 30-34 and 185 to 10)

**Interfaces:** Consumes Tasks 5.1 and 5.2. Page properties: duplicates `#[Url] status` (`pending|merged|dismissed`, default `pending`), `#[Url] band` (`''|high|low`); classification `#[Url] status` (existing), `#[Url(as: 'class')] classCode`.

- [ ] **Step 1: Write the failing tests** (names exact)

```php
// AdminTablePatternTest.php
dataset('table pages', [
    'duplicates' => ['pages::admin.duplicates.index'],
    'classification' => ['pages::admin.classifications.index'],
    // later tasks add: media, content, staff picks, providers, models, users, reports
]);

test('every migrated admin page has stat cards, one chart card, a toolbar and data-labelled cells', function (string $component) {
    seedAdminPage($component);   // helper in this file: creates 12 rows of the page's data
    $html = Livewire::test($component)->html();
    expect(htmlQuery($html, '//*[@data-test="stat-card"]')->length)->toBeGreaterThanOrEqual(2)
        ->and(htmlQuery($html, '//*[@data-test="admin-chart"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//td[not(@data-label) and not(@colspan)]')->length)->toBe(0);
})->with('table pages');

test('admin page sources use only the four button variants and no native confirm', function () {
    foreach (glob(resource_path('views/pages/admin/**/⚡*.blade.php')) as $file) { /* GLOB_BRACE for nested */ }
    // for each file in the migrated list: not toContain('<flux:button'), not toContain('wire:confirm'), not toContain('variant="light"')
});
```
Duplicates (add to `AdminDuplicatesTest.php`): pending default shows 10 of 12 with "Showing 1–10 of 12 pairs"; `status=merged` lists only merged; `band=high` lists score >= 0.95; search by either record's title; stat values: "Pairs waiting" 12 with subtext "oldest waiting 3 weeks" when the oldest `created_at` is 3 weeks old, "Merged" counts `status = merged`, subtext "2 in the last 30 days" from `updated_at`; the chart's two datasets labelled `Found` (from `created_at`) and `Resolved` (from `updated_at` where status is not pending); each "Keep first" is inside its own `x-confirm-dialog` named `merge-first-{id}` whose `wire:click` is `merge({id}, {media_id})`.
Classification: stats "Pending" (count, oldest age), "Accepted" and "Rejected" ("in the last 30 days" from `reviewed_at`); chart datasets `Accepted` and `Rejected` by `reviewed_at`; `class` filter (`classCode=813`) keeps only that class; search matches the story title; default sort confidence ascending; Remove sits in a dialog `remove-class-{id}`; the "More" menu item opens it with `$flux.modal('remove-class-{id}').show()`.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest tests/Feature/Admin/AdminTablePatternTest.php tests/Feature/Admin/AdminDuplicatesTest.php tests/Feature/Admin/ClassificationReviewTest.php`
Expected: FAIL (no stat cards, no chart, rows per page 25, no `status`/`band` filters).

- [ ] **Step 3: Implement.** Both pages `use AdminTable` (remove their own `WithPagination`, `updatedStatus`). Layout top to bottom: `x-admin-header`; a two-column grid at `lg` (stat cards stacked or 2x2 on the left, `x-admin.chart-card` on the right; below `lg` stats then chart); `x-admin-toolbar search="search"` with `x-chip` link tabs for `status` (same `href` plus `wire:click.prevent="$set(...)"` pattern the Reading files page uses today) and a `<flux:select>` for band (duplicates) or class code (classification, from the existing `classes` computed); `x-admin-table :paginator`. Stat and chart queries are computed properties built from the models' own columns named in P5-R8 (`DailySeries::count(DuplicateCandidate::query(), 'created_at')` and `...->where('status', '!=', 'pending'), 'updated_at'`; classification `MediaDeweyClass::query()->where('review_status', 'accepted')`, column `reviewed_at`, likewise rejected). The oldest-waiting subtext is `Carbon::parse(min created_at)->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE)`; none waiting reads "nothing waiting". Duplicates search: `whereHas('media', title ilike) or whereHas('duplicateMedia', title ilike)`; sort whitelist `score`, `created_at`, default `-score`; classification whitelist `confidence`, `created_at`, default `confidence`. Buttons: Keep first / Keep second / Accept / Reject / Not a duplicate = `x-cta variant="secondary"` (merge ones behind `x-confirm-dialog danger=false`, names `merge-first-{id}`, `merge-second-{id}`, also the flyout's "Keep this one"); Compare, Change class, Add class, More trigger = `outline`; Remove = `danger` in `x-confirm-dialog`. Every `<td>` gets `data-label`; long titles use `admin-truncate` with a `title` attribute. Keep all existing Livewire method names so current tests still call them.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/pest tests/Feature/Admin/AdminTablePatternTest.php tests/Feature/Admin/AdminDuplicatesTest.php tests/Feature/Admin/ClassificationReviewTest.php tests/Feature/Admin/AdminOnlyAccessTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**: `git commit -m "feat: duplicates and classification review on the shared admin table pattern"`

### Task 5.4: Catalog group: Media, Reading files, Staff picks

**Files:**
- Modify: `app/Models/ContentSource.php` (scope `awaitingVerification`), `resources/views/pages/admin/media/⚡index.blade.php`, `resources/views/pages/admin/media/⚡edit.blade.php` (variants and dialog only), `resources/views/pages/admin/content/⚡index.blade.php`, `resources/views/pages/admin/staff-picks/⚡index.blade.php`
- Test: `tests/Feature/Admin/AdminTablePatternTest.php` (add the three pages), `tests/Feature/Admin/AdminMediaEditTest.php`, `tests/Feature/Admin/StaffPicksTest.php`, a new `tests/Feature/Admin/AdminContentTest.php` if no file covers Reading files (check `grep -ln "admin.content" tests/Feature`), `tests/Browser/StaffPickEditorTest.php`

**Interfaces:** Produces `ContentSource::scopeAwaitingVerification(Builder $query): void` = `access_type != user_owned` and not (`verified_at` and `verified_by` both set). Page properties: media `#[Url] trashed` (kept) plus `#[Url] type` (media type slug dropdown); content `#[Url(as: 'show')] show` (existing tabs) plus `#[Url] format`; staff picks `#[Url] show` (existing) and the search that today only lives in the editor stays separate (`$search` in the editor is the story picker, so the page's list search uses a new `#[Url(as: 'q')] public string $query`; the trait is not used with its `$search` on this page, see Step 3).

- [ ] **Step 1: Write the failing tests**
  - Media: stats "Stories" (`Media::count()`, subtext "N added in the last 30 days" from `created_at`), "Removed or merged" (`Media::onlyTrashed()->count()`); chart `Stories added` from `created_at`; search by title and `type` filter together with page 2; `sort=-publication_year` orders; whitelist `title`, `publication_year`, `created_at`; 10 per page; "Edit" is `outline`.
  - Reading files: stats "Awaiting verification" (`ContentSource::awaitingVerification()->count()`, oldest age from `created_at`), "Verified" (`whereNotNull verified_at and verified_by`, non-user-owned), "Failed" (`status = failed`); chart `Verified` from `verified_at` per day; tabs All/Unverified/Verified/Failed keep their meaning; `format` dropdown (`epub|pdf|cbz|cbr|images|url`); a reader's own upload (`user_owned`) is never listed or counted (assert with a `ContentSource::factory()->userOwned($user)` row).
  - Staff picks: stats "Staff picks" total, "Shown to readers", "Off" (the existing `counts`); chart `Stories per pick`, top 10 by `items_count` (labels are pick names); tabs All/Shown/Off; "New staff pick" is `primary`; per-row switch unchanged; Delete in the editor is an `x-confirm-dialog` named `delete-staff-pick`.
  - Browser (`StaffPickEditorTest.php`): open the editor on pick B while pick A exists; click Delete, assert the dialog text names pick B and the row count is still 2; click Cancel, count still 2; click Delete then the dialog's Confirm (`[data-test=confirm-delete-staff-pick]`), assert only pick B is gone and A remains.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest tests/Feature/Admin/AdminTablePatternTest.php tests/Feature/Admin/StaffPicksTest.php tests/Feature/Admin/AdminMediaEditTest.php`
Expected: FAIL (no stat cards or chart on the three pages; `awaitingVerification` undefined).

- [ ] **Step 3: Implement.** Media and Reading files `use AdminTable` as in Task 5.3; Reading files keeps `listed()` and replaces its private `verified()` plus the unverified filter with the new scope (one definition for page and, in Task 5.7, the work queue). Staff picks: it already has `$search` for the story picker inside the editor, so it does not use the trait's `$search`: add `use WithPagination` plus its own `#[Url(as: 'q')] $query`, `#[Url] $show`, 10 per page, a sort whitelist `updated_at`, `name` (the trait cannot be used here without renaming the editor property; recorded in the ledger as a deliberate exception, covered by the same pattern test). Staff picks chart: `$this->picks()->withCount('items')->orderByDesc('items_count')->limit(10)`. Media edit: "Remove this record" becomes `x-cta variant="danger"` inside `x-confirm-dialog name="remove-media"`, "Clear AI summary" the same with `danger=false`; other buttons use `secondary`/`outline`. If the confirm dialog cannot open inside the staff-pick flyout (nested dialogs), render it as a sibling of the flyout and open it with `$flux.modal('delete-staff-pick').show()`.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/pest tests/Feature/Admin && vendor/bin/pest tests/Browser/StaffPickEditorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**: `git commit -m "feat: media, reading files and staff picks on the shared admin table pattern"`

### Task 5.5: Operations group: Sync and Models

**Files:**
- Modify: `resources/views/pages/admin/providers/⚡index.blade.php`, `resources/views/pages/admin/models/⚡index.blade.php`, `resources/views/pages/admin/models/⚡compare.blade.php` (buttons to `x-cta outline`, nothing else)
- Test: `tests/Feature/Admin/AdminTablePatternTest.php` (add both), `tests/Feature/Admin/AdminPagesUiTest.php` (sync cases), `tests/Feature/Admin/ModelsPageTest.php`

**Interfaces:** Sync primary table = recent runs: `#[Url] status` (`''|succeeded|partial|failed|running`, tabs), `#[Url] source` (provider key dropdown), search over the source name and `error`; whitelist `started_at`, `status`, default `-started_at`. Models primary table = versions: `#[Url] status` (`''|active|candidate|retired|training`), search over `name`, whitelist `name`, `trained_at`, `created_at`, default `-created_at`. Public methods `toggleEnabled`, `syncNow`, `runNow` keep their names.

- [ ] **Step 1: Write the failing tests**
  - Sync stats: "Sources enabled" (`ExternalSource::providers()->where('enabled', true)->count()`, subtext "of N providers" = `providers()->count()`), "Records" (sum of `MediaExternalId` rows, from the existing `records` computed), "Last sync" (status of the newest `IngestionRun`, `tone=error` when `failed`, subtext its `started_at` diff), "Failed runs" (`status = failed`, `started_at` within 7 days). Chart "Sync runs by day": three datasets `Succeeded`, `Partial`, `Failed` stacked, from `started_at` and `status`. Runs list: 12 runs shows 10 with "Showing 1–10 of 12 runs"; `status=failed` plus `source=openlibrary` plus page 2 together; the sources list stays unpaginated and every row has Enable/Disable and Sync now as `secondary`; toggling still works.
  - Models stats: "Active versions" (`ModelVersion` status active), "Candidates", "Last trained" (`max(trained_at)` diff, "never" when null), "Failed jobs" (`MlJobRun` failed, `started_at` within 7 days). Chart "Job runs by day": `Succeeded` and `Failed` stacked from `MlJobRun.started_at` and `status`. Versions: search by name, status tab, 10 per page; recent job runs shows only the latest 10 with no pager; the 9 "Run now" buttons are `secondary`, the disabled running one stays disabled; the existing `data-test="ml-health"` badge stays. `Http::fake(['*/health' => ...])` as in `AdminPagesUiTest`.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest tests/Feature/Admin/AdminTablePatternTest.php tests/Feature/Admin/AdminPagesUiTest.php tests/Feature/Admin/ModelsPageTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement** as in Task 5.3. Sync's `running`/`records`/`sources` computed properties stay; replace `->limit(20)` on `runs` with the trait pagination over `IngestionRun::with('externalSource')`. Models keeps `versions` limit 30 replaced by trait pagination; `runs` becomes `limit(10)`. Failed-ids `<details>` on Sync stays inside the truncated Detail cell (it must not widen the row; Task 5.9 checks 375px).

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/pest tests/Feature/Admin tests/Feature/Ml`
Expected: PASS.

- [ ] **Step 5: Commit**: `git commit -m "feat: sync and models on the shared admin table pattern"`

### Task 5.6: People group: Users and Reports

**Files:**
- Modify: `resources/views/pages/admin/users/⚡index.blade.php`, `resources/views/pages/admin/moderation/⚡index.blade.php`
- Test: `tests/Feature/Admin/AdminTablePatternTest.php` (add both; also turn the static variant test into a glob over every file in `resources/views/pages/admin/**`), `tests/Feature/Admin/AdminUsersTest.php` (the 25 expectations at lines 51-57 and 84 become 10), `tests/Feature/Admin/AdminReportsTest.php`

**Interfaces:** Users: `#[Url] status` (`''|active|deactivated`, tabs), `#[Url] role` (`''|reader|admin`, dropdown), whitelist `name`, `created_at`, default `-created_at`, select list unchanged (no credentials). Reports: `#[Url] status` (existing), `#[Url] type`, `#[Url] reason` (dropdowns), search over `reports.details` (the first reader note), the grouped `cards()` query unchanged apart from the filters; it uses the trait with `ordered($query, 'reportable_id')`.

- [ ] **Step 1: Write the failing tests**
  - Users stats: "Accounts" (`User::count()`, subtext "N in the last 30 days" from `created_at`), "Readers" (`is_admin = false`), "Admins", "Deactivated" (`deactivated_at` not null); chart "Sign-ups" from `users.created_at`; 25 users show 10 with "Showing 1–10 of 25 accounts"; search plus `role=reader` plus `status=active` plus page 2 together; `q=%` shows the "No users found" empty row; a 60-character email still renders inside an `admin-truncate` cell with a `title` of the full address. Existing assertions (Alice, Dan, Una rows) keep passing.
  - Reports stats: "Open" (distinct reported things with an open report, the `openCount` query), "Oldest open" (`min(created_at)` of open reports), "Hidden or kept in the last 30 days" is split into "Actioned" and "Dismissed" counted by `handled_at` in the last 30 days and `status`; chart "Reports" two datasets `Opened` (`created_at`) and `Handled` (`handled_at`); `type` and `reason` dropdowns plus status tabs plus page 2 together; each card's Hide is an `x-cta variant="danger"` inside `x-confirm-dialog name="hide-report-{open_id}"` with `action="hide({open_id})"`, Dismiss and Restore are `secondary`; Livewire test: calling `hide` for report A leaves report B open (the dialog ids are per row).

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest tests/Feature/Admin/AdminTablePatternTest.php tests/Feature/Admin/AdminUsersTest.php tests/Feature/Admin/AdminReportsTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement** as in Task 5.3 (Reports keeps its card markup inside `x-admin-table` rows; each card is one stacked unit on phones, and its action buttons wrap at 375px). Remove Reports' own `updated()` (the trait handles it) and its `openCount` duplicate where the stat card now uses it.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/pest tests/Feature/Admin tests/Feature/Social`
Expected: PASS, including the static "four variants, no native confirm" test over every admin page.

- [ ] **Step 5: Commit**: `git commit -m "feat: users and reports on the shared admin table pattern"`

### Task 5.7: Overview: stat cards, four charts and the work queue

**Files:**
- Create: `app/Admin/WorkQueue.php`, `app/Admin/WorkQueueItem.php`, `app/Admin/Contracts/WorkQueueSource.php`, `app/Admin/WorkQueue/DuplicateWorkQueueSource.php`, `ClassificationWorkQueueSource.php`, `ReportWorkQueueSource.php`, `IngestionWorkQueueSource.php`, `ReadingFilesWorkQueueSource.php`, `app/Admin/Metrics/OverviewMetrics.php`, `tests/Feature/Admin/AdminWorkQueueTest.php`
- Modify: `resources/views/pages/admin/⚡index.blade.php`, `config/bookflix.php` (key `admin_work_queue_sources`), `tests/Feature/Admin/AdminPagesUiTest.php` (overview cases), `tests/Feature/Admin/AdminAccessTest.php` (query-budget and "stat pills" cases)

**Interfaces:** Produces `WorkQueueItem` (`final readonly class`, constructor property promotion: `string $area` in `catalog|review|operations|people`, `string $severity` in `error|warning|info`, `string $label`, `int $count`, `?CarbonInterface $oldestAt`, `string $status`, `string $ctaLabel`, `string $ctaUrl`); `WorkQueueSource::items(): iterable`; `WorkQueue::items(): Collection` resolves each configured class with `app()`, wraps each source in try/catch (`report($e)`, skip), then sorts: severity rank error, warning, info; within a rank `count > 0` before `count = 0`, then `oldestAt` ascending (null last), then `label`. `filtered(?string $area, ?string $severity)` filters that list. `OverviewMetrics::get(): array{stats: array, charts: array}` cached 60 s under `admin.overview-metrics`.

Sources (all read `AdminCounts::get()` where it already holds the number; area, severity rule, CTA): duplicates (`review`, warning when `count > 0` else info "All clear", "Review duplicates" to `admin.duplicates`, `oldestAt` from `oldestDuplicate`); classification (`review`, same rule, `admin.classifications`); reports (`people`, warning, count from `AdminCounts reports`, oldest = `min(created_at)` of open reports, `admin.moderation`); ingestion (`operations`, **error** with count = enabled providers whose newest `IngestionRun` is `failed`, status "Failing", `oldestAt` = the oldest of those `started_at`, CTA "Open sync" to `admin.providers`; otherwise one info row "Healthy, last sync :when" or "Never synced"); reading files (`catalog`, warning, count `ContentSource::awaitingVerification()->where('status', '!=', 'failed')->count()`, oldest `created_at`, `admin.content`).

- [ ] **Step 1: Write the failing tests** (`AdminWorkQueueTest.php`; each test `Cache::forget('admin.dashboard-counts')` and `Cache::forget('admin.overview-metrics')` first)

```php
test('the queue lists actionable items first: errors, then warnings oldest first, then all-clear rows', function () {
    $source = ExternalSource::factory()->create(['key' => 'openlibrary', 'enabled' => true]);
    IngestionRun::factory()->create(['external_source_id' => $source->id, 'status' => IngestionStatus::Failed, 'started_at' => now()->subDay()]);
    DuplicateCandidate::factory()->count(3)->create(['created_at' => now()->subDays(3)]);
    classified(Media::factory()->count(2)->create(), '813', status: 'pending');   // oldest = now
    // no reports, no reading files awaiting

    $labels = app(WorkQueue::class)->items()->pluck('label')->all();

    expect($labels)->toBe(['Sync', 'Duplicates', 'Classification', 'Reports', 'Reading files'])   // error; warning (3 days); warning (now); info; info (label order)
        ->and(app(WorkQueue::class)->items()->first()->severity)->toBe('error');
});

test('the queue filters by area and by severity', function () {
    /* same data */
    expect(app(WorkQueue::class)->filtered('review', null)->pluck('label')->all())->toBe(['Duplicates', 'Classification'])
        ->and(app(WorkQueue::class)->filtered(null, 'error')->pluck('label')->all())->toBe(['Sync']);
});

test('a source that throws is skipped and the overview still renders', function () {
    app()->bind('throwing-source', fn () => new class implements WorkQueueSource { public function items(): iterable { throw new RuntimeException('harvest db down'); } });
    config(['bookflix.admin_work_queue_sources' => ['throwing-source', DuplicateWorkQueueSource::class]]);

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.index'))->assertOk()->assertSee('Duplicates');
});

test('the overview shows five stat cards from real queries, four chart cards and a one-click CTA per queue row', function () {
    /* seed: 2 media (1 old), 1 reader + 1 admin, 1 enabled source, a failed run */
    $html = $this->actingAs(User::factory()->admin()->create())->get(route('admin.index'))->assertOk()->getContent();
    expect(htmlQuery($html, '//*[@data-test="stat-card"]')->length)->toBe(5)
        ->and(htmlQuery($html, '//*[@data-test="admin-chart"]')->length)->toBe(4)
        ->and(htmlQuery($html, '//*[@data-test="work-queue-row"]//a[@href]')->length)->toBeGreaterThanOrEqual(5);
});

test('the overview filters are in the query string and keep the page usable', function () {
    Livewire::withQueryParams(['area' => 'review', 'severity' => 'warning'])->test('pages::admin.index')->assertSee('Duplicates')->assertDontSee('Reading files');
});
```
Stat cards (exact queries): "Stories" `Media::count()`, subtext "N added in the last 30 days" (`created_at`); "Sources enabled" `ExternalSource::providers()->where('enabled', true)->count()`, subtext "of N providers"; "Awaiting review" `AdminCounts duplicates + classifications`, subtext "D duplicate pairs, C classes"; "Readers" `User::where('is_admin', false)->count()`, subtext "N in the last 30 days"; "Last sync" newest `IngestionRun` status with `tone=error` when failed and its `started_at` diff. Charts: "Catalog growth" `DailySeries::count(Media::query(), 'created_at')` (line); "Review backlog" `DailySeries::backlog(duplicates + classifications pending, opened, resolved)` with opened = duplicates `created_at` plus classification rows that are pending or have `reviewed_at`, resolved = duplicates `updated_at` where status is not pending plus classification `reviewed_at` (line); "Sync outcomes by day" stacked bar from `IngestionRun.started_at` by `status`; "Sign-ups" `User` non-admin `created_at` (bar). Update the two old overview tests (strings "Needs attention", "At a glance", "Open media", pills "1,305 to review") and set the cold-load query budget in `AdminAccessTest` to the measured count plus 3, recorded in the ledger; the warm load must be lower (metrics are cached).

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest tests/Feature/Admin/AdminWorkQueueTest.php`
Expected: FAIL, classes `App\Admin\WorkQueue` not found.

- [ ] **Step 3: Implement.** Config: `'admin_work_queue_sources' => [DuplicateWorkQueueSource::class, ClassificationWorkQueueSource::class, ReportWorkQueueSource::class, IngestionWorkQueueSource::class, ReadingFilesWorkQueueSource::class]` with a comment that later phases append their source. The page keeps `#[Url] area` and `#[Url] severity` (tabs for area, dropdown for severity; invalid values fall back to all via `choice`-style `in_array`). Layout: header; stat grid (5 cards, `data-test="admin-stats"`); chart grid 2 per row from `lg`, 1 on phones; queue as `x-admin-table label="Work queue"` with `data-test="work-queue-row"` rows (area, label with status badge: error red, warning amber, info zinc, count, oldest age or a dash, CTA `x-cta secondary` linking `ctaUrl`). Remove the old "Needs attention" and "At a glance" blocks.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/pest tests/Feature/Admin tests/Feature/Security/AdminRouteGuardTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**: `git commit -m "feat: admin overview with stat cards, four charts and a work queue registry"`

### Task 5.8: Five-destination sidebar, group tabs and the admin settings page

**Files:**
- Create: `resources/views/pages/admin/⚡settings.blade.php`, `app/Http/Middleware/SendAdminsToAdminSettings.php`, `tests/Feature/Admin/AdminSettingsTest.php`
- Modify: `config/bookflix.php` (`admin_navigation`), `resources/views/layouts/admin.blade.php`, `resources/views/layouts/admin-item.blade.php`, `routes/admin.php` (route `admin.settings` inside the existing group), `routes/settings.php` (attach the middleware to `settings.edit` and `profile.edit`), `app/Models/User.php` (`updating` guard), `tests/Feature/Admin/AdminShellTest.php`, `tests/Feature/Admin/AdminOnlyAccessTest.php` (add `admin.settings`), `tests/Browser/AdminShellTest.php` (audit paths)

**Interfaces:** Consumes Phase 4's `x-settings.section`, `x-settings.summary-card`, its password-confirmation dialog and `settings.edit`; the existing `pages::settings.two-factor-setup-modal` and `pages::settings.two-factor.recovery-codes` Livewire components. Produces route `admin.settings`, the five-entry `admin_navigation`, and the model guard.

- [ ] **Step 1: Write the failing tests**

```php
// AdminShellTest.php (replaces the ten-link expectations)
test('the sidebar has five destinations and every page in a group shows its link tabs', function (string $name, Closure $parameters, string $group, array $tabs, string $currentTab) {
    $html = $this->actingAs(User::factory()->admin()->create())->get(route($name, $parameters()))->assertOk()->getContent();
    expect(array_column(adminNav($html), 'label'))->toBe(['Overview', 'Catalog', 'Review', 'Operations', 'People'])
        ->and(array_column(adminNav($html), 'href'))->toBe([route('admin.index'), route('admin.media.index'), route('admin.duplicates'), route('admin.providers'), route('admin.users')])
        ->and(collect(adminNav($html))->where('current', true)->pluck('label')->all())->toBe([$group]);
    // tabs: <nav aria-label="Section pages">: labels in order, hrefs are the EXISTING route urls (D2-9), one aria-current
})->with([
    ['admin.media.index', fn () => [], 'Catalog', ['Media', 'Reading files', 'Staff picks'], 'Media'],
    ['admin.content', fn () => [], 'Catalog', ['Media', 'Reading files', 'Staff picks'], 'Reading files'],
    ['admin.media.edit', fn () => ['media' => Media::factory()->create()], 'Catalog', ['Media', 'Reading files', 'Staff picks'], 'Media'],
    ['admin.classifications', fn () => [], 'Review', ['Duplicates', 'Classification'], 'Classification'],
    ['admin.models.compare', fn () => [], 'Operations', ['Sync', 'Models'], 'Models'],
    ['admin.moderation', fn () => [], 'People', ['Users', 'Reports'], 'Reports'],
]);

test('Review shows the sum of waiting duplicates and classes, each tab its own count, People the open reports', ...);   // 3 duplicates + 2 classes: sidebar "5", tabs "3" and "2"
test('the account block is at the top of the sidebar: large avatar, name, Settings and Sign out, and nothing at the bottom', function () {
    $html = /* admin Ada Admin, get admin.index */;
    expect(substr_count($html, 'data-test="admin-account"'))->toBe(1)
        ->and(strpos($html, 'data-test="admin-account"'))->toBeLessThan(strpos($html, 'aria-label="Admin sections"'))
        ->and($html)->toContain('Ada Admin')->toContain('href="'.route('admin.settings').'"')->toContain('action="'.route('logout').'"');
});
```
`AdminSettingsTest.php`:
```php
test('only an admin opens settings', ...);   // guest -> login, reader 403, admin 200
test('the email is shown as text with no input, and the name edits in a dialog', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'email' => 'ada@example.test']);
    $this->actingAs($admin);
    Livewire::test('pages::admin.settings')->assertSee('ada@example.test')->assertDontSeeHtml('wire:model="email"')->assertSeeHtml('name="edit-name"')
        ->set('name', 'Ada Lovelace')->call('updateName')->assertHasNoErrors();
    expect($admin->fresh()->name)->toBe('Ada Lovelace');
});
test('a blank or 256-character name is refused', ...);
test('a forged email change from an admin is refused on the settings page', function () {
    $admin = User::factory()->admin()->create(['email' => 'ada@example.test']);
    $this->actingAs($admin);
    try { Livewire::test('pages::admin.settings')->set('email', 'evil@example.test')->call('updateName'); } catch (Throwable) {}
    expect($admin->fresh()->email)->toBe('ada@example.test');
});
test('a forged email change from an admin is refused through the profile save of the settings page', function () {
    $admin = User::factory()->admin()->create(['email' => 'ada@example.test']);
    $this->actingAs($admin);
    Livewire::test('pages::settings.index')->set('email', 'evil@example.test')->call('updateProfileInformation')->assertHasErrors('email');   // Phase 4's page and method name; re-check in the baseline
    expect($admin->fresh()->email)->toBe('ada@example.test');
});
test('Fortify has no profile-information route, so that path is closed too', function () {
    $this->actingAs($admin = User::factory()->admin()->create(['email' => 'ada@example.test']))
        ->putJson('/user/profile-information', ['name' => 'X', 'email' => 'evil@example.test'])->assertStatus(404);   // accept 405 if the framework answers it
    expect($admin->fresh()->email)->toBe('ada@example.test');
});
test('the model refuses any save that changes the signed-in admin email, but a reader and the console can', function () {
    $admin = User::factory()->admin()->create(); $this->actingAs($admin);
    expect(fn () => $admin->forceFill(['email' => 'new@example.test'])->save())->toThrow(ValidationException::class);
    $reader = User::factory()->create(); $this->actingAs($reader); $reader->update(['email' => 'reader-new@example.test']);
    auth()->logout(); User::factory()->admin()->create()->update(['email' => 'console@example.test']);   // no signed-in admin: allowed
    expect($reader->fresh()->email)->toBe('reader-new@example.test');
});
test('an admin is sent from the reader settings to the admin settings; a reader is not', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('settings.edit'))->assertRedirect(route('admin.settings'));
    $this->actingAs(User::factory()->create())->get(route('settings.edit'))->assertOk();
});
test('changing the password needs the current one', ...);   // wrong current_password -> error; right one updates the hash
test('two-factor setup is offered with the existing setup modal', ...);   // assertSeeLivewire('pages::settings.two-factor-setup-modal')
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest tests/Feature/Admin/AdminShellTest.php tests/Feature/Admin/AdminSettingsTest.php`
Expected: FAIL (ten links, no tabs, no `admin.settings` route).

- [ ] **Step 3: Implement.**
  - `admin_navigation` (five entries; `active` lists every route of the group, `count` lists `AdminCounts` keys to sum): Overview (`admin.index`); Catalog icon `book-open`, route `admin.media.index`, tabs Media (`admin.media.*`), Reading files (`admin.content`), Staff picks (`admin.staff-picks`); Review icon `rectangle-group`, route `admin.duplicates`, `count: [duplicates, classifications]`, tabs Duplicates (count `duplicates`), Classification (count `classifications`); Operations icon `arrow-path`, route `admin.providers`, tabs Sync (`admin.providers`), Models (`admin.models`, `admin.models.*`); People icon `users`, route `admin.users`, `count: [reports]`, tabs Users, Reports (count `reports`).
  - `layouts/admin`: avatar block (`flux:avatar size="xl"`, name, `Settings` link to `admin.settings`, `Sign out` form, `data-test="admin-account"`) first in the sidebar, then the nav loop over the five entries (`layouts/admin-item` takes `$item`, sums its `count` keys); remove the bottom block; the phone header avatar links to `admin.settings`. Keep Phase 2's drawer edits. Above `{{ $slot }}`, render `<x-admin.tabs>` from the current entry's `tabs` when it has any (so no page repeats it).
  - `User::booted()`: `static::updating(fn (User $user) => ...)` throws `ValidationException::withMessages(['email' => __('The email address of an admin account cannot be changed here.')])` when `$user->is_admin`, `$user->isDirty('email')`, and `auth()->id() === $user->id`.
  - `SendAdminsToAdminSettings`: `if ($request->user()?->can('access-admin')) { return redirect()->route('admin.settings'); }`; attach to `settings.edit` and `profile.edit`.
  - `⚡settings.blade.php`: `Gate::authorize('access-admin')` in `boot()`, `#[Title('Settings')]`; header block, `x-settings.section` for Profile (name row with an Edit `outline` button opening `<flux:modal name="edit-name">` with a form that calls `updateName`; email row as plain text with the line "Admin emails are set by the system owner and cannot be changed here"), Security (password dialog with the `PasswordValidationRules` trait; the existing two-factor components), and `x-settings.summary-card` on the right (role, 2FA on or off). `updateName` validates `nameRules()` and saves only `name`. Passkeys are not built (P5-R11).

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/pest tests/Feature/Admin tests/Feature/Settings tests/Feature/Security && vendor/bin/pest tests/Browser/AdminShellTest.php`
Expected: PASS (the drawer test still focuses the first nav link).

- [ ] **Step 5: Commit**: `git commit -m "feat: five-link admin sidebar with group tabs, and an admin settings page whose email is read-only"`

### Task 5.9: Layout holds at 375, 768, 1024 and 1280

**Files:**
- Create: `tests/Browser/AdminLayoutTest.php`
- Modify: `resources/css/app.css` and the page views only where a check fails

**Interfaces:** Consumes everything above; follow the viewport and `assertScript` style of `tests/Browser/AdminShellTest.php`.

- [ ] **Step 1: Write the failing test**

```php
dataset('admin viewports', [[375, 812], [768, 1024], [1024, 768], [1280, 800]]);
dataset('admin paths', ['/admin', '/admin/media', '/admin/content', '/admin/staff-picks', '/admin/duplicates', '/admin/classifications', '/admin/providers', '/admin/models', '/admin/users', '/admin/reports', '/admin/settings']);

beforeEach(function () {
    Http::fake(['*/health' => Http::response(['status' => 'ok'])]);
    $this->actingAs(User::factory()->admin()->create(['name' => str_repeat('N', 60)]));
    User::factory()->create(['email' => str_repeat('a', 60).'@example.test', 'name' => str_repeat('Longname', 8)]);
    Media::factory()->create(['title' => str_repeat('Supercalifragilistic', 4)]);   // 80 unbroken characters
    // plus 12 rows for each list (duplicates, classification, reports, runs, versions) so pagination shows
});

test('no admin page scrolls sideways', function (string $path, int $width, int $height) {
    visit($path, ['viewport' => ['width' => $width, 'height' => $height]])->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);
})->with('admin paths', 'admin viewports');

test('the chart sits beside the stat cards from 1024 and below them on a phone, and the tables stack under 768', function () {
    $page = visit('/admin/users', ['viewport' => ['width' => 1280, 'height' => 800]])->wait(2)
        ->assertScript('document.querySelector("[data-test=admin-chart] canvas").width > 0', true)
        ->assertScript('document.querySelector("[data-test=admin-chart]").getBoundingClientRect().left >= document.querySelector("[data-test=admin-stats]").getBoundingClientRect().right - 1', true)
        ->assertScript('getComputedStyle(document.querySelector(".admin-table thead")).display !== "none"', true);

    visit('/admin/users', ['viewport' => ['width' => 375, 'height' => 812]])->wait(2)
        ->assertScript('document.querySelector("[data-test=admin-chart]").getBoundingClientRect().top >= document.querySelector("[data-test=admin-stats]").getBoundingClientRect().bottom - 1', true)
        ->assertScript('getComputedStyle(document.querySelector(".admin-table thead")).display', 'none')
        ->assertScript('[...document.querySelectorAll(".admin-table td")].every((td) => td.hasAttribute("data-label") || td.hasAttribute("colspan"))', true);
});

test('long text is truncated with its full text in a tooltip', function () {
    visit('/admin/media', ['viewport' => ['width' => 1280, 'height' => 800]])->wait(1)
        ->assertScript('document.querySelector(".admin-truncate[title^=Supercali]").scrollWidth >= document.querySelector(".admin-truncate[title^=Supercali]").clientWidth', true);
});

test('the admin pages pass the accessibility audit on a phone and on a laptop', function (string $path, int $width, int $height) {
    visit($path, ['viewport' => ['width' => $width, 'height' => $height]])->wait(1)->assertNoAccessibilityIssues();
})->with('admin paths', [[375, 812], [1280, 800]]);
```
Also extend `tests/Browser/AdminShellTest.php` audit list with the new paths if Task 5.8 did not.

- [ ] **Step 2: Run to verify it fails (or passes)**

Run: `vendor/bin/pest tests/Browser/AdminLayoutTest.php`
Expected: FAIL on the first real overflow or label gap (likely Reports cards, Sync failed-ids, the 60-character name in the sidebar). If everything passes, record that in the ledger; the test stays.

- [ ] **Step 3: Implement the fixes** each failure names: `min-w-0` on flex children, `overflow-wrap: anywhere` in `.admin-truncate` under 768px, `truncate` on the sidebar name, `flex-wrap` on button rows, `max-w-full` on charts' wrapper. Never hide overflow on `body` to pass.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/pest tests/Browser/AdminLayoutTest.php tests/Browser/AdminShellTest.php tests/Browser/StaffPickEditorTest.php tests/Browser/CspTest.php`
Expected: PASS at every viewport.

- [ ] **Step 5: Commit**: `git commit -m "test: admin pages hold at 375, 768, 1024 and 1280 with no sideways scroll"`

## Phase exit

- [ ] `env -u AI_AGENT -u CLAUDECODE composer test` passes (Pint, PHPStan level 7, Unit and Feature).
- [ ] Browser files run one by one and green: `tests/Browser/AdminLayoutTest.php`, `AdminShellTest.php`, `StaffPickEditorTest.php`, `CspTest.php`, `Components/CtaTest.php`, and `NoHorizontalScrollTest.php` if Phase 2 created it.
- [ ] Screenshot self-review at 375 and 1280 for the Overview, one table page and `admin.settings` against `DESIGN.md` and `PRODUCT.md`; premium bar: no card grids of identical empty boxes, empty states say why.
- [ ] `/code-review` (or whole-branch review on the most capable model) against A4, A5, A6 and this plan; one fix wave.
- [ ] Ledger holds: baseline results, the per-task commit ranges, query budget numbers, the Needs decision items (admin passkeys, active readers).
- [ ] PR (stacked per D2-24 on Phase 4's tip until it merges, then rebased on `origin/main`); body carries the Part I report table with a row for every brief item:

| Item | Expected status | Files |
|---|---|---|
| A4 shared table pattern (components, trait, four buttons, dialogs) | Fixed | `x-admin.*`, `x-admin-table`, `x-admin-toolbar`, `x-confirm-dialog`, `x-cta`, `AdminTable`, `DailySeries`, `app.css` |
| A4 Duplicates, Classification | Fixed | `pages/admin/duplicates`, `pages/admin/classifications` |
| A4 Media, Reading files, Staff picks | Fixed | `pages/admin/media`, `content`, `staff-picks`, `ContentSource` |
| A4 Sync, Models, Users, Reports | Fixed | `pages/admin/providers`, `models`, `users`, `moderation` |
| A4 responsive at 375/768/1024/1280 | Fixed | `tests/Browser/AdminLayoutTest.php` |
| A5 stat cards, four charts, work queue | Fixed (active readers: Needs decision) | `pages/admin/⚡index`, `app/Admin/*`, `config/bookflix.php` |
| A6 five-destination sidebar, tabs, avatar block | Fixed | `layouts/admin*`, `config/bookflix.php` |
| A6 old URLs keep working (D2-9, no redirects needed) | Already OK | `routes/admin.php` |
| A6 admin settings, 2FA, name dialog, read-only email | Fixed (passkeys: Needs decision) | `pages/admin/⚡settings`, `User`, `SendAdminsToAdminSettings` |
| A6 forged email rejected server-side | Fixed (Fortify path absent, P5-R4) | `app/Models/User.php`, `AdminSettingsTest.php` |

PR rules: Part G; never merged by the agent; no `Co-Authored-By`, no AI attribution in commits or the PR body.
