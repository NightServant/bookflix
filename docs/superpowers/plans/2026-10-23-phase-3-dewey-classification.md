# Phase 3 — Dewey-inspired Classification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every story gets reviewable Dewey-inspired classifications (rules + provider codes now, ML in Phase 7), browsable from `/explore`, and curated through an admin review queue.

**Architecture:** `app/Classification` (master plan A5/A10): `DeweyHierarchy` over a seeded `dewey_classes` tree with a materialized `path`; `DeweyResolver` (external codes), `DeweyMapper` (rules), `DeweyValidator` (limits), and `ClassificationService` orchestrating them. Ingestion dispatches `ClassifyMedia`. Public surfaces only show accepted rows plus pending rows at/above a confidence threshold.

**Tech Stack:** Laravel 13, Postgres (`varchar_pattern_ops` index), Livewire 4 + Flux free, Pest.

**Spec:** `/Users/gabe/Documents/Bookflix Documentation/Bookflix_Specification.md` §11–§19, §49 (in-repo: `docs/superpowers/specs/bookflix-specification.md`). **Master plan:** A6 classification block, A7 services, A10, Part E Phase 3.

**Depends on:** Phase 1 (`access-admin`, `routes/admin.php`, `config/bookflix.php`), Phase 2 (`Media`, `IngestionPipeline`, `NormalizedMedia.externalDeweyCode`, `ImportMediaRecord`, queue `ingestion`).

## Global Constraints

- The layer is **"Dewey-inspired"**, never "DDC" or "official" in UI copy, code comments shown to users, or docs (§11, R3). Class names are Bookflix's own wording, written in-house into the CSV.
- Classification is **not genre**: never derive `genres` from Dewey or vice-versa (§14).
- Automatic rows start `review_status = pending`; public site shows `accepted` + `pending` with `confidence >= config('bookflix.classification.public_min_confidence')` (0.80). `rejected` never shows (A10).
- ≤ 1 primary (DB partial unique index `WHERE classification_type='primary' AND review_status<>'rejected'`), ≤ 3 secondary, minimum confidence `config('bookflix.classification.min_confidence')` (0.50) (§15, A7).
- Confidence by method: manual 1.0, external 0.95, rules 0.70–0.85 (A10).
- Q1 default: comics/manga/manhwa/manhua/graphic novels get primary `741.5x` by country **plus** a secondary 8xx literature class when the work is literary.
- Livewire pages: `resources/views/pages/<area>/⚡<name>.blade.php`, `Route::livewire(...)`. Flux free only. Admin actions re-authorize with `Gate::authorize('access-admin')`.

## Review Focus

1. A pending low-confidence or rejected class must not leak to public pages/counts. → Task 3.4.
2. Two concurrent attempts to set a primary class: DB rejects the second; service swaps atomically. → Tasks 3.2, 3.6.
3. Unknown/garbage external codes (`999.99`, `""`, `"abc"`, `"813."`) never throw and never produce a class. → Task 3.3.
4. Re-running classification must not overwrite an admin's manual/accepted/rejected decision. → Task 3.3.
5. A media with no genres/subjects/code (sparse record) classifies safely (fallback class or none) without error. → Task 3.3.

## File structure

```text
app/Classification/ClassificationService.php
app/Classification/DeweyCounts.php   app/Classification/ClassShelves.php   app/Classification/ClassShelf.php
app/Classification/Dewey/{DeweyHierarchy,DeweyResolver,DeweyMapper,DeweyValidator}.php
app/Enums/{ClassificationType,ClassificationMethod,ReviewStatus}.php
app/Models/{DeweyClass,MediaDeweyClass}.php   app/Jobs/ClassifyMedia.php
database/data/{dewey_classes.csv,dewey_subject_keywords.csv}   database/seeders/DeweySeeder.php
resources/views/pages/explore/{⚡index,⚡show}.blade.php   resources/views/pages/admin/classifications/⚡index.blade.php
```

---

### Task 3.1: Hierarchy seed and `DeweyHierarchy`

**Files:**
- Create: migration `2026_10_24_000001_create_dewey_classes_table.php` (A6: `code` unique string(16), `name`, `description` null, `parent_id` null FK, `level` smallint, `path` string; `varchar_pattern_ops` index: `create index dewey_classes_path_pattern on dewey_classes (path varchar_pattern_ops)`), `app/Models/DeweyClass.php`, factory, `database/data/dewey_classes.csv` (`code,name,description,parent_code`), `database/seeders/DeweySeeder.php` (idempotent upsert; computes `level` (root = 1) and `path` like `800/810/813`)
- Seed content: the 10 main classes (000…900), all 100 divisions, and sections 813, 823, 833, 843, 853, 863, 891.7, 895.1, 895.6, 895.7, plus comics: `741.5` and country children `741.52` Japanese, `741.53` Korean, `741.54` Chinese, `741.55` American/English, `741.56` French/Belgian, `741.59` other (Bookflix's own section codes under division 740). Names are original wording.
- Create: `app/Classification/Dewey/DeweyHierarchy.php`
- Modify: `config/bookflix.php` (`classification.{public_min_confidence=0.80,min_confidence=0.50,max_secondary=3}`)
- Test: `tests/Feature/Classification/DeweyHierarchyTest.php`

**Interfaces:**
- Produces (A7): `DeweyHierarchy::roots(): Collection<DeweyClass>`, `children(DeweyClass): Collection`, `ancestors(DeweyClass): Collection` (root first, excludes self), `findByCode(string): ?DeweyClass`, `similarity(DeweyClass $a, DeweyClass $b): float` (depth of lowest common ancestor ÷ the larger of the two depths; identical → 1.0; no shared root → 0.0). Additional: `DeweyHierarchy::subtreeIds(DeweyClass): Collection<int>` (self + descendants via `path LIKE '{path}/%' OR id = self`). `DeweyClass::scopeSubtree(Builder, DeweyClass)`.

- [ ] **Step 1: Write the failing tests**

```php
beforeEach(fn () => $this->seed(DeweySeeder::class));

test('there are ten roots and 800 has 810 as a child', function () {
    $h = app(DeweyHierarchy::class);
    expect($h->roots())->toHaveCount(10)
        ->and($h->children($h->findByCode('800'))->pluck('code'))->toContain('810');
});

test('ancestors of 813 are 800 then 810', function () {
    $h = app(DeweyHierarchy::class);
    expect($h->ancestors($h->findByCode('813'))->pluck('code')->all())->toBe(['800', '810']);
});

test('path and level are computed', function () {
    $c = DeweyClass::firstWhere('code', '813');
    expect($c->path)->toBe('800/810/813')->and($c->level)->toBe(3);
});

test('subtree query returns self and descendants only', function () {
    $ids = app(DeweyHierarchy::class)->subtreeIds(DeweyClass::firstWhere('code', '810'));
    $codes = DeweyClass::whereIn('id', $ids)->pluck('code');
    expect($codes)->toContain('810', '813')->not->toContain('800', '823');
});

test('similarity is lowest common ancestor depth over the larger depth', function () {
    $h = app(DeweyHierarchy::class);
    expect($h->similarity($h->findByCode('813'), $h->findByCode('823')))->toEqualWithDelta(1 / 3, 0.0001)
        ->and($h->similarity($h->findByCode('813'), $h->findByCode('813')))->toBe(1.0)
        ->and($h->similarity($h->findByCode('813'), $h->findByCode('510')))->toBe(0.0);
});

test('seeding twice does not duplicate classes', function () {
    $count = DeweyClass::count();
    $this->seed(DeweySeeder::class);
    expect(DeweyClass::count())->toBe($count);
});

test('the comics subtree exists under 740', function () {
    expect(DeweyClass::firstWhere('code', '741.55')->path)->toBe('700/740/741.5/741.55');
});
```
Note `741.5` is a section under division `740`; per this path rule 741.5 is level 3 and its country children level 4, so the test above is the contract (no separate `741` class is seeded — flag if the executor prefers one; if added, update the path in this test and in 3.3).

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Classification/DeweyHierarchyTest.php` → FAIL (class `DeweySeeder` not found).
- [ ] **Step 3: Implement** migration, model, seeder (two passes: insert rows, then resolve parents), CSV content, `DeweyHierarchy`. Cache `roots()` for 1 hour with key `dewey:roots` (flushed by the seeder).
- [ ] **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: dewey-inspired hierarchy seed and DeweyHierarchy"`

---

### Task 3.2: `media_dewey_classes`, `DeweyValidator`, `ClassificationService::assign`

**Files:**
- Create: migration `2026_10_24_000002_create_media_dewey_classes_table.php` (A6 columns; `unique(media_id, dewey_class_id)`; raw partial unique `create unique index media_dewey_primary_unique on media_dewey_classes (media_id) where classification_type = 'primary' and review_status <> 'rejected'`), enums `ClassificationType`(Primary, Secondary), `ClassificationMethod`(Manual, Automatic, External), `ReviewStatus`(Pending, Accepted, Rejected), `app/Models/MediaDeweyClass.php` + factory, `app/Classification/Dewey/DeweyValidator.php`, `app/Classification/ClassificationCandidate.php` (A7 readonly DTO: `code`, `confidence`, `method`, `source`, `?modelVersionId`), `app/Classification/ClassificationService.php` (assign only here)
- Modify: `Media` (`deweyClasses(): HasMany`, `primaryDewey(): HasOne` (non-rejected primary), `scopePublicDewey`), `MediaDeweyClass` scope `public()`.
- Test: `tests/Feature/Classification/ClassificationAssignTest.php`, `tests/Unit/Classification/DeweyValidatorTest.php`

**Interfaces:**
- Consumes: `DeweyClass`, `ClassificationCandidate`.
- Produces: `DeweyValidator::filter(list<ClassificationCandidate>): list<ClassificationCandidate>` — drops confidence `< min_confidence`, sorts by confidence desc (ties: manual > external > automatic), keeps at most 1 primary + `max_secondary` (first element is the primary; consumers treat index 0 as primary), de-duplicates by code. `ClassificationService::assign(Media $media, DeweyClass $class, ClassificationType $type, ClassificationCandidate $candidate): MediaDeweyClass` — `updateOrCreate` on `(media, class)`; `review_status` = `Accepted` for method Manual, otherwise `Pending`; throws `PrimaryClassificationExists` (domain exception, wraps the unique violation) when a second non-rejected primary is assigned to a different class. `MediaDeweyClass::scopePublic(Builder)`: accepted OR (pending AND confidence ≥ `public_min_confidence`).

- [ ] **Step 1: Write the failing tests**

```php
test('the validator keeps one primary and three secondary and drops low confidence', function () {
    $in = [cand('813', .95), cand('500', .90), cand('600', .85), cand('700', .80), cand('900', .75), cand('300', .20)];
    $out = (new DeweyValidator)->filter($in);

    expect($out)->toHaveCount(4)
        ->and($out[0]->code)->toBe('813')
        ->and(collect($out)->pluck('code'))->not->toContain('900', '300');
});

test('duplicate codes are collapsed to the highest confidence', function () {
    $out = (new DeweyValidator)->filter([cand('813', .70), cand('813', .90)]);
    expect($out)->toHaveCount(1)->and($out[0]->confidence)->toBe(.90);
});

test('a second primary is rejected by the service and the database', function () {
    $media = Media::factory()->create();
    $svc = app(ClassificationService::class);
    $svc->assign($media, dewey('813'), ClassificationType::Primary, cand('813', .9));

    expect(fn () => $svc->assign($media, dewey('823'), ClassificationType::Primary, cand('823', .9)))->toThrow(PrimaryClassificationExists::class);
    expect(fn () => MediaDeweyClass::factory()->create(['media_id' => $media->id, 'dewey_class_id' => dewey('833')->id, 'classification_type' => 'primary', 'review_status' => 'pending']))->toThrow(QueryException::class);
});

test('a rejected primary does not block a new primary', function () {
    $media = Media::factory()->create();
    MediaDeweyClass::factory()->for($media)->create(['dewey_class_id' => dewey('813')->id, 'classification_type' => 'primary', 'review_status' => 'rejected']);

    app(ClassificationService::class)->assign($media, dewey('823'), ClassificationType::Primary, cand('823', .9));
    expect($media->deweyClasses()->count())->toBe(2);
});

test('manual assignments are accepted, automatic ones pending', function () {
    $media = Media::factory()->create();
    $svc = app(ClassificationService::class);

    expect($svc->assign($media, dewey('813'), ClassificationType::Primary, cand('813', 1.0, ClassificationMethod::Manual))->review_status)->toBe(ReviewStatus::Accepted)
        ->and($svc->assign($media, dewey('500'), ClassificationType::Secondary, cand('500', .8))->review_status)->toBe(ReviewStatus::Pending);
});

test('the same class cannot be both primary and secondary for one media', function () {
    $media = Media::factory()->create();
    $svc = app(ClassificationService::class);
    $svc->assign($media, dewey('813'), ClassificationType::Primary, cand('813', .9));
    $svc->assign($media, dewey('813'), ClassificationType::Secondary, cand('813', .9));
    expect($media->deweyClasses()->count())->toBe(1);
});
```
(`cand(code, confidence, method = Automatic)` and `dewey(code)` helpers in `tests/Helpers`.)

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** per Interfaces (catch `UniqueConstraintViolationException` on the partial index). **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: media dewey classes, validator and assign"`

---

### Task 3.3: `DeweyResolver`, `DeweyMapper`, `ClassificationService::classify`, `ClassifyMedia` in ingestion (Review Focus #3, #4, #5)

**Files:**
- Create: `app/Classification/Dewey/{DeweyResolver,DeweyMapper}.php`, `database/data/dewey_subject_keywords.csv` (`keyword,code`, e.g. `science→500`, `engineering→600`, `history→900`, `philosophy→100`, `religion→200`, `cooking→641`, `psychology→150`…), `app/Jobs/ClassifyMedia.php`, `app/Console/Commands/ClassifyAll.php` (`bookflix:classify-all {--only-missing}`)
- Modify: `ClassificationService` (`classify`), `app/Catalog/Ingestion/IngestionPipeline.php` (after a `Created`/`Updated` outcome: `ClassifyMedia::dispatch($media->id)->onQueue('ingestion')` — Phase 2 left this extension point), `NormalizedMedia` is unchanged (`externalDeweyCode` exists).
- Test: `tests/Unit/Classification/DeweyResolverTest.php`, `tests/Feature/Classification/ClassifyMediaTest.php`

**Interfaces:**
- Produces (A7): `DeweyResolver::resolve(string $externalCode): ?DeweyClass` (trim; accept only `/^\d{3}(\.\d+)?$/`; deepest seeded prefix, e.g. `813.54` → `813`, `741.5952` → `741.5`; no match → null). `DeweyMapper::map(Media $media): list<ClassificationCandidate>`: rules (method `Automatic`, `source = 'rules'`):
  - **prose** (`family=prose`): country/language → literature class — `US`→813, `GB`→823, `DE`→833, `FR`→843, `IT`→853, `ES`/`PT`-es→863, `RU`→891.7, `CN`→895.1, `JP`→895.6, `KR`→895.7; fall back to language (`en`→813 if country unknown, `ja`,`ko`,`zh`,`fr`,`de`,`it`,`es`,`ru` likewise); unknown both → class `800` at 0.70. Fiction genre present → 0.85, else 0.75.
  - **sequential_art** and **illustrated** (comics/manga/graphic novels/webcomics/picture books): primary `741.5x` by `country_of_origin` (JP 741.52, KR 741.53, CN/TW 741.54, US/GB/CA 741.55, FR/BE 741.56, else 741.59) at 0.80; plus a secondary 8xx literature class (via the prose rule, confidence 0.70) **only** when the media has the genre slug `literary-fiction` or media type `graphic-novel`.
  - **interactive** (visual novels): primary by the prose rule, 0.70.
  - subject keywords (matched against genre/theme/tag names and `description` words): add secondaries, confidence 0.70, never beyond the validator limits.
  `ClassificationService::classify(Media $media, ?string $externalCode = null): Collection<ClassificationCandidate>` (the pipeline passes `NormalizedMedia.externalDeweyCode`; A6 has no column to store it) — external code first (method `External`, confidence 0.95, source `external_code`) then mapper candidates; run `DeweyValidator::filter`. `ClassificationService::apply(Media $media, ?string $externalCode = null): void` persists classify() output via `assign`, **skipping any class/media pair that already has a row** and **skipping assigning a primary when the media already has a non-rejected primary or any `Manual` row** (Review Focus #4); a previously *rejected* pair is never re-added. `ClassifyMedia(int $mediaId, ?string $externalCode = null)` job (queue `ingestion`) calls `apply`; `IngestionPipeline` dispatches it with the normalized `externalDeweyCode`.

- [ ] **Step 1: Write the failing tests**

```php
test('the resolver finds the deepest seeded prefix', function (string $code, ?string $expected) {
    expect(app(DeweyResolver::class)->resolve($code)?->code)->toBe($expected);
})->with([
    ['813.54', '813'], ['813', '813'], ['741.5952', '741.5'], ['891.73', '891.7'],
    ['999.99', null], ['', null], ['abc', null], ['813.', null], ['  813.54 ', '813'],
]);

test('dune gets primary 813 from the external code at 0.95', function () {
    $media = Media::factory()->novel()->create(['title' => 'Dune', 'language' => 'en', 'country_of_origin' => 'US']);
    $c = app(ClassificationService::class)->classify($media, '813.54');

    expect($c->first()->code)->toBe('813')->and($c->first()->method)->toBe(ClassificationMethod::External)->and($c->first()->confidence)->toBe(.95);
});
```

```php
test('the martian gets 813 primary with 500 and 600 secondaries from subjects', function () {
    $media = Media::factory()->novel()->create(['title' => 'The Martian', 'language' => 'en', 'country_of_origin' => 'US']);
    $media->tags()->attach([Tag::factory()->create(['name' => 'Science'])->id, Tag::factory()->create(['name' => 'Engineering'])->id]);

    $codes = app(ClassificationService::class)->classify($media, '813.6')->pluck('code', 'code');
    expect($codes->keys()->all())->toBe(['813', '500', '600']);
});

test('a berserk style manga gets 741.52 as primary', function () {
    $media = Media::factory()->manga()->create(['country_of_origin' => 'JP', 'language' => 'ja']);
    expect(app(ClassificationService::class)->classify($media)->first()->code)->toBe('741.52');
});

test('a literary graphic novel also gets a secondary literature class', function () {
    $media = Media::factory()->state(['country_of_origin' => 'US'])->graphicNovel()->create();
    $media->genres()->attach(Genre::factory()->create(['slug' => 'literary-fiction']));
    expect(app(ClassificationService::class)->classify($media)->pluck('code')->all())->toBe(['741.55', '813']);
});

test('a sparse record classifies without error', function () {
    $media = Media::factory()->create(['country_of_origin' => null, 'language' => null, 'description' => null]);
    expect(fn () => app(ClassificationService::class)->classify($media))->not->toThrow(Throwable::class);
});

test('re-running classification never overrides a manual decision', function () {
    $media = Media::factory()->novel()->create(['country_of_origin' => 'US']);
    app(ClassificationService::class)->assign($media, dewey('823'), ClassificationType::Primary, cand('823', 1.0, ClassificationMethod::Manual));

    app(ClassificationService::class)->apply($media);

    expect($media->deweyClasses()->where('classification_type', 'primary')->pluck('dewey_class_id')->all())->toBe([dewey('823')->id]);
});

test('re-running classification does not resurrect a rejected class', function () {
    $media = Media::factory()->novel()->create(['country_of_origin' => 'US']);
    MediaDeweyClass::factory()->for($media)->create(['dewey_class_id' => dewey('813')->id, 'classification_type' => 'primary', 'review_status' => 'rejected']);

    app(ClassificationService::class)->apply($media);

    expect($media->deweyClasses()->where('dewey_class_id', dewey('813')->id)->first()->review_status)->toBe(ReviewStatus::Rejected);
});

test('ingestion dispatches classify media', function () {
    Bus::fake([ClassifyMedia::class]);
    importFixture('anilist', 'manga-berserk');
    Bus::assertDispatched(ClassifyMedia::class);
});

test('classify media persists pending rows with confidence and source', function () {
    $media = Media::factory()->manga()->create(['country_of_origin' => 'JP']);
    (new ClassifyMedia($media->id))->handle(app(ClassificationService::class));
    $row = $media->deweyClasses()->first();
    expect($row->review_status)->toBe(ReviewStatus::Pending)->and($row->source)->toBe('rules')->and((float) $row->confidence)->toBe(.8);
});
```
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Classification tests/Unit/Classification` → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS (then `php artisan test --compact tests/Feature/Catalog` to confirm Phase 2 still passes with the new dispatch). **Step 5: Commit** — `git commit -m "feat: dewey resolver, rule mapper and classification during ingestion"`

---

### Task 3.4: Explore pages with counts and breadcrumbs (Review Focus #1)

**Files:**
- Create: `app/Classification/DeweyCounts.php`, `resources/views/pages/explore/⚡index.blade.php`, `resources/views/pages/explore/⚡show.blade.php`, `resources/views/components/dewey-breadcrumb.blade.php`
- Modify: `routes/web.php` (auth + verified group: `Route::livewire('explore', 'pages::explore.index')->name('explore.index')`, `Route::livewire('explore/{code}', 'pages::explore.show')->name('explore.show')->where('code', '[0-9]{3}(\.[0-9]+)?')`), no nav item (D-13: the desktop nav is the 7 fixed items and Explore is entered from Discover and Home in Phase 4; until then use the URL; a temporary link from `pages/admin/⚡index.blade.php` is not needed)
- Test: `tests/Feature/Classification/ExploreTest.php`

**Interfaces:**
- Consumes: `DeweyHierarchy`, `MediaDeweyClass::scopePublic`.
- Produces: `DeweyCounts::forClass(DeweyClass): int` (distinct media with a **public primary** class in the subtree; cached `dewey:count:{id}` for 1 hour) and `DeweyCounts::flush(): void` (called by `ClassificationService` after every assign/accept/reject/replace/remove — add the calls here). `Media::scopeInDeweySubtree(Builder, DeweyClass)` and `Media::scopePubliclyClassified`. Show page paginates 24 stories, `#[Url] int $page` via `WithPagination`; unknown code → 404.

- [ ] **Step 1: Write the failing tests**

```php
test('explore lists the ten classes with story counts', function () {
    classified(Media::factory()->count(2)->create(), '813');
    $this->actingAs(User::factory()->create())->get(route('explore.index'))->assertOk()->assertSeeInOrder(['000', '100'])->assertSee('800')->assertSee('2');
});

test('drilling down 800 to 810 to 813 shows breadcrumbs and children', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('explore.show', '800'))->assertOk()->assertSee('810');
    $this->get(route('explore.show', '813'))->assertOk()->assertSeeInOrder(['800', '810', '813']);
});

test('a section lists the stories in its subtree', function () {
    $in = classified(Media::factory()->create(['title' => 'In Subtree']), '813');
    $out = classified(Media::factory()->create(['title' => 'Elsewhere']), '510');
    $this->actingAs(User::factory()->create())->get(route('explore.show', '810'))->assertSee('In Subtree')->assertDontSee('Elsewhere');
});

test('pending below the public minimum and rejected are hidden everywhere', function () {
    classified(Media::factory()->create(['title' => 'Low']), '813', status: 'pending', confidence: 0.60);
    classified(Media::factory()->create(['title' => 'Nope']), '813', status: 'rejected', confidence: 0.95);
    classified(Media::factory()->create(['title' => 'Shown']), '813', status: 'pending', confidence: 0.85);
    $this->actingAs(User::factory()->create());

    $this->get(route('explore.show', '813'))->assertSee('Shown')->assertDontSee('Low')->assertDontSee('Nope');
    expect(app(DeweyCounts::class)->forClass(dewey('813')))->toBe(1);
});

test('only the primary class counts a story once', function () {
    $m = classified(Media::factory()->create(), '813');
    classified($m, '500', type: 'secondary');
    expect(app(DeweyCounts::class)->forClass(dewey('500')))->toBe(0)->and(app(DeweyCounts::class)->forClass(dewey('800')))->toBe(1);
});

test('an unknown class code is a 404 and guests are redirected', function () {
    $this->get(route('explore.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('explore.show', '999'))->assertNotFound();
});
```
(`classified(Media|Collection, code, type, status, confidence)` helper creates `MediaDeweyClass` rows; default status `accepted`, confidence 0.95.) The page copy says "Explore the Library" and uses "class/division/section", never "DDC".

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (`DeweyCounts::flush()` is called from `assign` here; 3.6 adds the calls from `accept`/`reject`/`replace`/`remove` and the test that counts refresh after a rejection). **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: explore pages with dewey navigation and counts"`

---

### Task 3.5: Classification shelves

**Files:**
- Create: `app/Classification/ClassShelf.php` (readonly: `DeweyClass $class`, `Collection $media`), `app/Classification/ClassShelves.php`, `resources/views/components/class-shelves.blade.php`
- Modify: `resources/views/pages/explore/⚡index.blade.php` (a "Browse by subject" row of shelves using the component with the newest 12 stories per root)
- Test: `tests/Feature/Classification/ClassShelvesTest.php`

**Interfaces:**
- Produces: `ClassShelves::forMedia(Collection $media, string $level = 'root'): Collection<ClassShelf>` — groups by each media's public primary class's ancestor at level 1 (`'root'`) or 2 (`'division'`), ordered by class code, skipping empty groups; media without a public primary are omitted. Phase 5 reuses it for the library; keep it independent of how `$media` was loaded (eager-load `deweyClasses.deweyClass` itself to avoid N+1).

- [ ] **Step 1: Write the failing tests**

```php
test('stories are grouped by root and by division', function () {
    $a = classified(Media::factory()->create(), '813');
    $b = classified(Media::factory()->create(), '823');
    $c = classified(Media::factory()->create(), '510');
    $shelves = app(ClassShelves::class);

    expect($shelves->forMedia(collect([$a, $b, $c]))->map(fn ($s) => $s->class->code)->all())->toBe(['500', '800'])
        ->and($shelves->forMedia(collect([$a, $b, $c]), 'division')->map(fn ($s) => $s->class->code)->all())->toBe(['510', '810', '820']);
});

test('an empty collection and unclassified media produce no shelves', function () {
    expect(app(ClassShelves::class)->forMedia(collect()))->toBeEmpty()
        ->and(app(ClassShelves::class)->forMedia(collect([Media::factory()->create()])))->toBeEmpty();
});

test('hidden classifications do not create shelves', function () {
    $m = classified(Media::factory()->create(), '813', status: 'rejected');
    expect(app(ClassShelves::class)->forMedia(collect([$m])))->toBeEmpty();
});

test('building shelves for 20 stories runs a constant number of queries', function () {
    $media = Media::factory()->count(20)->create()->each(fn ($m) => classified($m, '813'));
    DB::enableQueryLog();
    app(ClassShelves::class)->forMedia($media->fresh());
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(3);
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: classification shelves"`

---

### Task 3.6: Admin review queue (Review Focus #2)

**Files:**
- Create: `resources/views/pages/admin/classifications/⚡index.blade.php` (pending rows sorted by confidence asc, filter by status, paginated 25; actions accept / reject / change (select a class by code) / add / remove)
- Modify: `app/Classification/ClassificationService.php` (`accept`, `reject`, `replace`, `remove`), `routes/admin.php` (`admin.classifications`), admin index links
- Test: `tests/Feature/Admin/ClassificationReviewTest.php`

**Interfaces:**
- Produces (A7): `accept(MediaDeweyClass, User): void`, `reject(MediaDeweyClass, User): void`, `replace(MediaDeweyClass, DeweyClass, User): MediaDeweyClass` (swaps within `DB::transaction`: marks the old row `rejected`, creates the new one with method Manual, confidence 1.0, `Accepted`, same type), plus `remove(MediaDeweyClass, User): void` (delete). All set `reviewed_by`/`reviewed_at` and call `DeweyCounts::flush()`.

- [ ] **Step 1: Write the failing tests**

```php
test('non admins get 403 on the review queue', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.classifications'))->assertForbidden();
});

test('the queue lists pending rows lowest confidence first', function () {
    classified(Media::factory()->create(['title' => 'High']), '813', status: 'pending', confidence: 0.9);
    classified(Media::factory()->create(['title' => 'Low']), '813', status: 'pending', confidence: 0.5);
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.classifications'))->assertSeeInOrder(['Low', 'High']);
});

test('accept and reject record the reviewer and time', function () {
    $admin = User::factory()->admin()->create();
    $a = classified(Media::factory()->create(), '813', status: 'pending');
    $b = classified(Media::factory()->create(), '823', status: 'pending');
    $this->actingAs($admin);

    Livewire::test('pages::admin.classifications.index')->call('accept', $a->deweyClasses()->first()->id)->call('reject', $b->deweyClasses()->first()->id);

    $ra = $a->deweyClasses()->first();
    expect($ra->review_status)->toBe(ReviewStatus::Accepted)->and($ra->reviewed_by)->toBe($admin->id)->and($ra->reviewed_at)->not->toBeNull()
        ->and($b->deweyClasses()->first()->review_status)->toBe(ReviewStatus::Rejected);
});

test('changing the primary swaps it atomically', function () {
    $m = classified(Media::factory()->create(), '813', status: 'pending');
    $row = $m->deweyClasses()->first();
    $this->actingAs($admin = User::factory()->admin()->create());

    Livewire::test('pages::admin.classifications.index')->call('change', $row->id, '823');

    expect($row->fresh()->review_status)->toBe(ReviewStatus::Rejected)
        ->and($m->deweyClasses()->where('review_status', 'accepted')->where('classification_type', 'primary')->sole()->dewey_class_id)->toBe(dewey('823')->id);
});

test('a failed swap leaves the original primary intact', function () {
    $m = classified(Media::factory()->create(), '813', status: 'pending');
    $row = $m->deweyClasses()->first();
    expect(fn () => app(ClassificationService::class)->replace($row, new DeweyClass, User::factory()->admin()->create()))->toThrow(Throwable::class);
    expect($row->fresh()->review_status)->toBe(ReviewStatus::Pending);
});

test('an admin can add a secondary class and remove a class', function () {
    $m = classified(Media::factory()->create(), '813');
    $this->actingAs(User::factory()->admin()->create());
    $lw = Livewire::test('pages::admin.classifications.index');

    $lw->call('add', $m->id, '500', 'secondary');
    expect($m->deweyClasses()->count())->toBe(2);

    $lw->call('remove', $m->deweyClasses()->where('classification_type', 'secondary')->first()->id);
    expect($m->deweyClasses()->count())->toBe(1);
});

test('every action rejects a non admin caller', function () {
    $row = classified(Media::factory()->create(), '813', status: 'pending')->deweyClasses()->first();
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::admin.classifications.index')->call('accept', $row->id)->assertForbidden();
});
```
Rewrite the "failed swap" test to pass an unsaved `new DeweyClass` (null id) as the target: the thrown exception must roll the transaction back. Also add here: `expect(app(DeweyCounts::class)->forClass(dewey('813')))->toBe(0)` after rejecting a story's only primary (counts refresh).

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat: admin classification review queue"`

---

### Task 3.7: Backfill command and coverage report

**Files:**
- Modify: `app/Console/Commands/ClassifyAll.php` (from 3.3: chunked `Media::lazyById(500)`, dispatches `ClassifyMedia`; `--only-missing` skips media with any non-rejected primary), `app/Console/Commands/ClassificationCoverage.php` (`bookflix:classification-coverage` prints total, with-public-primary, percent)
- Test: `tests/Feature/Classification/ClassifyAllTest.php`

**Interfaces:** consumes `ClassifyMedia`; produces command output `Coverage: 80.0% (4/5)` — format fixed by the test.

- [ ] **Step 1: Write the failing tests**

```php
test('classify all dispatches one job per media and only-missing skips classified ones', function () {
    Bus::fake();
    classified(Media::factory()->create(), '813');
    Media::factory()->count(2)->create();

    $this->artisan('bookflix:classify-all', ['--only-missing' => true])->assertSuccessful();
    Bus::assertDispatchedTimes(ClassifyMedia::class, 2);
});

test('coverage reports the share of media with a public primary class', function () {
    classified(Media::factory()->count(4)->create(), '813');
    Media::factory()->create();
    $this->artisan('bookflix:classification-coverage')->expectsOutputToContain('Coverage: 80.0% (4/5)')->assertSuccessful();
});
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** `composer test` → PASS. **Step 5: Commit** — `git commit -m "feat: classification backfill and coverage commands"`

---

## Phase 3 exit criteria
- [ ] `composer test` green on Postgres; Phase 2 tests still green.
- [ ] `bookflix:classification-coverage` reports ≥ 80% on the synced dev catalog; the review queue works end to end.
- [ ] Copy review (`design:ux-copy`): no "DDC"/"official" wording anywhere in views.
- [ ] Ask the user to run the full `php artisan test --compact`.

## Self-review notes
- Spec coverage: §11–§13 (3.1–3.2), §14–§15 (3.3), §16 (3.4), §17 (3.5), §18 rules half (3.3; ML half is Phase 7.6), §19 (3.6), §49 service boundary (3.2/3.3).
- Names match master plan A7 (`DeweyHierarchy`, `DeweyResolver`, `DeweyMapper`, `DeweyValidator`, `ClassificationService::{classify,assign,accept,reject,replace}`, `ClassificationCandidate`). Deviations (flagged in the report): `classify`/`apply` take an optional `?string $externalCode`; `741.52–741.59` are Bookflix-defined section codes.
