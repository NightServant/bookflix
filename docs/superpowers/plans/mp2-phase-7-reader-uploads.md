# Phase 7: Reader and your own files Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the reader open MOBI/AZW3 and FB2, give books a light theme and margin control, give comics zoom and swipe, end every book or volume on a one-tap "next" prompt, and turn "Add your own file" into a bulk, DRM-checked, auto-matched, series-grouped upload with its quota shown in Settings.

**Architecture:** foliate-js (already the EPUB engine) is routed for the two new book formats by `ContentFormat` and `epub.js`; comics stay on `pages.js` with added pointer gestures; `App\Reader\NextChapter` becomes the single "what comes next" answer for every engine. Upload intake grows three small classes (`EpubDrmDetector`, `UploadMetadataReader`, `UploadMatcher`) that `UploadContentSource` and the upload page call; uploads remain `content_sources` rows (D2-8).

**Tech Stack:** Laravel 13 / Livewire 4 single-file pages, Alpine (`readerChrome`), foliate-js 1.0.1, Pest 5 + browser plugin, Postgres.

**Spec:** `docs/superpowers/specs/2026-11-26-cleanup-brief.md` F1, F2, F6, Part I (uploads and next-chapter tests); `docs/superpowers/plans/2026-11-26-bookflix-masterplan-2.md` Part F "Phase 7", Part F.0 row 7, D2-7, D2-8, D2-12, D2-17.

**Branch:** `mp2-phase-7-reader-uploads` · **Ledger:** `docs/superpowers/plans/mp2-phase-7-ledger.md` · **Depends on:** Phase 6 (`stories.show` public), Phase 3 (`legal.terms`, `legal.privacy`, `bookflix.legal.contact_email`, `R/legal/sections/*`), Phase 4 (`settings.edit`, `x-settings.summary-card`), Phase 1 (hardened Livewire middleware), Phase 2 (`NoHorizontalScrollTest`).

`R/` below means `resources/views/`. A path with `⚡` is a single-file Livewire page.

## Global Constraints

Every Global Constraint in the masterplan applies. Phase-specific:

- No new Composer, npm or Python dependency. S3 direct upload needs `league/flysystem-aws-s3-v3`, which is not installed: record it "Needs decision" (D2-7). Resumable uploads: "Needs decision".
- Fixtures are generated in tests (`tinyEpub()` style). Never download a book. Browser-test servers read an upload body as text: use ASCII fixtures (PDF, FB2) for upload browser tests.
- Uploads stay `content_sources` rows with `access_type=user_owned`. No `user_uploads` table, no `page_count` or `metadata` column (D2-8, P7-R5).
- Reader chrome stays tokens-only: no red except the one primary action, no shadows added. Reduced motion: no animation added that ignores `prefers-reduced-motion`.
- User-facing copy for DRM never mentions removal, cracking, stripping or decrypting.
- Config defaults added: `bookflix.reader.upload.mobi_mb` 100, `fb2_mb` 50, `max_batch` 25; `per_hour` 10 becomes 50 (P7-R6). Each gets a line in `config/bookflix.php` comments.
- Webtoon detection threshold: tall-image ratio height/width of 2.0 or more (P7-R2). Double-tap zoom 2.5, max zoom 4. Swipe: at least 60 px, at least 1.5 times as far across as down, under 600 ms.
- Never commit a real email or person name. Contact addresses come from `config('bookflix.legal.contact_email')`; when null the view carries `{{-- TODO(owner): takedown contact --}}`.

## Review Focus

1. **A user's upload leaks to another reader** (direct `/read/{slug}?source=`, a signed URL replayed by someone else, a Livewire action on someone else's file id, or a hosted-looking "Read" button for a story only another user uploaded to), or a guest reaching anything but a verified public-domain file on an unrestricted story. → Tasks 7.9 and 7.10.
2. **A DRM-protected EPUB is accepted, or an ordinary EPUB with font obfuscation is wrongly rejected.** Both hurt: one stores a file nobody can read, the other blocks legitimate books. → Task 7.7 (allowed-algorithm and malformed `encryption.xml` cases).
3. **Bulk upload half-fails silently**: one bad file aborts the batch, the quota is passed mid-batch, or a file is attached to the wrong volume without the reader noticing. → Task 7.8 (per-row errors, quota mid-batch, unmatched rows blocked until a story is chosen).
4. **The last page dead-ends**: a comic's last page or a series' final volume shows a prompt to a book the reader can't open, or skips straight to a list. → Task 7.4 (ordering, unreadable next, uploads, one-tap browser test).
5. **A zoomed or swiped comic turns the page by accident**, so a reader panning a zoomed page loses their place. → Task 7.3 (tap and swipe ignored while zoomed).

## Cross-phase interfaces

**Consumes:** Phase 3 routes `legal.terms` / `legal.privacy` and `config('bookflix.legal.contact_email')`; Phase 4 route `settings.edit`, page `pages::settings.index`, component `x-settings.summary-card` (right-column card; confirm its props in Baseline step 7); Phase 2 `tests/Browser/NoHorizontalScrollTest.php` public-page list.

**Produces (registry names, exact):**

- Column `content_sources.jurisdiction` (nullable string, 120).
- `App\Reader\Archives\EpubDrmDetector::isProtected(string $path): bool`
- `App\Reader\Uploads\UploadMetadataReader::read(string $path, App\Enums\ContentFormat $format, string $originalName): App\Reader\Uploads\UploadMetadata`
- `App\Reader\Uploads\UploadMetadata` (readonly): `?string $series`, `?string $volume`, `?string $chapter`, `?string $title`, `list<string> $creators`.
- `App\Reader\NextChapter::for(App\Models\Media $media, ?App\Models\ContentSource $source, App\Models\User $user): ?App\Reader\NextChapterTarget`
- `App\Reader\NextChapterTarget` (readonly): `Media $media`, `string $label`, `bool $readable`, `string $url`.
- Route `help.reading-apps` (GET `/help/reading-apps`, public).
- Added (not in registry): `ContentFormat::Mobi`, `ContentFormat::Fb2`, `App\Reader\Archives\MobiInspector`, `App\Reader\Cbz\WebtoonDetector::isStrip(ContentSource $source): bool`, `App\Reader\Uploads\UploadMatcher::match(UploadMetadata $metadata, User $user): ?Media`, `App\Reader\Uploads\UploadQuota`, `App\Actions\Reader\RematchUpload`, `config('bookflix.reading_apps')`.

## Rulings

| # | Ruling | Cost if wrong |
|---|---|---|
| P7-R1 | One `ContentFormat::Mobi` covers MOBI, AZW3 and KF8 (all share the `BOOKMOBI` PalmDB signature, and foliate reads them with one class); label "MOBI / AZW3". FB2 inside a zip (`.fb2.zip`, `.fbz`) is not accepted this phase. Encrypted MOBI (PalmDOC encryption field 1 or 2) is refused as DRM. | Low; a reader with a `.fbz` gets the "not a supported file" message. |
| P7-R2 | Webtoon auto-detection runs on the server: the median height/width of the first 5 pages (from image headers only) is 2.0 or more, for a paged comic type. Series metadata already routes manhwa, manhua and webcomic types to the strip (`reading_mode=vertical_scroll`). | Medium; a 1.6 to 1.9 webtoon cut stays paged. Raise or lower the constant in `WebtoonDetector`. |
| P7-R3 | Pinch and double-tap zoom is built for the paged comic engine only. The webtoon strip keeps the browser's own page zoom (the viewport meta does not block it). | Low; a strip reader cannot double-tap zoom. |
| P7-R4 | Swipe is added to comics. For books foliate's paginator already turns pages on touch drags (`paginator.js` touchstart/touchend); Task 7.2 tests that and adds an `epub.js` handler only if the test fails. | Low. |
| P7-R5 | An upload that matches no catalog story is not stored until the reader picks one. Bookflix never creates catalog rows from uploads, so uploads stay out of search, shelves and recommendations. | Low; one extra click per unmatched file. |
| P7-R6 | `per_hour` goes from 10 to 50 and a selection holds at most 25 files (`max_batch`); otherwise bulk upload cannot work. The quota and per-file caps are unchanged. | Medium; abuse is bounded by quota and 50 per hour. |
| P7-R7 | "Next" is the first later volume in series order (position ascending, nulls last, then title) among the next 25 that this reader may open; if none is openable the immediate next volume is returned with `readable=false` and its story-page URL. | Low; a reader with gaps skips ahead instead of landing on a dead card. |
| P7-R8 | `livewire.temporary_file_upload.disk` becomes `env('LIVEWIRE_TEMP_UPLOAD_DISK', 'local')`. Setting it to `s3` is "Needs decision" until the Flysystem S3 package is approved. | Low; default is unchanged. |
| P7-R9 | `content_sources.jurisdiction` is a plain place phrase ("the United States"), no ISO code, no backfill. Where it is null the notice falls back to the stored license text. | Low; a TODO(owner) lists the Standard Ebooks and Gutenberg rows to fill. |
| P7-R10 | There is no download area on the story page (Baseline step 8). The reading-apps link sits in the license notice of a hosted file and in the landing footer. | Low. |
| P7-R11 | `EpubDrmDetector` fails closed: an `encryption.xml` that is not valid XML counts as protected. | Low; a broken book is refused with the DRM message. |
| P7-R12 | MOBI and PDF metadata come from the filename only (no EXTH parsing). | Low. |
| P7-R13 | **(owner)** Guests may read. `reader.show`, `reader.stream` and `reader.page` open to guests only for a source that is `public_domain`, verified by an admin, ready, with a stored file, on media not gated by `MatureAccess` for a null user. Everything else (user upload, unverified, external link, licensed) is refused to a guest: the stream and page routes answer **403**, the reader page redirects to `login`. Phase 9 widens this only through `LicenseGate` (worldwide licences). `ContentAccessGate::allows` takes a nullable user; the argument order stays `(?User $user, ContentSource $source)` as today so no caller changes (the coordinator's `(source, user)` order is for Phase 9's `LicenseGate`). Guest URLs bind to a hash of the session id (`hash_hmac('sha256', session id, app key)`, never the raw id), 10-minute TTL, plus a per-IP guest throttle (`bookflix.reader.guest_per_minute`, default 120). Guest progress is browser-only (localStorage `bookflix.guest.progress.<sourceId>`); `reader.progress` stays `auth`. | Medium; a gate slip exposes files. Cost is bounded because the guest rule is a closed allow-list in one method. |

## Baseline re-check

Run first and record in the ledger (what to grep, what was found).

- [ ] **1. foliate identity (D2-17).** `cat node_modules/foliate-js/package.json` shows `"name": "foliate-js"`, `"version": "1.0.1"`, `repository` `johnfactotum/foliate-js`, `"license": "MIT"`; `head -3 node_modules/foliate-js/LICENSE` is the MIT text. Record version, licence, and that `package-lock.json` pins it. Confirm `grep -n "isMOBI\|isFB2" node_modules/foliate-js/view.js` (both exist) and `grep -n "allow-scripts" vite.config.js` (sandbox rewrite).
- [ ] **2. Already present in books.** `grep -n "lineHeight\|fontSize" resources/js/reader/chrome.js` (line height and size exist and persist in `bookflix.reader.settings`); `grep -n "onKey" resources/js/reader/chrome.js` (arrows, Space, PageUp/Down, `/`, `f`); `grep -n "prefers-reduced-motion\|animated" resources/js/reader/*.js` (foliate's `animated` attribute is never set; `ReaderChromeTest` "with reduced motion" exists). Missing: light theme, margins.
- [ ] **3. Already present in comics.** `grep -n "pointerup\|tap(" resources/js/reader/chrome.js` (touch tap zones: thirds, touch only); `grep -n "zoom\|swipe\|dblclick" resources/js/reader/pages.js` (expect no matches: zoom and swipe are missing); `grep -n "ArrowLeft" tests/Browser/MangaReaderTest.php` (arrow keys tested); `grep -n "reading_mode" app database/seeders` (manhwa, manhua, webcomic already map to the strip).
- [ ] **4. Next prompt today.** `grep -n "function next" -A18 'R/pages/reader/⚡show.blade.php'` (an inline computed `next`); `grep -n "next-episode" R/pages/reader/⚡show.blade.php` (webtoon only); `R/components/reader/finished.blade.php` renders only for `epub` and `pdf`. A paged comic has no end prompt.
- [ ] **5. Upload today.** `grep -n "DRM" R/pages/library/⚡upload.blade.php` (attestation checkbox only); `grep -rn "ComicInfo" app` (none); `composer show league/flysystem-aws-s3-v3` (not installed); `grep -n "per_hour\|quota_mb" config/bookflix.php`.
- [ ] **6. Format column.** `php artisan db:table content_sources` or Boost `database-schema`: record the exact check-constraint name on `format` (expected `content_sources_format_check`).
- [ ] **7. Earlier phases.** `ls R/legal/sections` (Phase 3 uploads section present?), `grep -rn "summary-card" R/components/settings` (props), `ls R/pages/settings` (`⚡index.blade.php` present), `ls tests/Browser/NoHorizontalScrollTest.php`. If one is missing, record it and use the nearest existing file; do not re-plan that phase.
- [ ] **8. Story page.** `grep -rn -i "download" R/pages/stories R/components/where-to-read.blade.php` (no download area exists; P7-R10).

## Tasks

### Task 7.1: MOBI/AZW3 and FB2 open in the reader

**Files:**
- Modify: `app/Enums/ContentFormat.php`, `app/Reader/UploadContentSource.php` (`detect`, caps), `app/Reader/SourceChooser.php` (`FORMATS`), `R/pages/reader/⚡show.blade.php` (`engine()`), `resources/js/reader/epub.js` (the `new File(...)` in `open()`), `config/bookflix.php` (`reader.upload.mobi_mb`, `fb2_mb`), `R/pages/library/⚡upload.blade.php` (`accept`, `limits`)
- Create: `app/Reader/Archives/MobiInspector.php`, `database/migrations/2026_11_27_100000_add_mobi_and_fb2_to_content_sources_format.php`
- Test: `tests/Feature/Reader/UploadContentSourceTest.php`, `tests/Browser/BookFormatsReaderTest.php`, helpers `tinyFb2()` and `tinyMobi()` in `tests/Pest.php` next to `tinyEpub()`

**Interfaces:**
- Produces: `ContentFormat::Mobi` (`'mobi'`, mime `application/x-mobipocket-ebook`, label "MOBI / AZW3"), `ContentFormat::Fb2` (`'fb2'`, mime `application/x-fictionbook+xml`, label "FB2"); `MobiInspector::matches(string $path): bool` (PalmDB type+creator `BOOKMOBI` at byte 60), `MobiInspector::isEncrypted(string $path): bool` (first record's PalmDOC header, bytes 12-13, is 1 or 2).

- [ ] **Step 1: Write the failing tests**

```php
// UploadContentSourceTest.php (follow the file's helpers upFileOf/uploadBook/upRefusal)
test('a MOBI is detected from its bytes and stored ready as mobi, whatever it is named', function (string $name) {
    $source = uploadBook($this->user, $this->media, upFileOf(upMobiBytes(), $name));
    expect($source->format)->toBe(ContentFormat::Mobi)->and($source->status)->toBe(ContentStatus::Ready)
        ->and($source->storage_path)->toEndWith('.mobi')->and($source->mime_type)->toBe('application/x-mobipocket-ebook');
})->with(['a.mobi', 'a.azw3', 'a.bin']);

test('a FictionBook file is accepted as fb2 and an html page named .fb2 is not', function () {
    expect(uploadBook($this->user, $this->media, upFileOf(tinyFb2(), 'a.fb2'))->format)->toBe(ContentFormat::Fb2);
    expect(upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf('<html><body>hi</body></html>', 'a.fb2')))->errors()['file'][0])
        ->toBe('This file is not a supported book. Use EPUB, MOBI, AZW3, FB2, PDF, CBZ or CBR.');
});

test('a MOBI with its encryption field set is refused with the copy-protection message', function () {
    expect(upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf(upMobiBytes(encryption: 2), 'a.mobi')))->errors()['file'][0])
        ->toBe("This book is copy-protected, so Bookflix can't open it. Add a DRM-free copy instead.");
});

test('a MOBI over mobi_mb is refused by size', function () { config(['bookflix.reader.upload.mobi_mb' => 0.0001]); /* expect "This file is larger than" */ });
```

`upMobiBytes(int $encryption = 0)`: 78-byte PalmDB header (name, `BOOKMOBI` at offset 60), one record entry at offset 78 pointing to byte 86, then a 16-byte PalmDOC header whose bytes 12-13 hold `$encryption` (big-endian). `tinyFb2()` (in `tests/Pest.php`): ASCII FictionBook 2.0 XML, `xmlns="http://www.gribuser.ru/xml/fictionbook/2.0"`, title "The Open Book", two `<section>` with `<title>` "I. Down the Rabbit-Hole" and "II. The Pool of Tears". `tinyMobi()` (in `tests/Pest.php`): the smallest uncompressed MOBI foliate will open (PalmDB + PalmDOC compression 1 + MOBI header, one text record).

```php
// BookFormatsReaderTest.php (follow EpubReaderTest.php / epubSource())
test('an FB2 book opens, shows its chapters and turns pages', function () {
    $source = bookSource(ContentFormat::Fb2, tinyFb2());   // helper beside epubSource() in tests/Pest.php: same body with a format argument
    actingAs(User::factory()->create());
    visit(route('reader.show', $source->media->slug), ['viewport' => ['width' => 1440, 'height' => 900]])
        ->waitForText('Chapter 1 of 2')->assertSeeIn('[data-reader=toolbar]', 'The Open Book')
        ->assertNoJavaScriptErrors()->assertNoConsoleLogs();
});
test('a MOBI book opens', fn () => /* same with tinyMobi(); if foliate rejects the hand-built file after one fixture fix, ->skip('foliate rejects the generated MOBI: check a real file by hand') and say so in the ledger */);
```

- [ ] **Step 2: Run to verify they fail**
Run: `vendor/bin/pest tests/Feature/Reader/UploadContentSourceTest.php --filter="MOBI|FictionBook"` then `vendor/bin/pest tests/Browser/BookFormatsReaderTest.php`. Expected: FAIL (`ContentFormat::Mobi` undefined; message text differs).

- [ ] **Step 3: Implement**
  - Migration: drop and recreate `content_sources_format_check` with the old six values plus `mobi`, `fb2` (copy the `constrain()` pattern of `2026_10_30_000004_extend_media_age_rating_values.php`; `down()` restores six values after deleting nothing: guard with a count check that throws if mobi/fb2 rows exist).
  - `UploadContentSource::detect`: after the PDF/zip/RAR checks, `MobiInspector::matches` returns `Mobi` (encrypted: refuse with the copy-protection message above), and a file whose first 1024 bytes (BOM and `<?xml` allowed) contain `<FictionBook` returns `Fb2`. The "not a book" message becomes `This file is not a supported book. Use EPUB, MOBI, AZW3, FB2, PDF, CBZ or CBR.` (update the existing tests that assert the old text). Cap lookup: `mobi_mb`, `fb2_mb`.
  - `SourceChooser::FORMATS` = Epub, Mobi, Fb2, Pdf, Cbz. `engine()` maps Epub, Mobi and Fb2 to `'epub'`.
  - `epub.js` `open()`: build the `File` from `container.dataset.format`: epub `book.epub` + `application/epub+zip`; mobi `book.mobi` + `application/x-mobipocket-ebook`; fb2 `book.fb2` + `application/x-fictionbook+xml` (foliate picks the parser from the name or type).
  - Upload page `accept` adds `.mobi,.azw3,.fb2`; `limits()` adds "MOBI up to :mobi · FB2 up to :fb2".

- [ ] **Step 4: Run to verify they pass**
Run: `vendor/bin/pest tests/Feature/Reader tests/Feature/Admin/AdminContentVerificationTest.php && vendor/bin/pest tests/Browser/BookFormatsReaderTest.php tests/Browser/EpubReaderTest.php`. Expected: PASS (MOBI browser test may be skipped as stated).

- [ ] **Step 5: Commit** `git commit -m "feat: open MOBI, AZW3 and FB2 books in the reader"`

### Task 7.2: Light theme and margin control

**Files:**
- Modify: `resources/css/app.css` (tokens beside `--color-reader-sepia`), `resources/js/reader/epub.js` (`COLORS`, `layout()`), `resources/js/reader/chrome.js` (`DEFAULTS.epub`), `R/components/reader/settings-panel.blade.php` (theme radiogroup, margin row)
- Test: `tests/Browser/ReaderChromeTest.php` (follow its settings-panel tests)

**Interfaces:** setting `theme` gains `light`; new setting `margin` in `narrow|normal|wide`, default `normal`, stored in `bookflix.reader.settings` like `fontSize`.

- [ ] **Step 1: Write the failing tests**

```php
test('Light theme paints the book pale, is kept on reload, and Sepia and Dark still work', function () {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(0.4);
    $page->click('[data-reader=settings-button]')->wait(0.3)->click('[data-reader-option="theme:light"]')->wait(0.4)
        ->assertScript('getComputedStyle(('.$this->doc.').body).backgroundColor', 'rgb(246, 244, 239)')
        ->assertScript('getComputedStyle(('.$this->doc.').body).color', 'rgb(28, 28, 28)')
        ->assertAttribute('[data-reader-option="theme:light"]', 'aria-checked', 'true');
    $page->refresh()->waitForText('Chapter 1 of 2')->wait(0.5)
        ->assertScript('getComputedStyle(('.$this->doc.').body).backgroundColor', 'rgb(246, 244, 239)');
});

test('Margins change the gap around the text and are kept', function (string $margin, string $desktopGap) {
    $page = visit(($this->read)(), $this->desktop)->waitForText('Chapter 1 of 2')->wait(0.4);
    $page->click('[data-reader=settings-button]')->wait(0.3)->click('[data-reader-option="margin:'.$margin.'"]')->wait(0.4)
        ->assertScript('document.querySelector("foliate-view").renderer.getAttribute("gap")', $desktopGap)
        ->assertScript('JSON.parse(localStorage.getItem("bookflix.reader.settings")).margin', $margin);
})->with([['narrow', '4%'], ['normal', '8%'], ['wide', '14%']]);

test('on a phone the margins are 3%, 6% and 10%', function () { /* same on $this->phone, expecting '3%' / '6%' / '10%' */ });

test('swiping a book on a touch screen turns the page (foliate does it natively)', function () {
    // $this->phone + ['hasTouch' => true]; dispatch touchstart, touchmove (screenX 300 -> 80) and touchend on the first section's body with
    // new Touch({...}) / TouchEvent; assert the "Page n of N" label in [data-reader=bottom-bar] advances by one.
});
```

- [ ] **Step 2: Run** `vendor/bin/pest tests/Browser/ReaderChromeTest.php --filter="Light|Margins|phone the margins|swiping"`. Expected: FAIL (no `theme:light` option, no `margin` option). The swipe test may already pass: that proves P7-R4.

- [ ] **Step 3: Implement**
  - `app.css`: `--color-reader-light: #f6f4ef;` and `--color-reader-light-ink: #1c1c1c;` next to the sepia tokens, and `bg-reader-light` / `text-reader-light-ink` utilities are generated from them the way the other two are.
  - `epub.js`: `COLORS.light = { background: ['--color-reader-light', '#f6f4ef'], ink: ['--color-reader-light-ink', '#1c1c1c'], link: '#0b57d0' }`; `html { color-scheme: ... }` is `light` for the light theme, else `dark`. `layout()`: `gap` from a table `{ narrow: ['4%','3%'], normal: ['8%','6%'], wide: ['14%','10%'] }` (wide screen, phone); unknown value falls back to normal.
  - `chrome.js` `DEFAULTS.epub.margin = 'normal'`; `loadSettings` already keeps stored values whose type matches.
  - `settings-panel.blade.php` (book variant): the theme radiogroup lists dark, sepia, light (`data-reader-option="theme:light"`, label "Light", swatch `bg-reader-light text-reader-light-ink`) and its arrow-key handler cycles three values; add a "Margins" row with `<x-reader.segmented name="margin" model="settings.margin" :options="['narrow' => __('Narrow'), 'normal' => __('Normal'), 'wide' => __('Wide')]">`.
  - Only if the swipe test failed: in `epub.js` `forward()` handle touch `pointerdown`/`pointerup` on the section document with the swipe thresholds in Global Constraints and emit `reader:swipe` (chrome handles it in Task 7.3 Step 3); record it in the ledger.

- [ ] **Step 4: Run** `vendor/bin/pest tests/Browser/ReaderChromeTest.php tests/Browser/EpubReaderTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat: light reader theme and margin setting"`

### Task 7.3: Comics reader: zoom, swipe and webtoon detection

**Files:**
- Create: `app/Reader/Cbz/WebtoonDetector.php`, `tests/Feature/Reader/WebtoonDetectorTest.php`
- Modify: `resources/js/reader/pages.js` (paged mode: pointer gestures, `layoutPaged`), `resources/js/reader/chrome.js` (`zoomed`, `tap`, `reader:swipe`), `R/pages/reader/⚡show.blade.php` (`engine()`), `tests/Feature/Reader/ReaderPageTest.php`
- Test: `tests/Browser/MangaReaderTest.php`

**Interfaces:**
- Produces: `WebtoonDetector::isStrip(ContentSource $source): bool`. Events: `reader:zoom` `{ zoomed: bool }` (engine to chrome), `reader:swipe` `{ forward: bool }` (engine to chrome). `stage.dataset.zoom` holds the number.

- [ ] **Step 1: Write the failing tests**

```php
// WebtoonDetectorTest.php (ContentSource::factory()->cbz(pages, size) writes the pages)
test('a comic is a strip when the median of its first five pages is at least twice as tall as wide', function (array $sizes, bool $strip) {
    Storage::fake(ContentSource::disk());
    $source = ContentSource::factory()->for(Media::factory()->manga())->publicDomain()->verified()->cbz(count($sizes), sizes: array_combine(range(1, count($sizes)), $sizes))->create();
    expect(app(WebtoonDetector::class)->isStrip($source))->toBe($strip);
})->with([
    'ordinary comic pages' => [[[100, 150], [100, 150], [100, 150]], false],
    'exactly 2.0' => [[[100, 200], [100, 200], [100, 200]], true],
    'a tall strip' => [[[80, 400], [80, 400], [80, 400]], true],
    'one tall cover in a normal book' => [[[100, 400], [100, 150], [100, 150], [100, 150], [100, 150]], false],
    'only the first five count' => [[[100, 150], [100, 150], [100, 150], [100, 150], [100, 150], [100, 500], [100, 500], [100, 500], [100, 500]], false],
]);
test('a file that cannot be read is not a strip', fn () => /* a cbz source whose stored bytes are not a zip -> false, no exception */);

// ReaderPageTest.php
test('a manga whose pages are tall strips opens the webtoon engine, an ordinary manga stays paged', function () {
    // comicSource('manga', 5, [100, 250]) -> assertSeeHtml('data-reader-engine="webtoon"'); comicSource('manga', 5, [40, 60]) -> 'pages'
});
```

```php
// MangaReaderTest.php (follow its existing tests; comicSource('manga', 6, [40, 60]); script dispatch like ReaderChromeTest line 115)
test('double-tap zooms to 2.5 and a second double-tap returns to fit', function () {
    // $this->phone + ['hasTouch' => true]; dispatch two pointerup (pointerType touch, same x,y, 120 ms apart) on [data-reader=pages-stage]
    // assertScript('document.querySelector("[data-reader=pages-stage]").dataset.zoom', '2.5'); repeat -> '1'
});
test('a double-click zooms on desktop', fn () => /* dispatchEvent(new MouseEvent('dblclick')) -> dataset.zoom '2.5' */);
test('pinching two pointers apart zooms in, capped at 4', fn () => /* pointerdown id 1 and 2 at 100px apart, pointermove to 600px apart -> dataset.zoom '4' */);
test('while zoomed a side tap and a swipe do not turn the page', function () {
    // zoom via double-tap; tap x=0.9 and swipe 200px -> [data-reader=page-label] still "Page 1 of 6"; reset zoom -> a side tap turns.
});
test('swiping turns the page the way the book reads: left for next in LTR, right for next in RTL', function () {
    // manga is RTL: a touch swipe from x=100 to x=300 (rightward) goes to Page 2; a swipe under 60px, or more vertical than horizontal, does nothing.
});
test('turning a page resets the zoom', fn () => /* zoom, press ArrowLeft (RTL next) -> dataset.zoom '1' */);
```

- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Reader/WebtoonDetectorTest.php tests/Feature/Reader/ReaderPageTest.php --filter="strip|Webtoon"` and `vendor/bin/pest tests/Browser/MangaReaderTest.php --filter="zoom|swip|pinch"`. Expected: FAIL (class missing; `dataset.zoom` undefined).

- [ ] **Step 3: Implement**
  - `WebtoonDetector`: `RATIO = 2.0`, `SAMPLE = 5`, `HEADER_BYTES = 262144`. Uses `CbzExtractor::index($source)` for the first five `CbzPage`s and `CbzExtractor::open()` with `ZipGuard::readEntry` per page, `getimagesizefromstring` for width and height, the median of height/width `>= RATIO`. Any `UnsafeArchive`, unreadable image or zero width counts as "not a strip". It does not read the whole image.
  - `engine()` in `⚡show.blade.php`: for `Cbz` with a type whose `reading_mode` is not `VerticalScroll`, return `'webtoon'` when `WebtoonDetector::isStrip($this->source)`.
  - `pages.js` (paged only, listeners through the existing `on()`): `touch-action: pan-x pan-y` on the stage so the browser does not pinch the page itself. State `zoom` (1 to 4). `layoutPaged` multiplies the computed image height and width by `zoom`, so the stage scrolls. Double-tap (two touch `pointerup` within 300 ms and 30 px) and `dblclick` toggle 1 and 2.5 around the tap point (adjust `scrollLeft/Top` to keep it under the finger); two active pointers change `zoom` by the ratio of their distance; `ctrl+wheel` zooms the same way. Every change sets `stage.dataset.zoom` and emits `reader:zoom` `{ zoomed: zoom > 1 }`; `relocate()` to a different page and `reader:settings` reset zoom to 1. Swipe: a single touch or pen pointer, not zoomed, meeting the thresholds in Global Constraints, emits `reader:swipe` `{ forward }` where forward is leftward for `ltr` and rightward for `rtl`.
  - `chrome.js`: `zoomed: false` state set by `reader:zoom`; `tap()` ignores the side thirds while `zoomed` (the middle third still toggles the bars); a `reader:swipe` listener calls `this.turn(detail.forward)` when `state === 'ready'`, no panel is open, `!finished` and not `zoomed`.

- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/Reader && vendor/bin/pest tests/Browser/MangaReaderTest.php tests/Browser/WebtoonReaderTest.php tests/Browser/ReaderChromeTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat: comic zoom and swipe, and strip detection from page shape"`

### Task 7.4: NextChapter and the one-tap prompt on every engine

**Files:**
- Create: `app/Reader/NextChapter.php`, `app/Reader/NextChapterTarget.php`, `tests/Feature/Reader/NextChapterTest.php`
- Modify: `R/pages/reader/⚡show.blade.php` (replace the inline `next` computed; render `x-reader.finished` for `pages` too; webtoon link uses the target), `R/components/reader/finished.blade.php`, `resources/js/reader/chrome.js` (`turn`: forward at the end of a comic opens the card)
- Test: `tests/Browser/MangaReaderTest.php`, `tests/Browser/WebtoonReaderTest.php` (existing next-episode test stays green)

**Interfaces:**
- Produces: `NextChapter::for(Media $media, ?ContentSource $source, User $user): ?NextChapterTarget` (uses `Media::series()`, `Series::orderedMedia()->visibleTo($user)`, `SourceChooser::for`). `NextChapterTarget { Media $media; string $label; bool $readable; string $url }`: `label` is the series pivot label ("Volume 3") else the title; `url` is `route('reader.show', ['slug' => ..., 'source' => $chosen->id])` when readable, else `route('stories.show', slug)`.

- [ ] **Step 1: Write the failing tests**

```php
// NextChapterTest.php: a series built with $series->media()->attach($media, ['position' => n, 'label' => 'Volume n'])
test('the next volume follows series order: position, nulls last, then title', function () { /* v1 -> v2; v2 -> v3; a null-position volume is last; last -> null */ });
test('a volume the reader cannot open is skipped for the next one they can', function () { /* readable: v1, v2, v4 (v3 has no file) -> v2 gives v4 with url to reader.show */ });
test('when no later volume is openable the immediate next is returned unreadable, pointing at its story', function () {
    /* expect ->readable false and ->url route('stories.show', v3->slug) */ });
test('the reader\'s own upload counts as readable for them only', function () { /* own upload on v2 -> target readable for owner; for another reader readable=false */ });
test('a mature volume the reader may not see is never the next one', fn () => /* matureStory() in the series, viewer without mature access */);
test('the url opens the source the reader will actually get, an upload before a hosted file', fn () => /* url contains ?source=<upload id> */);
test('a story in no series has no next', fn () => expect(app(NextChapter::class)->for($media, null, $user))->toBeNull());
test('only the next 25 volumes are looked at', fn () => /* 30 volumes, 26..30 readable, 2..26 not -> v1 gives readable=false with v2 */);
test('the label is the series label, else the title', fn () => /* null label -> $media->title */);
```

```php
// MangaReaderTest.php: a 2-volume series (comicSource + a second comicSource attached at positions 1 and 2, labels 'Volume 1', 'Volume 2')
test('the last page of a comic offers the next volume in one tap', function () {
    // open volume 1 at its last page (progress saved at page 5/5); press ArrowLeft (RTL next)
    // assertVisible('[data-reader=finished]') ; assertSeeIn('[data-reader=finished]', 'Volume 2'); click('[data-reader=finished] a[data-reader=next-link]')
    // waitForText('Page 1 of 5'); assertPathContains('/read/'.$second->media->slug)
});
test('the final volume says so and offers the story, not a list', fn () => /* no next-link; link "Back to the story" only */);
test('the card does not show when a comic opens on its last page, only after a further forward move', fn () => /* arrive, assertMissing finished */);
```

- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Reader/NextChapterTest.php` then the browser filter. Expected: FAIL (class missing; no `[data-reader=finished]` for comics).

- [ ] **Step 3: Implement**
  - `NextChapter::for`: pick the first series of `$media` whose ordered list contains it (a source that is the reader's own upload prefers a series where they hold uploads; otherwise first by id). Load `orderedMedia()->visibleTo($user)` ids only, take the next `SCAN = 25` after `$media`, return the first one `SourceChooser::for($user, $candidate)` can open (readable true), else the immediate next (readable false), else null. `$source` is accepted so a later phase can prefer its series; it is used only for the upload preference above.
  - `show` page: `next` computed calls `NextChapter`; `x-reader.finished` is rendered for `epub`, `pdf` and `pages`; the card's primary link carries `data-reader="next-link"` and its text is "Start reading" for a book and "Next volume" for a comic, with the label under it; with no target the card shows only "Back to the story". The webtoon end link uses `$next->url` and keeps `data-reader="next-episode"`.
  - `chrome.js` `turn(forward)`: set `finished = true` when moving forward from the last page for `epub` and `pages`; `onRelocate` still never opens the card on arrival; `pages` is added to the engines the card can show for.

- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/Reader && vendor/bin/pest tests/Browser/MangaReaderTest.php tests/Browser/WebtoonReaderTest.php tests/Browser/EpubReaderTest.php tests/Browser/PdfReaderTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat: one-tap next volume on every reader, ordered by series"`

### Task 7.5: Jurisdiction on reading files and the notice before reading

**Files:**
- Create: `database/migrations/2026_11_27_110000_add_jurisdiction_to_content_sources_table.php`
- Modify: `app/Models/ContentSource.php` (`@property`, `#[Fillable]`), `app/Actions/Reader/VerifyContentSource.php`, `R/pages/admin/content/⚡index.blade.php` (verify dialog), `R/pages/stories/⚡show.blade.php` (notice under the action row), `database/factories/ContentSourceFactory.php` (`jurisdiction` null by default)
- Test: `tests/Feature/Reader/AdminContentVerificationTest.php`, `tests/Feature/Reader/StoryReadActionsTest.php`

**Interfaces:** `VerifyContentSource::handle(ContentSource $source, User $admin, string $license, ?string $rightsHolder, ?string $jurisdiction = null): void` (trimmed, blank becomes null, max 120).

- [ ] **Step 1: Write the failing tests**

```php
test('the jurisdiction is stored trimmed, blank becomes null, and over 120 characters is refused', function () { /* verify with ' the United States ' -> 'the United States'; '' -> null; str_repeat('a', 121) -> ValidationException on jurisdiction */ });
test('the Reading files verify dialog records the jurisdiction', fn () => /* Livewire pages::admin.content.index: set verifying, jurisdiction, call verify */);

test('a hosted public-domain file shows the notice before reading', function (?string $jurisdiction, string $license, string $expected) {
    // hosted source verified+ready, jurisdiction/license as given; reader opens stories.show
    // ->assertSee($expected) and the read-action button is above it in the HTML
})->with([
    'jurisdiction set' => ['the United States', 'Anything', "Public domain in the United States. Check your local laws if you're elsewhere."],
    'no jurisdiction falls back to the license text' => [null, 'Public domain in the United States', "Public domain in the United States. Check your local laws if you're elsewhere."],
]);
test('a licensed file shows its license then the same sentence', fn () => /* 'Creative Commons BY 4.0. Check your local laws if you\'re elsewhere.' */);
test('the reader\'s own upload and a story with nothing to read show no notice', fn () => /* assertDontSee('Check your local laws') */);
```

- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Reader/AdminContentVerificationTest.php tests/Feature/Reader/StoryReadActionsTest.php`. Expected: FAIL (column missing, no notice).
- [ ] **Step 3: Implement.** Migration adds `string('jurisdiction', 120)->nullable()` after `license`. Notice (a `<p class="mt-3 text-meta text-ink-muted">` under the buttons, shown only when `$this->readSource` is hosted, i.e. `access_type` is `PublicDomain` or `Licensed`): sentence one is `__('Public domain in :place.', ['place' => $jurisdiction])` when public domain with a jurisdiction, else `rtrim($license, '.').'.'`; sentence two is `__("Check your local laws if you're elsewhere.")`. The admin dialog gets a `flux:input wire:model="jurisdiction"` labelled "Jurisdiction" with helper "Where this file is in the public domain or licensed, for example the United States. Leave blank if unknown." Add `{{-- TODO(owner): fill jurisdiction on existing Standard Ebooks and Gutenberg rows --}}` beside the input and list it in the ledger.
- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/Reader tests/Feature/Admin && vendor/bin/pest tests/Browser/StoryPageTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat: record jurisdiction on reading files and show it before reading"`

### Task 7.6: Reading-apps help page

**Files:**
- Create: `R/help/reading-apps.blade.php` (landing layout, like Phase 3's `R/legal/*`), `tests/Feature/HelpReadingAppsTest.php`
- Modify: `routes/web.php` (public, outside the auth group), `config/bookflix.php` (`reading_apps`), `R/components/landing/footer.blade.php` (Explore column), `R/pages/stories/⚡show.blade.php` (notice from Task 7.5 gets a link), `tests/Browser/NoHorizontalScrollTest.php` (add the route to the public list)

**Interfaces:** Produces route `help.reading-apps`. `config('bookflix.reading_apps')`: list of `['name', 'url', 'platforms', 'good_for']` in this order and with these URLs only: Readest `https://readest.com` (All platforms, including web; All-round EPUB and PDF reading); Koodo Reader `https://koodoreader.com` (All platforms; Managing a personal EPUB/PDF library); KOReader `https://koreader.rocks` (E-ink devices, Android, Linux; Reading on e-ink devices); Foliate `https://johnfactotum.github.io/foliate/` (Linux; Clean EPUB reading on Linux); Thorium Reader `https://thoriumreader.com` (Windows, macOS, Linux; Accessible EPUB reading); Sumatra PDF `https://sumatrapdfreader.org` (Windows; Lightweight PDF, EPUB and CBZ); calibre `https://calibre-ebook.com` (Windows, macOS, Linux; Organizing and converting a library, "ignore its download plugins").

- [ ] **Step 1: Write the failing tests**

```php
test('the page is public and lists exactly the seven apps in order', function () {
    $this->get(route('help.reading-apps'))->assertOk()->assertSeeInOrder(['Readest', 'Koodo Reader', 'KOReader', 'Foliate', 'Thorium Reader', 'Sumatra PDF', 'calibre']);
});
test('every external link goes to exactly these official hosts and opens safely', function () {
    $html = $this->get(route('help.reading-apps'))->getContent();
    preg_match_all('#<a [^>]*href="(https?://[^"]+)"[^>]*>#', $html, $m);
    $external = collect($m[1])->reject(fn ($url) => str_starts_with($url, config('app.url')))->map(fn ($url) => parse_url($url, PHP_URL_HOST))->unique()->values()->all();
    expect($external)->toBe(['readest.com', 'koodoreader.com', 'koreader.rocks', 'johnfactotum.github.io', 'thoriumreader.com', 'sumatrapdfreader.org', 'calibre-ebook.com']);
    expect($html)->toContain('rel="noopener noreferrer"');
});
test('the landing footer and a hosted story page link to it', fn () => /* get('/') and the stories.show page from Task 7.5 contain route('help.reading-apps') */);
test('the config holds exactly the seven urls above and nothing else', fn () => expect(collect(config('bookflix.reading_apps'))->pluck('url')->all())->toBe(['https://readest.com', 'https://koodoreader.com', 'https://koreader.rocks', 'https://johnfactotum.github.io/foliate/', 'https://thoriumreader.com', 'https://sumatrapdfreader.org', 'https://calibre-ebook.com']));
```

- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/HelpReadingAppsTest.php`. Expected: FAIL (route not defined).
- [ ] **Step 3: Implement.** `Route::view('help/reading-apps', 'help.reading-apps', [...])->name('help.reading-apps');` renders a heading, one plain sentence ("These free apps open the files Bookflix lets you download. Each link goes to the project's own site."), a table (name, platforms, good for, link opening in a new tab with `rel="noopener noreferrer"` and a screen-reader "(opens in a new tab)"), and a line linking `legal.privacy` and `legal.terms`. Footer: add `[route('help.reading-apps'), __('Reading apps')]` to the Explore column (no new column, so the grid is unchanged). Story notice: append `<a href="{{ route('help.reading-apps') }}">See free reading apps</a>` to the Task 7.5 paragraph.
- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/HelpReadingAppsTest.php tests/Feature/LandingTest.php && vendor/bin/pest tests/Browser/NoHorizontalScrollTest.php`. Expected: PASS at 320, 375, 414.
- [ ] **Step 5: Commit** `git commit -m "feat: public help page for free reading apps"`

### Task 7.7: Upload intake: DRM detection, metadata and catalog matching

**Files:**
- Create: `app/Reader/Archives/EpubDrmDetector.php`, `app/Reader/Uploads/UploadMetadata.php`, `app/Reader/Uploads/UploadMetadataReader.php`, `app/Reader/Uploads/UploadMatcher.php`, `tests/Feature/Reader/EpubDrmDetectorTest.php`, `tests/Feature/Reader/UploadMetadataReaderTest.php`, `tests/Feature/Reader/UploadMatcherTest.php`
- Modify: `app/Reader/UploadContentSource.php` (`detectZip`), `tests/Feature/Reader/UploadContentSourceTest.php`

**Interfaces:** the registry signatures above; `UploadMatcher::match(UploadMetadata $metadata, User $user): ?Media`.

- [ ] **Step 1: Write the failing tests**

```php
// EpubDrmDetectorTest.php: build EPUBs with tinyEpub() bytes plus an added META-INF/encryption.xml (helper epubWithEncryption(string $xml))
test('an EPUB with no encryption.xml is not protected', fn () => expect(isProtected(tinyEpub()))->toBeFalse());
test('font obfuscation with the IDPF or Adobe algorithm is not protection', function (string $algorithm) { /* EncryptedData/EncryptionMethod Algorithm=$algorithm -> false */ })
    ->with(['http://www.idpf.org/2008/embedding', 'http://ns.adobe.com/pdf/enc#RC']);
test('any other algorithm is protection', function (string $algorithm) { /* -> true */ })
    ->with(['http://www.w3.org/2001/04/xmlenc#aes128-cbc', 'http://www.w3.org/2001/04/xmlenc#aes256-cbc', 'http://example.com/unknown']);
test('one protected entry among obfuscated fonts is enough', fn () => /* two entries, one aes -> true */);
test('an encryption.xml that is not valid XML counts as protected (fails closed)', fn () => /* '<not-closed' -> true */);

// UploadContentSourceTest.php
test('a DRM-protected EPUB is refused with a plain explanation and nothing is stored', function () {
    $e = upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf(epubWithEncryption(aes128()), 'locked.epub')));
    expect($e->errors()['file'][0])->toBe("This book is copy-protected, so Bookflix can't open it. Add a DRM-free copy instead.")
        ->and($e->errors()['file'][0])->not->toMatch('/remov|decrypt|bypass|crack|strip/i')
        ->and(ContentSource::count())->toBe(0);
    Storage::disk(ContentSource::disk())->assertDirectoryEmpty('content');   // or assertMissing on the user directory
});
test('an EPUB with font obfuscation uploads normally', fn () => /* IDPF algorithm -> accepted */);
```

```php
// UploadMetadataReaderTest.php
test('filename patterns', function (string $name, ?string $series, ?string $volume, ?string $chapter, ?string $title) {
    $m = app(UploadMetadataReader::class)->read(tempPdf(), ContentFormat::Pdf, $name);
    expect([$m->series, $m->volume, $m->chapter, $m->title])->toBe([$series, $volume, $chapter, $title]);
})->with([
    'volume and chapter' => ['Tide Cycle v02 c013.cbz', 'Tide Cycle', '2', '13', null],
    'volume word and dot' => ['One Harbour Vol. 05.cbz', 'One Harbour', '5', null, null],
    'decimal volume' => ['Tide Cycle v02.5.cbz', 'Tide Cycle', '2.5', null, null],
    'chapter word' => ['Night Shift Chapter 12.cbz', 'Night Shift', null, '12', null],
    'issue number, tags removed' => ['Saga of Lights #7 (2019) (Digital).cbz', 'Saga of Lights', null, '7', null],
    'trailing number is a volume' => ['Berserk Tides 03 [Group].cbz', 'Berserk Tides', '3', null, null],
    'underscores' => ['Tide_Cycle_v2.cbz', 'Tide Cycle', '2', null, null],
    'no pattern: the stem is the title' => ['The Open Book.epub', null, null, null, 'The Open Book'],
    'a path is dropped first' => ['../../etc/passwd v1.pdf', 'passwd', '1', null, null],
]);
test('ComicInfo.xml fills series, volume, number as chapter, title and writers, and beats the filename', function () {
    // CBZ zip with 01.png + ComicInfo.xml '<ComicInfo><Series>Saga of Lights</Series><Volume>2</Volume><Number>13</Number><Title>Thirteen</Title><Writer>Ada Example, Ben Sample</Writer></ComicInfo>'
    // read(path, Cbz, 'whatever v9.cbz') -> series 'Saga of Lights', volume '2', chapter '13', title 'Thirteen', creators ['Ada Example', 'Ben Sample']
});
test('EPUB metadata: dc:title, dc:creator, calibre:series and series_index, and EPUB3 belongs-to-collection with group-position', fn () => /* two EPUB fixtures */);
test('FB2 metadata: book-title, author, sequence name and number', fn () => /* tinyFb2 variant */);
test('a missing or hostile ComicInfo is ignored, never an exception, and entities are not expanded', fn () => /* <!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]> -> series null, no file contents */);

// UploadMatcherTest.php
test('series plus volume matches the catalog volume at that position', fn () => /* Series 'Tide Cycle' with media at positions 1..3 -> v2 */);
test('chapter is used when there is no volume', fn () => /* position == chapter */);
test('two series with the same title are ambiguous and match nothing', fn () => expect(...)->toBeNull());
test('a title alone matches only one exact, case-insensitive title', fn () => /* two media 'The Open Book' -> null; one -> it */);
test('a story the reader may not see never matches', fn () => /* mature, no opt-in -> null */);
test('nothing known matches nothing', fn () => expect(match(new UploadMetadata(null, null, null, null, [])))->toBeNull());
```

- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Reader/EpubDrmDetectorTest.php tests/Feature/Reader/UploadMetadataReaderTest.php tests/Feature/Reader/UploadMatcherTest.php`. Expected: FAIL (classes missing).

- [ ] **Step 3: Implement**
  - `EpubDrmDetector::isProtected`: open with `ZipArchive` after the guard's inventory; read `META-INF/encryption.xml` through `ZipGuard::readEntry($zip, $index, 1048576)`; parse with `DOMDocument` flags `LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING`, never `LIBXML_NOENT`; any `EncryptionMethod/@Algorithm` not in `['http://www.idpf.org/2008/embedding', 'http://ns.adobe.com/pdf/enc#RC']` returns true; parse failure returns true (P7-R11); no file returns false. `UploadContentSource::detectZip` runs it after `EpubInspector::check` and refuses with the message above.
  - `UploadMetadata`: `final readonly class`, constructor promotion, no logic. Numbers are strings with leading zeros stripped and any decimal kept (`'013'` becomes `'13'`).
  - `UploadMetadataReader::read`: embedded source first (CBZ `ComicInfo.xml`: Series, Volume, Number as chapter, Title, Writer split on comma; EPUB OPF found through `container.xml`: `dc:title`, every `dc:creator`, `meta[name=calibre:series]` with `calibre:series_index` as volume, `meta[property=belongs-to-collection]` with its `refines` `group-position`; FB2: `book-title`, `author` names, `sequence@name` and `@number`; MOBI and PDF: none), then the filename fills any field still null. Filename: take the last path part, drop the extension (`cbz cbr epub pdf mobi azw3 fb2`), turn `_` into spaces, remove every `(...)` and `[...]` group, collapse spaces, then the first matching pattern wins, case-insensitive: (1) `^(?<s>.+?)\s+(?:v|vol|volume)\.?\s*(?<v>\d+(?:\.\d+)?)\s+(?:c|ch|chapter)\.?\s*(?<c>\d+(?:\.\d+)?)`; (2) `^(?<s>.+?)\s+(?:v|vol|volume)\.?\s*(?<v>\d+(?:\.\d+)?)`; (3) `^(?<s>.+?)\s+(?:c|ch|chapter)\.?\s*(?<c>\d+(?:\.\d+)?)`; (4) `^(?<s>.+?)\s+#(?<c>\d+)`; (5) `^(?<s>.+?)\s+(?<v>\d{1,3})$`; none: `title` is the stem. XML is read with the same flags as the detector and every read is capped at 1 MB.
  - `UploadMatcher::match`: if series and (volume or chapter): series rows with `lower(title) = lower(series)` (escape LIKE input; use `whereRaw('lower(title) = ?')`); exactly one series, then the media attached with `position = (float)(volume ?? chapter)` and visible to the user, exactly one result. Otherwise title (`title ?? series`): exactly one visible media with that lower-cased title. Anything else returns null.

- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/Reader`. Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat: refuse DRM EPUBs, read upload metadata and match uploads to the catalog"`

### Task 7.8: Bulk upload, fix-a-match, series grouping, quota in Settings, terms line

**Files:**
- Create: `app/Reader/Uploads/UploadQuota.php`, `app/Actions/Reader/RematchUpload.php`, `R/components/settings/upload-quota.blade.php`, `resources/js/upload.js` (Alpine data `bulkUpload`, registered in `resources/js/app.js` the way `readerChrome` is), `tests/Feature/Reader/BulkUploadTest.php`, `tests/Feature/Reader/RematchUploadTest.php`, `tests/Feature/Settings/UploadQuotaCardTest.php`
- Modify: `R/pages/library/⚡upload.blade.php`, `app/Reader/UploadContentSource.php` (use `UploadQuota`), `R/pages/settings/⚡index.blade.php` (summary column), `config/bookflix.php` (`per_hour` 50, `max_batch` 25), `config/livewire.php` (`'disk' => env('LIVEWIRE_TEMP_UPLOAD_DISK', 'local')`), `.env.example`, `tests/Feature/Reader/UploadPageTest.php`, `tests/Browser/UploadPageTest.php`, `app/Policies/ContentSourcePolicy.php` only if `update` is missing

**Interfaces:**
- Produces: `UploadQuota::used(User): int` (sum of the user's `user_owned` `byte_size`), `UploadQuota::limit(): int` (bytes, from `quota_mb`); `RematchUpload::handle(User $user, ContentSource $source, Media $media): void` (owner only, `Media::visibleTo`, reading progress stays on the old story). Page API: `$incoming` (one `TemporaryUploadedFile`), `receive()` (reads metadata, runs `UploadMatcher`, appends a row `{id, name, size, story: slug|'' , status: queued|added|error, error}` to `$rows`, moves the file to `$queued[$id]`), `setRowStory(string $id, string $slug)`, `removeRow(string $id)`, `save()` (loops the rows, one `UploadContentSource::handle` each, a `ValidationException` becomes that row's error and the loop continues), `rematch(int $id, string $slug)`, computed `groups` (the reader's files grouped by the first series of their story, ordered by series title then `media_series.position` nulls last; files in no series under "Other files").

- [ ] **Step 1: Write the failing tests**

```php
// BulkUploadTest.php (Livewire::actingAs($reader)->test('pages::library.upload'); fixtures from UploadContentSourceTest helpers)
test('a file with a ComicInfo series is matched to its catalog volume and shows the story picked', function () { /* receive() -> rows[0]['story'] === $v2->slug */ });
test('a file that matches nothing is queued with no story and Add is refused until one is chosen', function () {
    /* save() -> row error "Choose the story this file belongs to." and nothing stored; setRowStory then save -> added */ });
test('one bad file does not stop the rest of the batch', function () {
    /* three rows: good PDF, DRM EPUB, good CBZ -> save(): rows 1 and 3 status added, row 2 status error with the DRM message; ContentSource::count() === 2 */ });
test('the quota holds mid-batch: files before the limit are added, the file that would pass it errors, later ones too', function () {
    config(['bookflix.reader.upload.quota_mb' => 0.0003]); /* each ~200 byte PDFs: second row error 'This file would take you over your' */ });
test('the confirmation of own copy is required once for the batch', fn () => /* own=false -> error own.accepted, nothing stored */);
test('a selection over max_batch is refused', fn () => /* config max_batch 2, receive 3 -> third row error "You can add up to 2 files at a time." */);
test('Your files lists volumes in reading order inside their series', function () { /* uploads for v3, v1, v2 of 'Tide Cycle' + one loose -> groups[0] title 'Tide Cycle', order v1 v2 v3; loose under 'Other files' */ });
test('the page states the terms and the takedown contact', function () {
    config(['bookflix.legal.contact_email' => 'takedown@example.test']);
    /* assertSee('Only add files you have the right to use.')->assertSeeHtml('mailto:takedown@example.test') ; with null email: assertDontSee('mailto:')->assertSeeHtml(e(route('legal.terms'))) and the TODO(owner) comment is in the source */ });
test('the file limits line lists the new formats', fn () => /* assertSee('MOBI up to 100 MB') */);

// RematchUploadTest.php
test('an owner moves an upload to another visible story and their reading place stays on the old one', fn () => /* */);
test('another reader cannot rematch it (403) and a story they may not see is refused', fn () => /* */);

// UploadQuotaCardTest.php
test('Settings shows the upload quota with the same numbers as the upload page', function () {
    /* 38 MB used of the default 2048 MB -> get(route('settings.edit'))->assertSee('38 MB of 2 GB used')->assertSee('Your files')->assertSeeHtml(e(route('library.upload'))) ; an admin (no reader side) does not see the card */ });
test('livewire temporary uploads stay on a private disk by default', fn () => /* config('livewire.temporary_file_upload.disk') is 'local' and its url is null */);
```

```php
// tests/Browser/UploadPageTest.php: extend the existing file (its FoldUploadedFilesShim and tinyPdf() fixture)
test('two PDFs go through one by one with their own progress and results', function () {
    // attach two generated ASCII PDFs to the native input (multiple); assert two rows [data-upload-row], each with role=progressbar reaching 100,
    // pick a story for the unmatched one, click Upload, assert both appear under Your files and the rows read "Added".
    // run at 1440x900 and 375x812; assert scrollWidth <= innerWidth.
});
```

- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Reader/BulkUploadTest.php tests/Feature/Reader/RematchUploadTest.php tests/Feature/Settings/UploadQuotaCardTest.php`. Expected: FAIL (no `receive`, no rows).

- [ ] **Step 3: Implement**
  - Page: replace the single `$file`/`$story` form with the row flow above. `bulkUpload` (Alpine) loops the picked or dropped `File`s (at most `max_batch`), and for each calls `$wire.upload('incoming', file, finish, error, progress)` then `$wire.receive()`, keeping per-file `progress` and `error` in Alpine state so a row shows its own `role="progressbar"` (`data-upload-row`). Each queued row shows the detected title or series, the story combobox (existing `x-combobox`, `search` property per active row) to fix a match, and a remove button. Existing single-file Feature tests are rewritten onto this flow, keeping every assertion's intent (access, hidden-story refusal, quota text, delete, Story removed row).
  - "Your files" renders `groups`; each file row gets a "Change story" action opening the combobox and calling `rematch`. `RematchUpload` checks `ContentSourcePolicy` ownership (`abort 403`) and visibility.
  - Terms line under the confirm checkbox: `__('Only add files you have the right to use. Rights holders can ask for a file to be removed:')` followed by `<a href="mailto:{{ $email }}">` when `config('bookflix.legal.contact_email')` is set, else `<a href="{{ route('legal.terms') }}">` and `{{-- TODO(owner): takedown contact address (BOOKFLIX_LEGAL_CONTACT_EMAIL) --}}`. The DRM attestation sentence stays ("This is my own copy, and it has no DRM.").
  - `UploadQuota` is used by `UploadContentSource` (replacing its inline `sum`) and by the page's `used`/`quota` computeds. `upload-quota.blade.php` renders inside `x-settings.summary-card` (props per Baseline step 7): "38 MB of 2 GB used", the meter markup copied from the upload page, and a "Manage your files" link to `library.upload`; included in the summary column for readers only (`@can('use-reader-side')`).
  - `.env.example`: `LIVEWIRE_TEMP_UPLOAD_DISK=local` with a comment "s3 needs league/flysystem-aws-s3-v3, not installed: Needs decision". Record S3 direct upload and resumable upload as "Needs decision" in the ledger.

- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/Reader tests/Feature/Settings tests/Feature/Admin && vendor/bin/pest tests/Browser/UploadPageTest.php tests/Browser/AccessibilityTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat: bulk upload with matching, fix-a-match, series grouping and quota in Settings"`

### Task 7.9: Upload privacy tests (Part I) and no import-from-URL

**Files:**
- Create: `tests/Feature/Reader/UploadPrivacyTest.php`
- Test only; if a test finds a leak, fix it in the owning file and say so in the ledger.

Already covered (grep to confirm, record in ledger): per-user quota (`UploadContentSourceTest` "the per-user quota holds"), owner-only delete (`UploadPageTest` "only the owner can delete a file"), another reader's own signed URL refused (`UploadContentSourceTest` "only the owner can stream the upload"), the hosted signed-URL cases in `StreamContentTest` and `PageRouteTest`. Missing and added here:

- [ ] **Step 1: Write the failing tests**

```php
beforeEach(function () {
    Storage::fake(ContentSource::disk());
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    $this->media = Media::factory()->novel()->create();
    $this->upload = ContentSource::factory()->for($this->media)->userOwned($this->owner)->ready()->create(['format' => ContentFormat::Epub]);
});

test('another reader cannot open an upload through the reader URL, even naming its id', function () {
    $this->actingAs($this->other)->get(route('reader.show', ['slug' => $this->media->slug, 'source' => $this->upload->id]))
        ->assertRedirect(route('stories.show', $this->media->slug));   // nothing to read for them
});

test('the owner\'s signed stream URL used by someone else is refused, and by nobody signed in goes to sign in', function () {
    $url = ContentUrl::for($this->owner, $this->upload);
    $this->actingAs($this->other)->get($url)->assertForbidden();
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
});

test('the owner\'s signed comic page URL used by someone else is refused', fn () => /* CbzPageUrl::for($owner, $cbzUpload, 1) as $other -> 403 */);

test('a story that only another reader uploaded to shows no Read button and no link out', function () {
    $this->actingAs($this->other);
    Livewire::test('pages::stories.show', ['slug' => $this->media->slug])->assertDontSeeHtml('data-test="read-action"');
});

test('the locked source id on the reader page cannot be pointed at another reader\'s upload', function () {
    $hosted = ContentSource::factory()->for(Media::factory()->novel()->create())->publicDomain()->verified()->ready()->create();
    $this->actingAs($this->other);
    expect(fn () => Livewire::test('pages::reader.show', ['slug' => $hosted->media->slug])->set('sourceId', $this->upload->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('another reader cannot rematch or delete an upload through the upload page', function () {
    Livewire::actingAs($this->other)->test('pages::library.upload')->call('rematch', $this->upload->id, $this->media->slug)->assertForbidden();
    Livewire::actingAs($this->other)->test('pages::library.upload')->call('delete', $this->upload->id)->assertForbidden();
    expect($this->upload->fresh())->not->toBeNull();
});

test('there is no way to import a file from a web address', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri().' '.$r->getName());
    expect($routes->filter(fn (string $line) => preg_match('#(library|upload|reader|read|settings)\S*\s?.*(import|from-url|fetch|remote|download-url)#i', $line))->all())->toBe([]);
    $html = Livewire::actingAs($this->owner)->test('pages::library.upload')->html();
    expect($html)->not->toContain('type="url"')->not->toMatch('/import from (a )?(url|link|address)/i');
});
```

- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Reader/UploadPrivacyTest.php`. Expected: all but a regression PASS right away except `rematch` before Task 7.8 (order: this task runs after it). A FAIL here is a real leak: fix it before continuing.
- [ ] **Step 3: Implement** only what a failing test proves is missing, in the owning file.
- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/Reader tests/Feature/Security tests/Feature/Access`. Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "test: another reader can never reach an upload; no import from a URL"`

### Task 7.10: Guests can read verified public-domain books

**Files:**
- Modify: `app/Reader/ContentAccessGate.php`, `app/Reader/ContentUrl.php`, `app/Reader/SourceChooser.php` and `app/Reader/NextChapter.php` (user parameter becomes `?User`), `app/Http/Controllers/Reader/StreamContentController.php`, `app/Http/Controllers/Reader/PageController.php`, `app/Providers/AppServiceProvider.php` (`reader-stream` limiter), `routes/reader.php`, `config/bookflix.php` (`reader.guest_per_minute` 120), `R/pages/reader/⚡show.blade.php`, `resources/js/reader/chrome.js`, `resources/js/reader/progress.js`, `R/legal/sections/*` (the Phase 3 uploads or reading section; create `R/legal/sections/reading.blade.php` if none fits and include it in both legal pages)
- Test: `tests/Feature/Reader/GuestReadingTest.php`, `tests/Browser/GuestReaderTest.php`

**Interfaces:**
- Produces: `ContentAccessGate::allows(?User $user, ContentSource $source): bool` (a null user is allowed only the guest rule in P7-R13; non-null behaviour unchanged, including `ContentAccessGate::preview`). `ContentUrl::for(?User $user, ContentSource $source): string` (user id in `user` for readers; for guests `guest` = session hash). `CbzPageUrl::for(?User, ...)` the same. `NextChapter::for(Media, ?ContentSource, ?User): ?NextChapterTarget`.
- Routes: `reader.stream`, `reader.page` and `reader.show` drop `auth`, `verified`, `reader` for guests: use a `web`-only group whose controllers call the gate; signed-in admins are still refused by `EnsureReaderAccount`. `reader.progress` keeps `auth, verified, reader`.

- [ ] **Step 1: Write the failing tests** (`GuestReadingTest.php`, `Storage::fake(ContentSource::disk())`, no `actingAs`)

```php
test('a guest reads a verified public-domain EPUB: page opens and the signed stream serves the bytes', function () {
    $source = epubSource();   // tinyEpub(), publicDomain, verified, ready
    $page = $this->get(route('reader.show', $source->media->slug))->assertOk()->assertSee('data-guest="1"', false);
    preg_match('/data-stream-url="([^"]+)"/', $page->getContent(), $m);
    $this->get(html_entity_decode($m[1]))->assertOk()->assertHeader('Content-Type', 'application/epub+zip');
});
test('a guest reads a verified public-domain CBZ page by page', function () {
    $source = comicSource('manga', 3);
    $url = ContentUrl::... /* CbzPageUrl::for(null, $source, 1) */;
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
});
test('a guest is refused everything else', function (string $case, int|string $expected) { /* build the source per $case */ })
    ->with([
        'a reader upload' => ['upload', 403], 'an unverified public-domain source' => ['unverified', 403],
        'a licensed source' => ['licensed', 403], 'an external link' => ['link', 403],
        'a mature title' => ['mature', 403],
    ]);   // stream route: 403 for each, using a URL signed with ContentUrl::for(null, $source)
test('the reader page for those same sources sends a guest to sign in', fn () => /* get(route('reader.show', slug)) assertRedirect(route('login')) for each case; mature: 404 like today */);
test('a guest URL replayed from another session is refused', function () {
    $source = epubSource(); $url = ContentUrl::for(null, $source);   // signed while session A is active
    $this->flushSession()->withSession(['_token' => 'other'])->get($url)->assertForbidden();
});
test('a guest URL is refused to a signed-in reader and a reader URL is refused to a guest', fn () => /* both 403 */);
test('a guest writes no reading_progress rows and reader.progress still needs sign in', function () {
    $source = epubSource();
    $this->get(route('reader.show', $source->media->slug))->assertOk();
    $this->postJson(route('reader.progress', $source->id), ['percent' => 40])->assertUnauthorized();   // or redirect to login for non-JSON: assert the status the auth middleware gives
    expect(ReadingProgress::count())->toBe(0);
});
test('the guest throttle answers 429 on stream and page', function () {
    config(['bookflix.reader.guest_per_minute' => 2]); /* three requests to each route as a guest -> third is 429 */ });
test('a signed-in reader still gets a user-bound URL and server progress (regression)', function () {
    $user = User::factory()->create(); $source = epubSource();
    expect(ContentUrl::for($user, $source))->toContain('user='.$user->id)->not->toContain('guest=');
    $this->actingAs($user)->postJson(route('reader.progress', $source->id), ['percent' => 40])->assertSuccessful();
    expect(ReadingProgress::where('user_id', $user->id)->count())->toBe(1);
});
test('a guest sees the jurisdiction notice and the sign-in note before and while reading', fn () => /* stories.show (public from Phase 6): assertSee("Check your local laws if you're elsewhere."); reader page assertSee('Sign in to keep your place on every device') */);
test('a guest gets the next-volume prompt only when the next item is also guest-readable', fn () => /* NextChapter::for($v1, null, null): v2 public-domain verified -> readable; v2 licensed or upload-only -> readable false, url stories.show */);
test('the legal pages say guests read without an account and position stays in the browser', fn () => /* get(route('legal.terms')/privacy) assertSee('reading position is stored only in your browser') */);
```

Browser (`GuestReaderTest.php`, follow `EpubReaderTest.php`, no `actingAs`): a guest opens a `tinyEpub()` book, turns to chapter 2, `assertScript('JSON.parse(localStorage.getItem("bookflix.guest.progress.'.$source->id.'")).percent > 0', true)`, reloads and resumes at "Chapter 2 of 2"; `assertSee('Sign in to keep your place on every device')`; no `POST` to `/progress` is made (`read_network`-style check: `assertScript('performance.getEntriesByName(...)')` filtered to `/progress` is empty); `assertNoJavaScriptErrors()`.

- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Reader/GuestReadingTest.php`. Expected: FAIL (guests are redirected to login, `ContentUrl::for(null, ...)` is a type error).

- [ ] **Step 3: Implement**
  - Gate: `if ($user === null) return $this->allowsGuest($source)`; `allowsGuest` returns true only when `access_type === PublicDomain`, `isVerified()`, `hasFile`, a media that exists and `! $media->isGatedFor(null)`. Everything else false. Place it before the deactivated-user check; keep the rest as is. Add a unit-style test row for each refusal in `ContentAccessGateTest.php`.
  - `ContentUrl::for`/`CbzPageUrl::for`: guests get `['source' => id, 'guest' => hash_hmac('sha256', session()->getId(), config('app.key'))]` after `session()->put('reader.guest', true)` so the cookie persists; same 10-minute TTL, relative signature. Both controllers: a signed-in user must match `user` as today; a guest must have no `user` value and `hash_equals` its `guest` value against the current session's hash; anything else 403. Then ask the gate (`allows($request->user(), $source)`).
  - Limiter `reader-stream`: guests `Limit::perMinute((int) config('bookflix.reader.guest_per_minute'))->by('guest:'.$request->ip())`; signed-in unchanged (600 per minute).
  - `routes/reader.php`: stream, page and show lose `auth, verified, reader` (the controllers and `mount` use the gate; `reader.show` redirects guests to `login` when `SourceChooser::for(null, ...)` finds nothing and a signed-in admin still gets the reader middleware answer). Add `reader` only where a user exists: keep `EnsureReaderAccount` on the group (it passes guests).
  - `⚡show.blade.php`: `auth()->user()` calls become nullable; `data-guest="1"` on the engine container; `resume()` returns no server progress for guests; a quiet `<p data-reader="guest-note" class="text-meta text-ink-muted">` "Sign in to keep your place on every device" in the bottom area for guests. `readable()` re-checks the gate with the nullable user.
  - `chrome.js` / `progress.js`: when `data-guest` is present, `startProgress` writes `{ locator, percent }` to `bookflix.guest.progress.<sourceId>` (try/catch, like the other storage) instead of POSTing, and `start()` copies that value into `container.dataset.resumeLocator` and `resumePercent` before loading the engine.
  - Legal: add the sentence "You can read public-domain books on Bookflix without an account. Your reading position is stored only in your browser." (key text `reading position is stored only in your browser` must appear verbatim, lower-case "your") to the Phase 3 section, so terms and privacy both show it.

- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/Reader tests/Feature/Security tests/Feature/Access && vendor/bin/pest tests/Browser/GuestReaderTest.php tests/Browser/EpubReaderTest.php tests/Browser/MangaReaderTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat: guests can read verified public-domain books, with browser-only progress"`

## Phase exit

- `composer test` green (Pint, PHPStan level 7, Unit and Feature). Run each touched browser file: `vendor/bin/pest tests/Browser/BookFormatsReaderTest.php tests/Browser/ReaderChromeTest.php tests/Browser/EpubReaderTest.php tests/Browser/MangaReaderTest.php tests/Browser/WebtoonReaderTest.php tests/Browser/PdfReaderTest.php tests/Browser/UploadPageTest.php tests/Browser/NoHorizontalScrollTest.php tests/Browser/AccessibilityTest.php tests/Browser/StoryPageTest.php tests/Browser/GuestReaderTest.php`. Prefix with `env -u AI_AGENT -u CLAUDECODE`.
- `npm run build` once to prove the foliate sandbox plugin still matches and the new `upload.js` bundles.
- Ledger holds: guest-gate review notes, baseline findings (steps 1 to 8), the Needs-decision items (S3 temporary uploads and the Flysystem package, resumable uploads, `.fbz`, MOBI render if skipped), the TODO(owner) list (takedown contact, jurisdiction backfill), conflict scan rows for 7.2 and 7.3 and 7.4 (all three edit `chrome.js`) and 7.5 and 7.6 (both edit `⚡show.blade.php` and the notice).
- Whole-branch review on the most capable model (specific asks: Review Focus items 1 to 5), then one fix wave. Open the PR per Part G and D2-24 (branch from Phase 6's tip while it is open; rebase onto `origin/main` first). Never merge.
- **PR body: the Part I report table**, one row per brief item (Item, Status, Files):

| Item | Status | Files |
|---|---|---|
| F1 foliate-js identity, licence, pin | Already OK (1.0.1, MIT, lockfile pin) | ledger |
| F1 MOBI/AZW3 | Fixed | `ContentFormat`, `MobiInspector`, `UploadContentSource`, `epub.js` |
| F1 FB2 | Fixed (`.fbz` Needs decision) | same |
| F1 light theme, margins | Fixed | `epub.js`, `chrome.js`, `settings-panel`, `app.css` |
| F1 line height, font size | Already OK | `chrome.js` |
| F1 keyboard navigation, reduced motion | Already OK | `chrome.js`, tests |
| F1 swipe | Fixed for comics; books Already OK (foliate native) or fixed per Task 7.2 | `pages.js`, `chrome.js` |
| F1 comics zoom (pinch, double-tap) | Fixed (paged; strip uses browser zoom) | `pages.js` |
| F1 tap zones, arrow keys | Already OK | `chrome.js` |
| F1 webtoon detection | Fixed (type was OK; image ratio added) | `WebtoonDetector`, `⚡show` |
| F1 next chapter or volume prompt | Fixed | `NextChapter`, `finished`, `chrome.js` |
| F1 CBR conversion | Already OK | `ConvertCbrToCbz` |
| F1 DjVu | Not applicable | none |
| F1 jurisdiction and notice | Fixed | migration, `VerifyContentSource`, admin dialog, story page |
| F2 reading-apps help page | Fixed | route, view, config, footer |
| F6 DRM detection | Fixed | `EpubDrmDetector`, `UploadContentSource` |
| F6 ComicInfo, EPUB, filename metadata | Fixed | `UploadMetadataReader` |
| F6 matching, series grouping, fix a match | Fixed | `UploadMatcher`, `RematchUpload`, upload page |
| F6 bulk upload, per-file progress and errors | Fixed | upload page, `upload.js` |
| F6 direct-to-S3, resumable uploads | Needs decision | `config/livewire.php`, `.env.example` |
| F6 quota in Settings, per-file limits | Fixed (limits Already OK) | `UploadQuota`, settings card |
| F6 private always | Already OK, tests added | `UploadPrivacyTest` |
| F6 terms line and takedown contact | Fixed (address `TODO(owner)`) | upload page |
| F6 no import from URL | Already OK, test added | `UploadPrivacyTest` |
| Guest reading of verified public-domain books (stream, page, reader; session-bound URLs, throttle, browser-only progress) | Fixed | `ContentAccessGate`, `ContentUrl`, reader routes, `progress.js`, legal sections |
| Part I: uploads privacy, DRM, quota, next-chapter tests | Fixed | tests listed above |
