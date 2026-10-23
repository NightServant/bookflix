# Phase 10 — Social Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Refresh against the code at phase start.** This plan is at task level (Files, Interfaces, test names with key assertions). Re-read Phases 1–9 as built, expand each task to step level with `superpowers:writing-plans`, and reconcile names with the code. F4 profile/review/report/moderation screens need F6 sign-off first.

**Goal:** Optional, privacy-first social features: public profiles and libraries, following, reviews with spoiler handling and an extractive review summary, reports and moderation, shared collections, an activity feed, a social recommendation source, and (lowest priority) per-story discussions.

**Architecture:** Everything is **opt-in and private by default** (Phase 1 `profile_visibility`/`library_visibility = private`). One authorization seam decides what a viewer may see: Gates `view-profile` / `view-library` (Phase 5) + `User::active()` everywhere, so deactivated users disappear from every surface. Moderation is admin-only (`access-admin`), content is hidden rather than deleted.

**Tech Stack:** Laravel 13, Livewire 4, Flux free, `Str::markdown` with HTML stripped (EasyMDE optional, MIT), Pest, the Phase 7 summarizer.

**Spec:** `/Users/gabe/Documents/Bookflix Documentation/Bookflix_Specification.md` §39 (reviews), §40, §43 (reviews/reports), §59, §60 (in-repo copy `docs/superpowers/specs/bookflix-specification.md`). **Master plan:** A6 Social (`reviews`, `reports`, `follows`), A12, A16, Q14, Part E Phase 10.

**Depends on:** Phase 1 (`Visibility`, `profiles`, `User::active()`, `access-admin`), Phase 5 (`Gate view-library`, collections visibility, `RateMedia`, `InteractionRecorder` incl. `review` event weight 4, morph map), Phase 6 (`CandidateSource`, `ReasonType`, `RecommendationServiceProvider`), Phase 7 (`MlClient::summarize`).

## Global Constraints

- Private by default; public visibility is an explicit user choice per profile and per library. A private profile/library is invisible to everyone but the owner (and admins in moderation context only).
- **Deactivated users are invisible**: profile, library, reviews, follows, activity, recommendations. Use `User::active()` / `isDeactivated()` in every query; never expose them via old URLs (404).
- Reviews: Markdown input rendered with HTML stripped (`Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false])`); max 5 000 chars; `has_spoilers` hides the body behind a "Show spoilers" control; one review per user × story (`unique(user_id, media_id)`); soft-deleted by author; `status` `published|hidden`.
- Reports: any authenticated user may report a review, collection or profile; reports never auto-hide; only admins act (hide/dismiss) and every action records `handled_by`/`handled_at`.
- Rate limits: reviews 10/hour, reports 10/hour, follows 60/hour per user. Uploaded avatars remain Phase 1's rules.
- Hidden reviews are excluded from every aggregate (review counts, review summary, activity, social recommendation signals).
- Interaction recording stays opt-out aware (`InteractionRecorder`); social data is not behavioral analytics and is exempt only for what the user publishes.
- Morph map additions: `review`, `collection`, `profile` (for `reports.reportable`). `security-review` on the branch (XSS in reviews, IDOR on reports/follows, moderation authorization).

## Review Focus

1. Private stays private on every route, search, feed, recommendation and API-ish endpoint; a visibility change takes effect immediately and clears caches. → 10.1, 10.5, 10.7.
2. Deactivated users and their content vanish everywhere, including follower counts and feeds; reactivation is out of scope (Q5). → 10.1–10.7.
3. XSS and abuse in reviews (script tags, javascript: links, huge bodies, spoilers leaking in previews/summaries/feeds). → 10.3.
4. Moderation correctness: hidden content excluded from aggregates; only admins moderate; one open report per reporter × target; self-follow/self-report blocked. → 10.2, 10.4.
5. Block/self cases: following yourself, following a private account, following twice, unfollow idempotence. → 10.2.

---

### Task 10.1: Public profiles and libraries

**Files:**
- Create: migration `add_handle_to_profiles_table` (`handle string(32) null unique`, lowercase `[a-z0-9_]`, **not in A6 — public URLs need a stable public identifier**, flagged), `app/Policies/ProfilePolicy.php` (Gate `view-profile` mirroring `view-library`), `resources/views/pages/profile/⚡show.blade.php` (route `profile.show` → `/u/{handle}`), `resources/views/pages/profile/⚡library.blade.php` (`/u/{handle}/library`), settings field for the handle in `settings/profile`
- Modify: `routes/web.php`, nav "Profile" item (own profile), sitemap exclusion for private profiles
- Test: `tests/Feature/Social/{ProfileVisibilityTest,HandleTest}.php`

**Interfaces:** `Gate::allows('view-profile', $owner)` = owner, or (`profile_visibility = public` and `! $owner->isDeactivated()`); library uses Phase 5's `view-library`. Public profile shows display name, avatar, bio, counts (followers/following of **active** users), favorite genres, recent public reviews, public collections; **never** email, preferences weights, interactions, progress or ratings unless the library is public (ratings then appear on the library page only).

- [ ] **Tests:** `private profile → 404 for others (not 403, no existence leak) and 200 for the owner`; `public profile visible to guests? no — auth required (app has no guest pages); other authed users see it`; `library private while profile public → library 404`; `visibility change takes effect on the next request`; `deactivated owner → 404 even with public settings`; `handle uniqueness, charset, reserved words (admin, settings, api…), case-insensitive`; `changing the handle redirects old handle? no — old handle 404 (documented)`; `profile page exposes no email/ids in HTML` (assertDontSee); `follower count excludes deactivated followers`.
- [ ] **Commit:** `feat: public profiles and libraries`

---

### Task 10.2: Following

**Files:** `app/Models/Follow.php` (table `follows`, PK `(follower_id, followed_id)`, A6), migration, `app/Actions/Social/{FollowUser,UnfollowUser}.php`, `app/Livewire` follow button component `resources/views/components/⚡follow-button.blade.php`, `resources/views/pages/profile/⚡connections.blade.php` (followers/following lists), `User::followers()/following()` relations
**Interfaces:** `FollowUser::handle(User $follower, User $target): void` — idempotent; rejects self-follow, deactivated targets and **private-profile targets** (403 `ValidationException` key `follow`); `UnfollowUser::handle` idempotent. Lists show only active users with public profiles (private ones are counted? no — omitted and not counted for non-owners).
- [ ] **Tests:** `follow creates one row, twice still one`; `self follow rejected`; `private target rejected`; `deactivated target rejected`; `unfollow idempotent`; `another user cannot make A follow B (action uses auth user)`; `lists exclude deactivated and private users`; `rate limit 60/hour`; `follows cascade-delete with the user`; `follow button reflects state after toggle`; `privacy change to private removes inbound follows? no — existing follows stay but stop granting visibility` (documented decision + test that private profile content remains hidden from followers).
- [ ] **Commit:** `feat: following`

---

### Task 10.3: Reviews with spoilers and moderation status (Review Focus #3)

**Files:** migration `create_reviews_table` (A6: `user_id`, `media_id`, `body text`, `has_spoilers bool`, `status` enum published|hidden, `softDeletes`, `unique(user_id, media_id)`), `app/Models/Review.php` + factory (states `hidden()`, `spoiler()`), `app/Policies/ReviewPolicy.php`, `app/Actions/Social/{SaveReview,DeleteReview}.php`, `resources/views/components/⚡review-form.blade.php`, `resources/views/components/review-list.blade.php`, story page "Reviews" tab (Phase 4 `<x-tabs>`), `app/Support/Markdown.php` (`safe(string): HtmlString`)
**Interfaces:** `SaveReview::handle(User $user, Media $media, string $body, bool $hasSpoilers): Review` (upsert; records `InteractionEvent::Review`; fires `LibraryChanged` for cache invalidation; rate limited); reviews list shows only `published` reviews by **active** users; spoiler bodies are collapsed (`<details>`), excluded from previews/feeds/summaries unless the viewer expands; `Markdown::safe` strips HTML and unsafe links.
- [ ] **Tests:** `one review per user per story, resubmitting edits`; `body required, 5000 max`; `script tags and javascript: links stripped from output` (dataset of payloads: `<script>`, `<img onerror>`, `[x](javascript:alert(1))`, `<iframe>`, `data:` URLs); `markdown renders emphasis/lists`; `spoiler review body not present in the initial HTML of list previews, only inside a closed <details>`; `hidden review not shown to others but visible to its author with a "hidden by moderators" notice`; `deactivated author's reviews not shown`; `soft-deleted review not shown and can be re-created`; `only the author edits/deletes (policy)`; `opted-out user: review stored, no interaction row`; `review rate limit 10/hour`; `Unicode/emoji/RTL bodies saved intact`.
- [ ] **Commit:** `feat: reviews with spoilers`

---

### Task 10.4: Reports and moderation queue (Review Focus #4)

**Files:** migration `create_reports_table` (A6: `reporter_id`, `morphs(reportable)`, `reason` enum(spam, abuse, spoiler, copyright, other), `details text null`, `status` open|actioned|dismissed, `handled_by null`, `handled_at null`; unique open report per `(reporter_id, reportable)` enforced in the action), `app/Models/Report.php`, `app/Actions/Social/{FileReport,HideReview,DismissReport}.php`, `resources/views/components/⚡report-dialog.blade.php` (`flux:modal`), `resources/views/pages/admin/moderation/⚡index.blade.php` (route `admin.moderation`)
**Interfaces:** `FileReport::handle(User $reporter, Model $target, ReportReason $reason, ?string $details): Report` (target must be `Review|Collection|Profile` via morph map; cannot report yourself/your own content; duplicate open report returns the existing one); moderation actions: `hide` (review → `hidden`; collection → `visibility = private` + `moderation_hidden_at`; profile → `profile_visibility = private`), `dismiss`; each sets `status`, `handled_by`, `handled_at` and, when hiding, marks sibling open reports on the same target `actioned`.
- [ ] **Tests:** `report requires auth, valid reason, details ≤ 1000`; `cannot report own review/profile`; `duplicate open report not duplicated`; `non-reportable class rejected (morph map)`; `non admins 403 on queue and on every action (Livewire call also 403)`; `hide removes the review from lists, counts and summaries immediately`; `dismiss keeps content`; `handled_by/handled_at recorded`; `sibling open reports actioned together`; `queue sorted oldest open first with filters`; `reported content deleted since → report shows "removed"`; `report rate limit`.
- [ ] **Commit:** `feat: reports and moderation queue`

---

### Task 10.5: Review summary (LSA) and aggregate hygiene

**Files:** migration `add_review_summary_to_media_features` (`review_summary text null`, `review_summary_count int`, not in A6), `app/Jobs/SummarizeReviews.php` (runs when a story gains/loses ≥ 5 published non-spoiler reviews since the last run; debounced), `MlClient::summarize` reuse, story page "What readers say" block
**Interfaces:** `SummarizeReviews::handle(): void` concatenates the **published, non-spoiler, active-author** review bodies (cap 20 000 chars, newest first), calls `summarize(text, 3)`, stores the extractive sentences; with fewer than 5 qualifying reviews it clears the summary. Never shows the summary for stories under the threshold.
- [ ] **Tests:** `fewer than 5 qualifying reviews → no summary, existing summary cleared`; `hidden/spoiler/deactivated-author reviews excluded from the text sent to ML` (assert request body); `hiding a review triggers a re-summarize that no longer contains its sentences`; `ML down → previous summary kept, no exception`; `summary text is escaped on render`; `job debounced (one job per story per 10 minutes)`.
- [ ] **Commit:** `feat: review summaries`

---

### Task 10.6: Shared collections

**Files:** modify `app/Policies/CollectionPolicy.php` (`view` honours `Visibility::Public` + active owner), `resources/views/pages/collections/⚡show.blade.php` (share link, copy-to-clipboard `x-clipboard`-free native `navigator.clipboard` with fallback), `resources/views/pages/profile/⚡show.blade.php` (public collections list), "Save a copy" action `CloneCollection::handle(User, Collection): Collection`
**Interfaces:** public collections are viewable by any authenticated user at `/collections/{owner-handle}/{slug}` (new route `collections.public`); cloning copies items as a new private collection owned by the cloner.
- [ ] **Tests:** `private collection 404 to others, public 200`; `becoming private later hides it immediately`; `deactivated owner's collection 404`; `clone creates a private copy with items and a unique slug`; `clone of a private collection rejected`; `owner editing a public collection does not leak private notes (collection_items.note shown only to owner)`; `reportable via 10.4`.
- [ ] **Commit:** `feat: shared collections`

---

### Task 10.7: Activity feed and social recommendation source (Review Focus #1, #2)

**Files:** `app/Social/ActivityFeed.php`, `resources/views/pages/home/…` optional "Friends" shelf is **not** added; feed page `resources/views/pages/activity/⚡index.blade.php` (route `activity`), `app/Discovery/Recommendations/Sources/FollowedReadersSource.php`, `ReasonType::PopularWithFollowed` + template "Popular with readers you follow" (A6 enum gains a value — migration alters the check/enum, flagged), `RecommendationServiceProvider` tag
**Interfaces:** `ActivityFeed::for(User $viewer, int $perPage = 20): LengthAwarePaginator` — events from users the viewer follows who are active **and** whose relevant visibility is public: finished a book (library `completed`), rated (library public only), published a non-spoiler review, created a public collection; derived from existing tables (no new table), ordered by timestamp desc, cursor-safe. `FollowedReadersSource` key `followed_readers`: stories completed or rated ≥ 8 by followed, active, **library-public** users, scored by distinct followers count, excluding the viewer's library; reason `PopularWithFollowed`, payload carries the count (never names).
- [ ] **Tests:** `feed only includes followed active users with public visibility`; `owner switching library to private removes their items from others' feeds at once (no cache staleness)`; `spoiler review shown as "reviewed X (spoilers hidden)"`; `hidden reviews excluded`; `deactivated user excluded`; `no follows → empty state`; `pagination stable under inserts`; `source returns nothing for private libraries / opted-out viewers? — opted-out viewers still see it if they follow people (their own data isn't used); documented` ; `source excludes viewer's library, dismissed items`; `explanation never reveals who`; `query count bounded (≤ 8)`.
- [ ] **Commit:** `feat: activity feed and followed readers source`

---

### Task 10.8 (lowest priority, optional): Per-story discussions

**Files:** migrations `discussion_threads` (`media_id`, `user_id`, `title`, `status`, `locked_at`), `discussion_posts` (`thread_id`, `user_id`, `body`, `status`, `softDeletes`) — **not in A6** — models, policies, `pages/stories/⚡discussion.blade.php` (story tab), report integration (add `discussion_post` to the morph map)
**Interfaces:** same safety rules as reviews (`Markdown::safe`, rate limits, `User::active()`, hidden excluded); admins can lock threads; spoiler flag per post.
- [ ] **Tests:** create/reply/edit/delete policies; XSS dataset reused; locked thread rejects replies; hidden/deactivated excluded; reportable; pagination; rate limit.
- [ ] **Commit:** `feat: story discussions` — build only after 10.1–10.7 prove out; otherwise record "deferred" in the decision log.

---

## Phase 10 exit criteria
- [ ] `composer test` + browser smoke (follow → feed → review → report → moderate) green; `security-review` and `design:accessibility-review` pass; `design:ux-copy` on report/moderation copy.
- [ ] Privacy matrix test (public/private × profile/library/collection × owner/other/deactivated) passes in one data-driven test file `tests/Feature/Social/PrivacyMatrixTest.php`.
- [ ] Ask the user to run the full `php artisan test --compact`.
