# Handoff: wrong media types on localhost

**For:** a Claude Code session running on the user's Mac, in the local `bookflix` folder.
**From:** the cloud session, 2026-10-29.
**Problem (user):** "The localhost version of the system does not correctly identify the type of entertainment book." Earlier example: novels classified as comic books.

---

## 1. What "type" means here

Every story has one `media_type` (table `media_types`, column `media.media_type_id`): `novel`, `light-novel`, `manga`, `manhwa`, `manhua`, `webcomic`, `comic-book`, `graphic-novel`, plus the picture-book and visual-novel families. The story page, the filters and Dewey classification all depend on it.

## 2. State of `main` (all merged 2026-10-29)

| PR | What it changed |
|---|---|
| #11 | Catalog expansion: Comic Vine, Metron, Hardcover, Open Library trending, MangaDex, MangaUpdates, Wikidata and Wikipedia; `media_metrics` |
| #14 | `MediaTypeClassifier` (the fix for "novels as comics"), `bookflix:reclassify`, the taxonomy tree |
| #15 | Genre-tree filters and the mature gate |
| #12, #13 | MangaDex covers, landing page (not related) |
| #16 (open) | Landing showcase behind the mature gate (not related) |

Phase 5 (personal library) is being built in the cloud on branch `phase-5-library`. **Don't touch that branch** or its files (library, collections, ratings, onboarding).

## 3. How a type is decided (code map)

- `app/Catalog/Classification/MediaTypeClassifier.php` is pure. It scores four families (prose, comic, picture, visual), picks the strongest, then the form inside it (`proseForm()`, `comicForm()`).
  - **Weights:** the provider's explicit format 12, a title marker 10, light-novel imprint 7, comic or manga publisher 4, prose publisher 3, "comics" subject 3.5, "graphic novels" subject 1.5 (+1.5 at 24–320 pages), then size evidence and a per-provider prior.
  - **Defaults:** prose wins ties. `comicForm()` defaults ties to `comic-book` for Comic Vine and Metron, and to `graphic-novel` for everyone else.
  - Every verdict carries `reasons`.
- `app/Catalog/Classification/ClassificationSignals.php` holds the inputs. `PublisherCatalog.php` holds the publisher and imprint lists; names are matched after `Str::ascii` + lowercase + punctuation stripped (`phrase()`).
- Providers call it from `normalize()` through `app/Catalog/Providers/ClassifiesMediaType.php`. Open Library passes title, description, subjects, publishers from all editions, page count and language; see `OpenLibraryProvider::normalize()` around line 270.
- `app/Catalog/Ingestion/IngestionPipeline.php::import()`:
  - An unchanged payload hash is **skipped**, so the classifier never runs again on old records unless the hashes are cleared.
  - A new record that the `Deduplicator` matches to an existing story is **merged** into it.
- `app/Catalog/Ingestion/Enricher.php::apply()`: **only the story's primary provider** (`media.primary_external_source_id`, set at creation and kept through merges) may change `media_type_id`, and never when an admin locked it (`locked_fields`). A prose record merged into a story first created by Comic Vine keeps Comic Vine's type.
- `app/Catalog/Classification/Reclassifier.php` and `bookflix:reclassify` re-run the classifier on **stored** data:
  - They skip AniList, MangaDex and MangaUpdates (`FORMAT_PROVIDERS`), locked types, and verdicts below `--min-confidence` (0.6).
  - Stored data lacks the provider's raw format field, so for Open Library, Comic Vine, Metron and Hardcover the real fix is `--resync --source=X`, which clears the payload hashes, followed by `bookflix:sync X --full`.
- Tests: `tests/Unit/Catalog/MediaTypeClassifierTest.php` (103 cases, data-driven), plus the provider tests in `tests/Feature/Catalog/*ProviderTest.php`.

## 4. What the cloud found (live Open Library, 2026-10-29)

20 well-known works were fetched with `OpenLibraryProvider::find()` and passed through `normalize()`; the script is in §7.

**Correct:** Dune, The Hobbit, Harry Potter, Frankenstein and Diary of a Wimpy Kid (novel); Watchmen, Maus, Persepolis, Saga, The Killing Joke, The Sandman and Bone (graphic-novel); Berserk, One Piece, Death Note and Naruto (manga).

**Wrong (real classifier bugs):**

| Work (OL id) | Got | Should be | Why |
|---|---|---|---|
| Attack on Titan (`OL17384069W`) | graphic-novel | manga | Publisher "Kodansha Comics" counts as manga publisher +3 for manga, but "graphic novels" subject +1 and size +2 put graphic-novel at about 3. The tie goes to the `graphic-novel` default in `comicForm()`. Subjects "Comics & graphic novels, manga" and "Comic books, strips, etc--japan" carry no manga weight, because only exact phrases match. |
| Sword Art Online (`OL17283107W`) | novel | light-novel | Publisher `台灣角川` (Taiwan Kadokawa) becomes "tai wan jiao chuan" after `Str::ascii`, so it never matches the Kadokawa imprints. CJK publisher names aren't handled. |
| Heartstopper (`OL20354498W`) | novel | graphic-novel | Prose publisher (Hodder Children's) +3 beats a lone "graphic novels" subject (1.5, no page count). |
| Solo Leveling (`OL19924210W`) | light-novel | debatable | It is the Korean web novel (publisher "Webnovel"); light-novel is acceptable. Low priority. |

**Biggest finding: nonfiction is labelled "novel".** A real `bookflix:sync openlibrary --full` page in the cloud brought in 20 trending works, and all 20 became `novel`. Among them are Atomic Habits, Rich Dad Poor Dad, The 48 Laws of Power, How to Win Friends and Influence People, The Psychology of Money, Think and Grow Rich, The 7 Habits of Highly Effective People, Can't Hurt Me and The Subtle Art of Not Giving a F*ck. The verdict for Atomic Habits is `novel`, confidence 0.66, reasons "only prose credits; a book catalogue".

- **Cause:** the ten media types are all story formats (`novel`, `light-novel`, `comic-book`, `graphic-novel`, `manga`, `manhwa`, `manhua`, `webcomic`, `picture-book`, `visual-novel`). The classifier has no nonfiction outcome, so any prose with no other evidence defaults to `novel`.
- **A strong signal goes unused:** Open Library editions carry a Dewey class (`OpenLibraryProvider::deweyCode()`, stored as `media_external_ids.dewey_code`). The classifier never sees it. In the cloud sample:
  - nonfiction: Atomic Habits 155.24, Rich Dad Poor Dad 332.024, The 48 Laws of Power 303.3, How to Win Friends 158.1, Think and Grow Rich 650.1
  - fiction: Harry Potter 823.92, The Hunger Games 813.6, O Alquimista 869.3
  - 8 of the 20 had no code at all.
- **This needs the user's decision before coding** (§8 step 0).

**Not reproducible in the cloud:** Comic Vine, Metron and Hardcover need API keys that exist only in the Mac's `.env`. Those three, plus whatever the Mac's database holds, are the main unknown. That's why this needs to run locally.

## 5. Likely causes on the Mac, most likely first

1. **Nonfiction defaults to `novel`** (§4), most likely what the user sees in Open Library and Hardcover books.
2. **Old data:** rows imported before #14 keep their old type until `--resync` + `sync --full` runs for that source, or the syncs haven't finished. Check `ingestion_runs` (§6).
3. **Primary-provider rule:** a novel first created by Comic Vine or Metron, whose prior is comic-book, or merged into one, keeps that type. Only that provider's re-sync, or an admin edit, can change it.
4. **Dedup merges across formats:** an Open Library or Hardcover novel matched to a comic with the same title. Check the cross-source query in §6.
5. **The classifier bugs in §4:** a manga publisher losing to the graphic-novel default, CJK publisher names, and a prose publisher outvoting a graphic-novel subject.
6. **Hardcover:** its GraphQL field names were never confirmed live (#11). If its syncs fail, its rows were never re-imported.
7. **Confidence gate:** `--min-confidence=0.6` can block a correct change. Run `bookflix:reclassify --dry-run` to see it.

## 6. Diagnostics to run first (read-only)

```bash
git checkout main && git pull origin main
php artisan migrate:status | grep -i pending

# types per source
php artisan tinker --execute="foreach (DB::select('select es.key, mt.slug, count(*) n from media m join media_types mt on mt.id = m.media_type_id join media_external_ids x on x.media_id = m.id join external_sources es on es.id = x.external_source_id where m.deleted_at is null and m.merged_into_id is null group by 1, 2 order by 1, 3 desc') as \$r) echo \$r->key.' '.\$r->slug.' '.\$r->n.PHP_EOL;"

# did the syncs finish?
php artisan tinker --execute="foreach (DB::select('select es.key, r.mode, r.status, r.started_at, r.finished_at, r.error from ingestion_runs r join external_sources es on es.id = r.external_source_id order by r.id desc limit 15') as \$r) echo implode(' | ', (array) \$r).PHP_EOL;"

# stories created by a comic source that a book source also lists (candidates for "novel shown as comic")
php artisan tinker --execute="foreach (DB::select('select m.id, m.title, mt.slug, p.key primary_source from media m join media_types mt on mt.id = m.media_type_id join external_sources p on p.id = m.primary_external_source_id where m.deleted_at is null and p.key in (\'comicvine\', \'metron\') and exists (select 1 from media_external_ids x join external_sources s on s.id = x.external_source_id where x.media_id = m.id and s.key in (\'openlibrary\', \'hardcover\')) limit 40') as \$r) echo implode(' | ', (array) \$r).PHP_EOL;"

# what reclassify would change, without writing
php artisan bookflix:reclassify --dry-run
```

Then ask the user for 3–5 concrete wrong titles (what it shows and what it should be). For each one, find its `media.id`, primary source, `locked_fields`, external ids, and the classifier verdict with reasons:

```bash
php artisan tinker --execute="\$m = App\Models\Media::where('title', 'ilike', '%TITLE%')->first(); \$r = app(App\Catalog\Classification\Reclassifier::class); \$p = (new ReflectionMethod(\$r, 'providerOf'))->invoke(\$r, \$m); \$v = app(App\Catalog\Classification\MediaTypeClassifier::class)->classify(\$r->signals(\$m, \$p)); dump(['id' => \$m->id, 'type' => \$m->mediaType->slug, 'primary' => \$p, 'locked' => \$m->locked_fields, 'verdict' => \$v->slug, 'confidence' => \$v->confidence, 'reasons' => \$v->reasons]);"
```

## 7. Probe script (live Open Library, no DB writes)

Save it as `storage/app/probe-ol.php` and run `php artisan tinker --execute="require storage_path('app/probe-ol.php');"`. Add the user's wrong titles to `$queries`.

```php
<?php
use App\Catalog\Classification\ClassificationSignals;
use App\Catalog\Classification\MediaTypeClassifier;
use App\Catalog\Providers\OpenLibrary\OpenLibraryProvider;
use Illuminate\Support\Facades\Http;

$p = app(OpenLibraryProvider::class);
$queries = [['Attack on Titan Hajime Isayama', 'manga'], ['Sword Art Online Reki Kawahara', 'light-novel'], ['Heartstopper Alice Oseman', 'graphic-novel'], ['Dune', 'novel']];
foreach ($queries as [$q, $expect]) {
    $hit = Http::timeout(20)->get('https://openlibrary.org/search.json', ['q' => $q, 'limit' => 1, 'fields' => 'key'])->json('docs.0.key');
    if (! $hit) { echo "$q | no hit\n"; continue; }
    $n = $p->normalize($p->find(basename($hit)));
    $v = app(MediaTypeClassifier::class)->classify(new ClassificationSignals(provider: 'openlibrary', title: $n->title, description: $n->description, subjects: $n->genres, publishers: $n->publishers, pageCount: $n->pageCount, language: $n->language));
    echo ($n->mediaTypeSlug === $expect ? 'ok ' : 'BAD')." $q | {$n->mediaTypeSlug} (want $expect) | ".basename($hit)."\n      ".implode(' ; ', array_slice($v->reasons, 0, 6))."\n";
}
```

Comic Vine, Metron and Hardcover have their own `find()` and `normalize()` in `app/Catalog/Providers/{ComicVine,Metron,Hardcover}`; probe them the same way with the local keys.

## 8. Fix plan

0. **Ask the user first: what should nonfiction be?** Options:
   - (a) Add a `nonfiction` media type (prose family). Bookflix has Dewey "Explore by Subject" for the 000s–900s, so nonfiction fits the catalogue. This is the recommendation.
   - (b) Keep nonfiction out of the catalogue: the classifier returns unsupported, and existing rows are soft-deleted by `reclassify`.
   - (c) Leave it as `novel`. Not recommended.

   For (a) or (b), the classifier gets a Dewey signal and nonfiction subjects ("Self-help", "Personal finance", "Success", "Business", "Psychology", "Biography", "Memoir" and so on). A 000–799 or 9xx Dewey class, or 8xx without a fiction subject, points to nonfiction. Pass `deweyCode` into `ClassificationSignals` from Open Library and Hardcover, and in the `Reclassifier` from `media_external_ids.dewey_code`. Option (a) also touches the `media_types` seeder, `MediaFamily`, the format filters, the Discover format tiles and the Dewey rules. Record the decision in the master plan's G2 (on PR #3).
1. Branch `fix-media-type-classification` from `main`.
2. **TDD:** add each wrong case to `tests/Unit/Catalog/MediaTypeClassifierTest.php` as `ClassificationSignals` copied from the real record, including the user's titles. Run it and see it fail.
3. Fix in `MediaTypeClassifier` / `PublisherCatalog`, keeping the weights' intent:
   - "manga" as a token inside a subject, and a `--japan` comics subject, add manga form evidence. A manga publisher should beat the `graphic-novel` tie default.
   - Match CJK publisher names (Kadokawa 角川 / カドカワ, 電撃文庫, MF文庫J and so on) without `Str::ascii`, or add their transliterations.
   - Strong comic subjects ("graphic novels" **and** "comics …", or a comics publisher) should be able to beat a single prose publisher, when there's no prose evidence beyond the publisher.
   - Whatever §6 shows for the local Comic Vine, Metron and Hardcover cases.
4. If the cause is data (§5 items 1–3): add the fix that lets the data heal, for example `bookflix:reclassify` honoring a stronger book-source verdict over a comic-source prior. Don't make admin locks overridable. Test it in `tests/Feature/Catalog/ReclassifyCommandTest.php` or similar.
5. Run `bookflix:reclassify --dry-run`, then `bookflix:reclassify`, then re-sync the affected sources, and re-check §6.
6. Open a PR to `main` with what was wrong, the evidence (before and after counts from §6), and the commands the user must run.

## 9. Rules (the user's)

- **Never** run `migrate:fresh`, `db:wipe`, `migrate:refresh`, or any artisan command with `--env=testing`. `php artisan migrate` is fine.
- Tests: `env -u AI_AGENT -u CLAUDECODE composer test` (Pint, PHPStan level 7, Pest on Postgres). Run `vendor/bin/pint --dirty` after PHP edits.
- Commits: plain conventional messages. **No `Co-Authored-By` lines, and no AI attribution in commits or PR bodies.**
- Never push to `main`; work on a branch and open a PR.
- Match the surrounding code: short docblocks that explain why, the same naming and idioms.
- Sexual content involving minors is never supported (`ProhibitedContent` rejects it at ingestion). Don't weaken it.
- Replies to the user: short and plain.
