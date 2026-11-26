# Bookflix: Cleanup, Polish and Hardening Pass

Working brief for a Claude Code session

> Converted to Markdown on 2026-11-26 from the owner's Word brief. The screenshots are left out (their captions remain). The implementation plan is `docs/superpowers/plans/2026-11-26-bookflix-masterplan-2.md`; its decision log records where the plan rules on something this brief leaves open.

**Stack:** Laravel · Blade + Livewire + Alpine.js · Tailwind CSS · Neon Postgres (pgvector) · S3-compatible storage · Python/FastAPI ML service. **Revised:** November 2026.

## 0. Read this first

You're picking up the existing Laravel codebase for Bookflix, an online library and story-discovery platform with a streaming-style browsing experience and a Dewey-inspired classification layer. This brief covers one pass of work: specific UI/UX changes the product owner has screenshotted, a site-wide cleanup and hardening checklist, data collection (legal book sources through a separate harvest database, review and rating data, and UI/UX references), an in-app e-reader, and a motion pass.

**What Bookflix is for.** The owner built Bookflix so they can read manhwa, comic books, graphic novels and books in one place, instead of jumping from website to website to find the next chapter. Every decision in this brief should make that easier: fewer clicks to the next page, one library, one place that remembers where you left off. Part F explains how Bookflix does that within the law: hosting what it may host, reading the user's own files, and tracking and launching everything else.

Treat this brief as the source of truth for the session. Where it disagrees with the code, the code tells you what exists and this brief tells you what should exist. The Bookflix master specification still governs anything this brief doesn't mention.

### Decisions already made (don't revisit them)

- Laravel is the full-stack framework. The UI is Blade + Livewire + Alpine.js with Tailwind. No React, no Next.js.
- Neon Postgres is the database. Files live in S3-compatible storage, never in a public bucket.
- Authentication is email and password through Laravel Fortify. No OAuth or Socialite.
- Accounts are deactivated, never hard-deleted.
- The visual language follows the Netflix-inspired token set in the master spec (Section 51): canvas #000000, surfaces #161616 / #232323 / #2d2d2d, hairline #414141, muted text #808080, and brand red #e50914 reserved for primary actions. No drop shadows; depth comes from stepping between surface colors.
- Bookflix only distributes public-domain, openly licensed, or user-owned content (master spec Section 6). This overrides anything else in this brief.
- Content collection runs in a separate harvest database and service (Part F5). Nothing it downloads reaches readers until an admin verifies it.

### How to work

- **Audit before you change anything.** For each part, find what exists today (routes, Blade views, Livewire components, policies, config) and write it down.
- **Follow the priority order below**, one part at a time, with a focused commit per item or per tight group of items. Don't bundle unrelated changes.
- **Don't fabricate.** No invented stats, testimonials, contact details, or legal text that claims practices the code doesn't follow. If you need content you can't derive from the codebase, leave a clearly marked TODO and list it in your report.
- **Ask before anything destructive or hard to undo:** dropping columns, editing migrations that already ran, bulk imports into production, rotating credentials, or changing auth behavior for existing users.
- **Skip what doesn't apply.** If the site has no phone number or no paid plan, don't build one to satisfy a checklist. Mark the item "Not applicable" and say why.
- **Prefer what Laravel already provides** (Form Requests, policies, gates, rate limiters, signed URLs, Fortify two-factor) over new packages. If you add a dependency, name it and justify it in the report.
- **Verify before you call a part done** using the checks in Part I.

### Priority order

- Part D: Security (fix anything exploitable first)
- Part B: Broken functionality and mobile
- Part E: Legal and compliance
- Part A: Screen-specific UI/UX changes (collect the F10 references first)
- Part C: Pages, structure, SEO, trust and conversion
- Part F: Everything in one place (sources, harvest database, your files, official platforms, licensing, reader)
- Part G: Motion and animation
- Part H: Design-tells audit (report only)

## Part A. Screen-specific UI/UX changes

The screenshots show the current state. Each item says what to change and how to tell it's done.

### A1. Homepage navigation bar

*Screenshot in the original brief (not included): Current homepage navigation bar*

**Current:** the top bar (logo, Top 10, Why Bookflix, FAQ, Sign in, Get started) stays sticky for the full length of the page.

**Change:** keep it sticky through the hero and content sections, but it must not appear over the FAQ section or the closing CTA section. When the top of the FAQ section reaches the top of the viewport, the bar leaves. Scrolling back above the FAQ brings it back.

**Implementation notes**

- Drive it with an Alpine component and an IntersectionObserver on the FAQ section (or a sentinel just before it), not a scroll listener.
- Hide it with a transform and opacity rather than display: none, so nothing jumps. Under prefers-reduced-motion, switch instantly.
- In-page links such as FAQ must still land in the right place. Use scroll-margin-top on anchored sections to account for the bar.

**Done when:** on desktop and at 375px wide, the bar shows over every section above the FAQ, is absent over the FAQ and final CTA, returns on scrolling up, and no content shifts when it toggles.

### A2. Frequently Asked Questions

*Screenshot in the original brief (not included): Current FAQ section*

**Current:** five FAQ cards in one column, all closed.

**Change**

- **Desktop (1024px and up):** two columns. The left column holds the first three questions in their current order; the right holds the last two. Each column stacks on its own (two flex columns, not a grid with shared rows), so opening a card on one side doesn't stretch the other.
- **Below 1024px:** one column, same order.
- "What is Bookflix?" is open when the page loads.
- Only one card is open at a time across both columns. Opening any card closes the open one, including the default. Clicking the open card closes it.
- **Accessibility:** each question is a <button> with aria-expanded and aria-controls; the answer region is labelled by its question; Enter and Space work; the focus ring uses design-system tokens instead of the browser's default blue.

**Done when:** all of the above holds with mouse, keyboard and touch at every breakpoint.

### A3. Settings page (match the Profile page)

*Screenshot in the original brief (not included): Profile page: the target look*

*Screenshot in the original brief (not included): Settings › Privacy: current state*

**Current:** Profile is one rich page with a cover banner, avatar, reading stats, a profile-strength checklist and a library summary. Settings is split into four sub-pages (Profile, Preferences, Privacy, Security) behind a side menu, each a stack of plain forms with its own Save button.

**Change:** rebuild Settings as a single page with the same structure as Profile: a header block, then surface cards in two columns on desktop (settings on the left, a narrower summary column on the right) and one column on mobile. Everything a user can configure lives on this page. The old sub-routes redirect to the matching section anchor.

Suggested sections, in order: **Profile** (name, avatar, bio, headline); **Reading preferences** (genres, themes, languages, media types, preferred length); **Privacy and AI** (personalization, AI explanations, what Bookflix sends to the AI service, download my data); **Security** (password, two-factor authentication, active sessions); **Account** (deactivate account).

**Components to add or upgrade**

- **Toggles** for every on/off setting. They save immediately, show a small inline confirmation, and roll back with an error message if the save fails. Remove per-card Save buttons where a toggle already saves.
- **Primary CTA buttons** (brand red) only for the one main action in a form that needs an explicit save.
- **Dialogs** for focused or risky edits: change password; set up two-factor (QR code plus recovery codes); deactivate account (explain what deactivation means and require the password).
- **A sticky in-page section nav** on desktop: anchor links that highlight the current section, so the long page stays easy to move around.

You may borrow patterns from well-regarded settings pages on other sites. Name the references you used and why in your report, and keep everything inside the Bookflix token set.

**Done when:** every setting that exists today is reachable on the single page with nothing lost, every toggle and dialog has success and error states, and the page looks like it belongs next to Profile.

### A4. Admin tables: one standard for every table

*Screenshot in the original brief (not included): Users*

*Screenshot in the original brief (not included): Models*

*Screenshot in the original brief (not included): Reading files*

*Screenshot in the original brief (not included): Staff picks*

*Screenshot in the original brief (not included): Classification review*

*Screenshot in the original brief (not included): Duplicate review*

**Current:** Users, Models, Reading files, Staff picks, Classification review and Duplicate review each handle search, filtering and actions differently. Some have no search, none paginate, and button styles don't match.

**Change:** build one reusable table pattern (a Blade component plus Livewire traits) and move every admin table onto it. Each table page has, from top to bottom:

- **Stat cards.** Each card has a title, a large emphasized number, and a line of subtext under the number (for example: "Duplicate pairs" / 980 / "oldest waiting 4 weeks"). Numbers come from real queries. If a metric isn't computed anywhere yet, leave the card out rather than inventing it.
- **A chart beside the stat cards.** One visualization of that table's data (for example, review throughput over the last 30 days). It sits to the right of the stat cards on desktop and below them on smaller screens.
- **Toolbar.** A debounced search over the columns that matter for that table, kept in the URL query string, plus filters: tabs when there are a few mutually exclusive states (Pending, Accepted, Rejected) and dropdowns when there are many values or several dimensions. Use whichever fits each table; some need both.
- **The table.** A clear hierarchy between headers (smaller, muted, semibold, hairline divider) and data rows (primary text, comfortable row height). Long text truncates with a tooltip or expands; it never overflows.
- **Pagination.** 10 rows per page, with the total count and page controls, and page state kept in the URL.

Buttons use four classes everywhere:

| Class | Look | Use for |
|---|---|---|
| Primary | Solid brand red | The single main action on a page, such as "New staff pick" |
| Secondary | Surface-card fill | Routine row actions: Accept, Keep first, Verify |
| Outline | Hairline border, transparent fill | Lower-emphasis actions: Compare, More |
| Danger | Red text and red border on a transparent fill, plus a confirmation dialog | Destructive actions: delete, retire a model, deactivate a user. Primary is also red, so danger must look clearly different. |

**Responsiveness:** the pattern works on mobile, tablet and small laptops (1280px). Below roughly 768px, rows collapse into stacked cards, or the table scrolls inside its own container with the first column pinned. The page itself never scrolls sideways.

**Done when:** all six tables use the shared pattern, search and filters work together with pagination, and the layout holds at 375, 768, 1024 and 1280px.

### A5. Admin Overview dashboard

*Screenshot in the original brief (not included): Current admin Overview*

**Current:** a text-only "Needs attention" table and an "At a glance" list.

**Change,** from top to bottom:

- **Stat cards** with the same anatomy as A4: for example, stories in the catalog, enabled sources, items awaiting review, registered readers, and last sync status.
- **Four chart cards,** two per row on desktop and one per row on mobile. Choose charts that answer real questions, such as catalog growth over time, review backlog over time, sync job outcomes by day, and reader sign-ups or active readers. Only chart data the system actually records.
- **One combined work queue** replacing "Needs attention" and "At a glance". Each row is either an item that needs a decision or a system area with its status: a count, the age of the oldest item, a status badge (a failing sync shows as an error), and a clear CTA. Tabs or a dropdown filter by area (Catalog, Review, Operations, People) and by severity. Items that need action sort first.

**Done when:** an admin can see what's broken and what's waiting at a glance, and reach any of it in one click.

### A6. Admin sidebar and admin settings

*Screenshot in the original brief (not included): Current admin sidebar*

**Current:** ten links in four groups (Overview; Sync, Media, Duplicates, Classification, Staff picks, Reading files; Models; Users, Reports) and a small account block at the bottom.

**Change**

- Put a large avatar at the top of the sidebar, with the admin's name and a Settings link under it. Remove the bottom account block; Sign out moves under the avatar.
- Consolidate routes to cut down the choices. Recommended grouping, with tabs inside each page:
- **Overview**
- **Catalog:** Media, Reading files, Staff picks
- **Review:** Duplicates, Classification (keep the pending counts as badges)
- **Operations:** Sync, Models
- **People:** Users, Reports
- That's five links instead of ten. Old URLs redirect to the new tab so bookmarks keep working.
- The admin settings page follows the Profile and Settings design from A3 and includes two-factor authentication (use Fortify's built-in support). Editing the name happens in a dialog. The email address is shown read-only: admins can't change it, and the server must reject an email change even from a forged request, not just hide the field.

**Done when:** the sidebar has five destinations, every old page is one click from its new home, and the admin settings page passes the same checks as A3.

## Part B. Broken stuff and basic functionality

- **Broken links, footer included.** Crawl every route the app serves, public and signed-in, and check every href: footer, in-page anchors, and links inside seeded content. Fix or remove each broken one.
- **Buttons that do nothing.** Every button or link-styled control triggers an action or a navigation, or is visibly disabled with a reason. Look for empty handlers, href="#", and Livewire actions that don't exist.
- **Horizontal scroll and mobile overflow.** Check at 320, 375, 414, 768, 1024, 1280 and 1440px. Usual causes: fixed widths, long unbroken strings (emails, URLs, alternate titles), wide tables, 100vw next to a scrollbar, and negative margins in carousels. Content rails scroll inside themselves; the page never does.
- **Mobile menu.** Add one if it's missing: an accessible drawer with a focus trap, Esc to close, body scroll locked while open, and auto-close on navigation.
- **Logo goes home.** The logo links to the home page: the reader's feed when signed in, the landing page for guests.
- **Clickable phone and email.** Use tel: and mailto: links. If the site shows no contact details, mark this Not applicable; don't invent any.
- **Real mobile check.** Use the site on a phone-sized viewport, not just a resized desktop: reader, search, library actions, forms and dialogs.
- **Placeholder text and dead nav.** Remove "lorem ipsum", "your text here", coming-soon stubs, and nav links that don't lead anywhere yet. Search views and seeders for them.

## Part C. Pages, structure, SEO, trust and conversion

### C1. Pages and structure

- **Custom 404.** Add resources/views/errors/404.blade.php in the Bookflix style, with a search box, a link home, and a few popular shelves. Give 403, 419 (page expired), 500 and 503 matching pages.
- **Thank-you page after form submissions** that end a flow: contact, feedback, content reports, and any signup or waitlist that sends the user away. Use post/redirect/get so a refresh doesn't resubmit. Settings saves stay inline (A3) and don't need a separate page.
- **Breadcrumbs** on nested pages: story and series pages, classification shelves (800 Literature › 810 American Literature › 813 Fiction), settings sections and admin pages. Output BreadcrumbList structured data alongside.
- **Internal links between related pages:** story, author, series, classification shelf and similar stories all link to each other; help and FAQ link to the privacy policy.
- **Privacy policy page,** linked from the footer and the registration form. Content requirements are in Part E.

### C2. SEO basics

- **Unique, descriptive title tags** on every page, set through the layout (for example, "Dune by Frank Herbert | Bookflix"). Private pages such as library, settings and admin still get proper titles.
- **Meta descriptions** for every public page. Story and author pages generate theirs from content: trim the synopsis to about 155 characters on a word boundary.
- **Favicon set:** favicon.ico, an SVG icon, a 180×180 apple-touch-icon, and a web manifest.
- **robots.txt:** allow public pages; disallow /admin, /settings, /library, auth routes and API routes. Reference the sitemap if one exists.
- **Social share image:** a site-wide og:image (1200×630) and Twitter card tags, with per-story images generated from the cover when the cover's license allows it.
- **Alt text on every image.** Covers read "Cover of {title} by {author}". Purely decorative images get alt="".
- **Compress slow images:** serve WebP or AVIF with fallbacks, responsive srcset, explicit width and height, and lazy loading below the fold. Cache and resize covers from external providers instead of hot-linking full-size originals.

### C3. Trust and content

- **Copyright year** in the footer comes from the server ({{ now()->year }}), not a hard-coded value.

### C4. Conversion

- **Main CTA above the fold.** "Get started" is visible without scrolling on desktop and on common phone sizes (for example 375×667).
- **Sticky CTA on mobile** for guests. It doesn't cover content or controls, respects the safe-area inset, and hides while the FAQ and the final CTA section are on screen, consistent with A1.
- **Success and error messages on every form:** inline field errors from validation, a visible confirmation on success, and a readable message when the server fails. Nothing fails or succeeds silently. Test each form by forcing a validation error and a server error.
- **Vercel Analytics.** Check the deployment target first. If Bookflix runs on Vercel, enable Vercel Web Analytics in the Blade layout and disclose it in the privacy policy. If it doesn't, don't bolt it on: say so in the report and propose an equivalent that fits the actual host.

## Part D. Security: check for and fix

- **Secrets in client code or the repo.** Scan the working tree and the full git history (for example with gitleaks) for API keys, database URLs, APP_KEY, storage keys and AI provider keys. Anything in a VITE_-prefixed variable ships to the browser, so nothing secret may live there. .env must be gitignored. If a secret was ever committed, report it: it needs rotating, and whether to rewrite history is the owner's call.
- **Weak or missing auth on protected routes.** Every admin route sits behind auth, verified and an admin gate or policy. Every public method on a Livewire component can be called from the browser, so each action authorizes on its own; lock IDs and roles with #[Locked]. Add Pest tests that hit admin routes and actions as a guest and as a regular reader and expect a redirect or 403.
- **Database and storage access.** The app connects to Neon with a least-privilege role, not the owner role. Preview-branch credentials are never shared with production, and no connection string reaches the client. Storage buckets are private; readers get files only through short-lived signed URLs issued after an authorization check.
- **Unvalidated or unsanitized input.** Every form, upload and query parameter goes through Form Requests or Livewire validation. Uploads are checked by content type and size, and EPUB and CBZ archives are checked for zip bombs and path traversal. User content is never rendered with {!! !!}. Models use $fillable. Sort and filter parameters are whitelisted, never passed straight into orderBy.
- **Open endpoints.** List every API route and Livewire-reachable action. Anything that reads or writes user data requires auth. Add rate limits to login, registration, password reset, search and the AI "Ask" feature.

## Part E. Legal and compliance

Bookflix stores account details, reading activity and interactions, and sends some of that to an outside AI service (the current Privacy screen says so). The privacy policy has to match what the code actually does, so start from an inventory of tables, logs and third-party calls, not from a template.

- **What's collected.** The policy discloses what data is collected, why, how long it's kept, and how users can export it.
- **AI use.** Disclose the "Ask" assistant, AI explanations and AI-assisted search; name the provider, say what's sent (matching the existing "What we send" panel), and explain that personalization and AI features can be switched off.
- **Third parties.** Disclose every service that touches user data: analytics, storage, email delivery, the AI provider, error tracking, and any CDN or font host.
- **Uploaded files after deactivation.** Accounts are deactivated, not deleted. Document exactly what happens to a user's uploads and data on deactivation (access revoked, how long files are kept), and make the code do what the policy says. Flag in your report that privacy laws such as GDPR and CCPA may still require a way to erase data on request. Don't build hard deletion without the owner's go-ahead.
- **User uploads (F6).** The privacy policy and terms explain that uploads are private, how they're stored, the storage quota, what happens to them on deactivation, and how rights holders can request a takedown.
- **Followed series on other platforms (F7).** Disclose that Bookflix stores which external series a user follows and their episode progress, and that following a link takes them to a third-party site with its own privacy policy.
- **Cancelling a subscription.** If there's a paid plan, cancelling must be at least as easy as signing up: the same number of steps or fewer, available from Settings. If there's no paid plan, mark this Not applicable.
- **Auto-renewal reminders.** If any charge renews automatically, email a reminder before the charge. Otherwise, Not applicable.
- **AI chat and self-harm.** The "Ask" assistant must respond well to messages about self-harm or suicide: warm and direct, without lecturing, with region-appropriate crisis resources. It must never treat these messages as a book search or fail silently. Keep the resource list in config so a person can review and update it, add tests with representative messages, and log no more of these conversations than you need.

## Part F. Everything in one place: sources, your files, official platforms and the reader

This part is the core of the product goal: one place to read manhwa, comics, graphic novels and books. Bookflix gets there four ways: it hosts free, public-domain and openly licensed works it collects automatically (F3–F5); it reads the user's own DRM-free files (F6); it tracks series on official platforms and opens the exact episode with one tap (F7); and it hosts licensed work from creators who give permission (F8). Reviews and ratings (F9) and UI/UX references (F10) support all of it.

**Why not the aggregator sites.** The sites that make one-stop manhwa reading easy today (MangaFire, Toonily, GetComics and similar) re-upload scans and translations nobody licensed. A Bookflix that collected from them would be doing the same thing at scale, and the legal risk would sit with the owner. Bookflix gets as close as it can to the same convenience through the four routes above.

**Using fmhy.net/reading.** https://fmhy.net/reading is a community index that mixes legitimate readers and public-domain libraries with shadow libraries and piracy sites, and it doesn't mark which is which. Treat it as a list of candidate sources, never as a download list. You may re-read it to find new candidates, but every candidate has to pass the vetting checklist in F3 and be approved by the owner before you write an adapter for it. Never integrate, scrape, proxy, embed or link to anything on the exclusion list (F4).

### F1. In-app reader (installation and integration)

Integrate **foliate-js** (github.com/johnfactotum/foliate-js) as the web reader. It renders EPUB, MOBI/KF8 (AZW3), FB2 and CBZ in the browser, and PDF through PDF.js. Confirm its license in the repository and pin a specific commit before vendoring it.

- **Install:** vendor it as ES modules under resources/js/reader (or as a pinned git dependency), bundle with Vite, and mount it in a Livewire reader component wrapped in wire:ignore so Livewire never re-renders the reader's DOM.
- **Serving files:** load book files from private storage through a signed, short-lived route that first checks that the file is marked Verified on the admin Reading files screen and that this user may read it. Never expose bucket URLs.
- **Progress:** save the location (CFI or page), chapter and percentage to reading_progress, debounced every few seconds and on page hide, and restore it on open. This feeds Continue Reading.
- **Reader chrome** follows the design tokens: dark by default, with adjustable font size, line height and margins, plus a light or sepia theme for long sessions. Support keyboard and swipe navigation and a table of contents, and respect reduced motion.
- **A dedicated comics and manhwa reader.** For image-based chapters (CBZ, converted CBR, image sets), build a lightweight image reader component (Alpine + Livewire) rather than forcing them through foliate-js. It needs: vertical continuous scroll for webtoon-format manhwa (detect from series metadata or tall image ratios); paged single-page and two-page spread modes for comics and graphic novels; right-to-left for manga; fit to width or height; pinch and double-tap zoom; tap zones and arrow keys; preloading of the next few pages; and, at the last page, a prompt that opens the next chapter or volume in one tap. That last step is the whole point of the product, so it should never require going back to a list.
- **Formats it doesn't cover:** for CBR (RAR), either convert verified files to CBZ at ingest or leave the format unsupported and say so. DjVu is out of scope.
- **License notice:** show the license and jurisdiction note on the story page before reading (for example, "Public domain in the United States. Check your local laws if you're elsewhere."). The Reading files screen already records this text.

### F2. Help page: read it in your own app

For public-domain files readers are allowed to download, add a help page recommending free reading apps, linking only to each project's official site:

| Reader | Platforms | Good for |
|---|---|---|
| Readest (readest.com) | All platforms, including web | All-round EPUB and PDF reading |
| Koodo Reader (koodoreader.com) | All platforms | Managing a personal EPUB/PDF library |
| KOReader (koreader.rocks) | E-ink devices, Android, Linux | Reading on e-ink devices |
| Foliate (johnfactotum.github.io/foliate) | Linux | Clean EPUB reading on Linux |
| Thorium Reader (thoriumreader.com) | Windows, macOS, Linux | Accessible EPUB reading |
| Sumatra PDF (sumatrapdfreader.org) | Windows | Lightweight PDF, EPUB and CBZ |
| calibre (calibre-ebook.com) | Windows, macOS, Linux | Organizing and converting a library (ignore its download plugins) |

Don't recommend readers whose installers bundle adware or whose provenance is unclear.

### F3. Book sources: allow-list, candidates and vetting

**Approved sources.** Build harvest adapters for these first (see F5). Record provenance for every file: source, source URL, license, rights holder, jurisdiction, and who verified it when.

| Source | Provides | Conditions |
|---|---|---|
| Project Gutenberg (gutenberg.org) | Public-domain ebooks | Public domain in the US. Use their official catalog files and mirrors and follow their robot policy; never crawl the website. |
| Standard Ebooks (standardebooks.org) | Carefully produced public-domain EPUBs | Check the current terms for their feeds before automating anything. |
| Wikisource (wikisource.org) | Public-domain texts | Use the MediaWiki API; licensing is per work. |
| Internet Archive (archive.org/details/texts) | Books, magazines, newspapers | Only items marked public domain or openly licensed. Never use controlled-lending ("Borrow") books. |
| Faded Page (fadedpage.com) | Public-domain ebooks | Public domain in Canada; many titles are still under copyright in the US and elsewhere. Gate by jurisdiction or skip. |
| Unglue.it (unglue.it) | Creative Commons books | Keep the license and attribution with the file. |
| OAPEN, OpenStax, LibreTexts, Open Textbook Library, Wikibooks | Open-access academic and textbook content | Keep license and attribution; suits the non-fiction shelves. |
| Comic Book Plus, Digital Comic Museum | Golden Age public-domain comics | Confirm public-domain status title by title before verifying a file. |
| LibriVox (librivox.org) | Public-domain audiobooks | Future audiobook support; collect metadata only for now. |
| DPLA (dp.la) | Aggregated metadata from US libraries | Metadata and links only. |

**Candidates from fmhy.net/reading that need vetting first:** Baen Free Library, ManyBooks, Loyal Books, FreeBannedBooks, By the Fireplace, DigiLibraries, ExClassics, and the Free-Programming-Books index (which only links out, so each linked book needs its own check). Some of these mix free and paid or licensed titles, so the vetting has to happen per title, not per site.

**Metadata only, no files:** Open Library (already in the master spec, with an official API), BookBrainz (open book metadata with an API), ISFDB (science fiction, fantasy and horror bibliography) and MangaUpdates (manga metadata and release tracking; check its API terms). Providers already in the master spec stay as they are. MangaDex is metadata-only: never fetch or display its chapter images, which are scanlations.

#### Vetting checklist for any new source

- It's the official site of the publisher, library or project, not a mirror or re-upload of someone else's catalog.
- Each item carries a machine-readable license or public-domain statement, or the whole collection has one clear license.
- Its terms or robots policy allow automated access, or it offers an API, OPDS feed or bulk download meant for that.
- You know which jurisdictions the public-domain claim covers.
- The owner has approved it in writing (a note in the sources table is enough). Until then, enabled stays false.

### F4. Exclusion list (never use)

**Shadow libraries and piracy sites** listed on that page, including Anna's Archive, Z-Library, Library Genesis, Sci-Hub, Mobilism, MyAnonaMouse, WeLib, Flibusta, OceanOfPDF, VDOC.PUB, PDFCoffee, GetComics, Read Comics Online, BatCave, AudiobookBay, Nyaa, MangaFire, MangaNato, MangaKatana, Mangapill, MangaHere/MangaFox, Toonily, NovelFire, Ranobes, Ocean of EPUB, and the magazine PDF sites.

**Also excluded:** site directories that point to them (Wotaku, The Index, EverythingMoe); DRM-removal tools (Libation, acsm-calibre-plugin, Audible tools); scrapers and downloaders (Lightnovel Crawler, WebToEpub, FMD2, Comics Downloader, HDoujinDownloader); calibre's download plugins; Kindle jailbreak tools; and manga-reader extensions that pull from third-party sites, such as Suwayomi's extension index.

### F5. Harvest database and automated collection workflow

**Focus:** comics, manhwa and graphic novels first, then prose. Content collection runs in its own system, separate from the production app, as the master spec requires (metadata aggregation and content distribution are kept apart). It downloads automatically from approved sources. Publishing to readers still goes through an admin's Verify click.

#### Infrastructure

- **A separate Neon project,** for example bookflix-harvest, rather than a branch of production. It gets its own credentials and compute, so a runaway job can't touch production data or slow it down. Neon holds metadata only.
- **A separate private bucket,** for example bookflix-harvest-raw, for downloaded files. Verified files are copied to the production bucket on promotion; production never reads from the harvest bucket directly.
- **A harvester process** built on the same stack (Laravel scheduler plus queue workers, with Horizon behind admin auth) and deployed as its own service, so it scales and fails independently of the reader-facing app.

#### Schema (starting point)

| Table | Purpose and key columns |
|---|---|
| sources | name, base_url, access_type (api, opds, bulk_dump, mirror), license_policy, jurisdiction, rate_limit_per_minute, terms_checked_at, approved_by, approved_at, enabled |
| harvest_runs | source_id, started_at, finished_at, status, counts (fetched, new, updated, failed), error |
| raw_records | source_id, external_id, payload (jsonb), checksum, fetched_at; unique on (source_id, external_id) |
| files | raw_record_id, format, source_url, storage_key, sha256, bytes, detected_mime, scanned_at, status (queued, downloaded, failed, quarantined) |
| license_evidence | raw_record_id, license, rights_statement, evidence_url, jurisdiction, auto_assessment, reviewer, decision, decided_at |
| promotions | raw_record_id, bookflix_media_id, promoted_by, promoted_at |

#### The automatic workflow

- **Schedule.** The scheduler starts one catalog job per enabled source on that source's cadence (for example, nightly). Runs are incremental: use the source's change feeds, ETags or modified-since dates instead of re-downloading everything.
- **Fetch and normalize** catalog records into raw_records, then map them to the Bookflix media model (title, creators, language, subjects, identifiers).
- **License gate.** A record's file is downloaded only if its license or rights statement matches that source's license_policy (for example, Gutenberg's US public-domain flag, or an Internet Archive public-domain or Creative Commons marker). Anything ambiguous goes to review without a download.
- **Download and validate.** Fetch files from the source's official download or mirror URLs, then check type by content, size limits, archive safety (zip bombs, path traversal) and checksums, and run a malware scan. Failures are quarantined, never deleted silently.
- **Deduplicate and enrich** against what's already harvested and what's in production, using identifiers, normalized title and author, and checksums. Then add metadata from the metadata-only providers.
- **Hand off for verification.** Ready items appear on the admin Reading files screen (through an internal API with a service token) with their license evidence. When an admin clicks Verify, a promotion job copies the file to the production bucket and creates or updates the media and reading-file records.

#### Being a good citizen of each source

- Respect robots policies, terms and per-source rate limits. Prefer official bulk files, mirrors and APIs over page scraping.
- Send a descriptive User-Agent with a contact address from .env.
- Use exponential backoff and stop a source after repeated failures (a circuit breaker), then alert the admin. The current Overview already shows a failing sync; the harvester shouldn't add more silent failures.

#### Rollout

- Pilot with a comics source first: Comic Book Plus or Digital Comic Museum, whichever passes the F3 vetting checklist for automated access. If neither allows automated downloads, pilot with Project Gutenberg and handle those comics sources as admin-run manual imports instead. Use a staging harvest database and a batch of about 50 titles.
- Comics sources to add after the pilot, each through the F3 checklist: Internet Archive's public-domain comics collections, and Creative Commons webcomics (for example Pepper&Carrot, which is CC BY). Respect license terms: a non-commercial (NC) license only works if Bookflix stays non-commercial, so record the license and check before publishing.
- Report counts, failures, duplicates and classification results, then wait for approval before adding sources, raising batch sizes, deploying the harvester, or promoting anything to production.
- Auto-approving publication for sources with clear machine-readable licenses (skipping the manual Verify click) is the owner's call. Default it to off.
- Watch storage and egress costs. Set a per-source cap on files and total size, and report projected costs before scaling up.

### F6. Your own files: bring-your-own library

Users can upload DRM-free comics, manhwa, graphic novels and books they own (bought from DRM-free stores such as Humble Bundle, publisher shops or creators' own stores, or downloaded legally) and read them in Bookflix with the same reader, progress tracking and shelves as everything else. Kavita and Komga are good references for how this should feel.

- **Formats:** CBZ, CBR (converted to CBZ on upload), EPUB and PDF. Accept bulk uploads.
- **Large files:** upload directly to private storage with presigned URLs and chunked or resumable uploads, rather than through Livewire's temporary upload limit. Show per-file progress and clear errors.
- **DRM:** detect DRM-protected EPUBs (encryption beyond font obfuscation) and reject them with a plain explanation. Bookflix never offers, links to or explains DRM removal.
- **Matching:** read ComicInfo.xml from CBZ files, EPUB metadata and the filename (series, volume, chapter), match the upload to the catalog record where possible, and group volumes and chapters into series in reading order. Let the user fix a wrong match.
- **Private by default and always:** uploads are visible only to the uploader. They never appear in the public catalog, search results, shelves or other users' recommendations, and they're served only through signed URLs after an ownership check.
- **Quota and limits:** a per-user storage quota shown in Settings, plus a per-file size limit, both configurable.
- **Terms:** the upload screen states that users may only upload files they have the right to use. Add a takedown contact to the terms and privacy pages.
- **Schema:** a user_uploads table (user_id, media_id nullable, original_filename, storage_key, format, bytes, sha256, page_count, status, metadata jsonb). Reading progress uses the existing reading_progress table so Continue Reading treats uploads like any other title.
- **No import-from-URL feature.** It would turn Bookflix into a downloader for exactly the sites in F4.

### F7. Official platforms: track in Bookflix, read with one tap

Most licensed manhwa and many comics live on official platforms that don't allow other apps to host their episodes. Bookflix can't host them, but it can be the single place where a reader follows those series, sees what's new, and jumps straight into the right episode.

- **Platforms to support,** each confirmed for current URL structure and terms before building: Webtoon, Tapas, Tappytoon, Lezhin, Manta, MANGA Plus, Shonen Jump, K Manga, Comikey, GlobalComix, Marvel Unlimited, DC Universe Infinite and Kindle/comiXology. The owner can add or drop platforms later.
- **Data:** platform_links (media_id, platform, series_url, episode_url_template where the platform's URLs allow it, regions available) and user_follows (user_id, media_id, platform, last_episode_read, notify).
- **One tap to the right place:** "Continue on Webtoon, Episode 43" opens that exact episode in a new tab, or in the platform's own app on phones through its normal web link. When there's no stable episode URL, link to the series page and say so.
- **Keeping progress honest:** Bookflix can't see what happens on another platform, so when the user comes back, ask once: "Did you finish Episode 43?" One tap updates progress. The user can also set the episode manually.
- **New episodes:** use official RSS feeds or APIs where a platform provides them, and release data from metadata providers (MangaUpdates, AniList) otherwise. Don't scrape platform pages against their terms. Notify followers in-app, and by email only if they opt in.
- **One library view:** hosted titles, the user's uploads and followed external series all appear together on Library and Continue Reading, with a small label saying where each one is read ("Reads in Bookflix", "Your file", "On Tapas").
- **Branding:** show platform names as text, or use logos only under each platform's published brand guidelines.

### F8. Creator and publisher licensing

To host more licensed manhwa and comics directly, Bookflix needs permission from the people who own them. Indie creators and small publishers are the realistic starting point, which is how GlobalComix built its catalog.

- **"Publish on Bookflix" page:** an application form for creators (name, works, links, rights they hold). Submissions land in a new admin review queue under Catalog.
- **License records:** a content_licenses table (licensor, works covered, rights granted such as stream only or stream and download, territories, start and end dates, revenue terms, signed agreement file, approved_by). Upload of licensed files is admin-only for now; a creator self-upload portal can come later.
- **Enforcement in code:** the reader checks that a licensed work's agreement is active and covers the reader's region before serving it. A scheduled job unpublishes works whose license has expired and alerts the admin a few weeks beforehand.
- **Contracts are the owner's job.** Don't draft license agreements or revenue terms; build the records and the enforcement, and leave the legal text to the owner and their advisor.

### F9. Book reviews and ratings from other websites

Don't scrape review text from Goodreads, Amazon, The StoryGraph or similar sites. Their terms forbid it, and each review belongs to the person who wrote it. Collect only what sources make available for reuse, and always show where it came from:

- **Aggregate ratings from open APIs:** for example, Open Library's ratings and reading-log counts. Display them as "4.1 on Open Library (2,345 ratings)" with a link.
- **Professional reviews by link:** for example, the New York Times Books API, which returns review links and short summaries under its own terms (API key, attribution). Store the link, the publication, the date and any summary the terms allow, and send readers to the original.
- **Openly licensed reception text:** Wikipedia's reception sections for classics, under CC BY-SA, with attribution and a link.
- **Before adding any other source** (for example Hardcover or BookWyrm), check that its API terms allow reuse and display, and get the owner's approval, the same as F3.

Store these in the harvest database (external_ratings, external_review_links) and promote them with the book. Keep them visually separate from Bookflix readers' own reviews, which remain the only full-text reviews on the site. Never generate or seed reviews with AI or present invented reviews as real.

### F10. UI/UX reference collection

Before starting Part A, build a small reference library in docs/design-references/. For each reference, save a screenshot, the URL, the date, and two or three sentences on which pattern you're borrowing and why. Cover:

- **Settings pages** (A3): single-page settings with section navigation, toggles and confirmation dialogs. Design-system docs such as GitHub Primer, Vercel Geist, Shopify Polaris and Material 3 explain their patterns, not just show them.
- **Admin tables and dashboards** (A4, A5): filter toolbars, pagination, stat cards beside charts, and work queues.
- **Reading UI** (F1): the open-source readers in F2, such as Readest, Thorium and Koodo, for reader controls and typography settings.
- **Streaming-style browsing:** shelf rows, cover tiles and hero banners, kept within the Netflix-inspired tokens already in the master spec.

Borrow patterns, not assets: no copied logos, illustrations, icons or fonts. Use only pages that are publicly visible without logging in. Keep screenshots internal; they don't ship with the app.

## Part G. Motion and animation

Motion should explain what changed, not decorate. Animate only transform and opacity. Keep durations short (micro-interactions 100–150ms, UI changes 200–250ms, pages and modals 250–350ms) and define durations and easing as tokens. Honor prefers-reduced-motion everywhere by switching to instant changes or a plain fade. Nothing may cause layout shift.

- **Hover and tap states.** Buttons, links, cards and cover tiles respond to hover, focus and press with a color step and a slight scale (cover tiles about 1.03–1.05; buttons press to about 0.98). The design system has no drop shadows, so lift elements with a surface-color step and scale instead. Every hover-only affordance needs a touch and keyboard equivalent.
- **Micro-interactions.** Toggles slide with a color change, fields confirm valid input, saving shows a brief check mark, icons change with state (accordion chevrons rotate, the favorite heart fills), and buttons have a subtle press.
- **Loading states and skeletons.** Every Livewire action gets a wire:loading state (spinner plus disabled button). Shelves, story pages, tables and charts get skeleton blocks shaped like the real content, plus a thin top progress bar for page navigation. Show skeletons only when loading takes longer than about 300ms so they don't flicker.
- **Scroll reveals and parallax.** Sections and shelves fade and rise a short distance (8–16px) once, the first time they enter the viewport. Don't animate every element. Use subtle parallax on the hero banner or featured story only, and turn it off under reduced motion.
- **Page and modal transitions.** Dialogs and drawers fade and scale, or slide in from their origin, with a backdrop fade (Alpine x-transition). Focus moves into them and returns on close. If wire:navigate is in use, add a quick cross-fade between pages.

**Note:** Scroll reveals overlap with "fade-up-on-scroll" in the design-tells list (Part H). The difference is restraint: a few purposeful reveals, a small distance, once. If the site already fades everything up, scale it back.

## Part H. Design-tells audit (report, don't redesign)

Review the UI against this list and report each finding with a screenshot or file reference and a recommended fix. Change only what Parts A–G already require; anything else waits for the owner's decision.

- Overuse of glassmorphism, "pill" badges above headlines, generic rounded cards, bento-grid layouts, default Lucide icons, fade-up-on-scroll on everything, or hover effects with nothing behind them.
- Space Grotesk as the only font doing any work; suggest a better pairing. Also check the font itself: the design system names Netflix Sans, which is Netflix's proprietary typeface and isn't licensed for other products. Report what's actually loaded and propose a licensed alternative with a pairing.
- A vague or generic hero that doesn't say what Bookflix actually does.
- No live product demo anywhere, such as a browsable sample shelf or a public-domain book a guest can open.
- A text-only logo, a default Vercel or Lovable URL or badge, or a one-page structure that looks unfinished rather than intentional.
- Fake-looking numbers: visitor or customer counts, a "Top 10", or stats that aren't backed by real data. Every number on the landing page should come from a query.
- Heavy em-dash use, or cursive and script fonts in the copy or UI, that read as AI-generated.

## Part I. Definition of done and the report

Before you call the session complete:

- php artisan test passes, with new Pest tests covering admin route and Livewire action authorization, form success and error paths, the FAQ accordion, the 404 page, signed reader URLs, and the AI self-harm response.
- Laravel Dusk runs the key flows at 375×667, 768×1024 and 1280×800 and asserts there's no horizontal scroll (document.documentElement.scrollWidth <= window.innerWidth).
- Uploads tests: one user can never fetch another user's upload (direct URL, signed URL reuse, Livewire action); DRM-protected EPUBs are rejected; quota limits hold.
- Licensing and platform tests: an expired or out-of-region license blocks reading; the next-chapter prompt opens the right chapter; followed-series progress updates from the return prompt.
- Harvester tests cover the license gate (an ambiguous record is never downloaded), deduplication, quarantine of bad files, and the promotion step.
- PHPStan (or Psalm) passes at the project's configured level.
- No secrets appear in the diff.

Finish with a report in this shape:

| Item | Status | What changed (files) | Notes |
|---|---|---|---|
| e.g. A2 FAQ two-column layout | Fixed | faq.blade.php, Faq.php | Default-open card verified on mobile |
| e.g. C4 Vercel Analytics | Needs decision | None | App isn't hosted on Vercel; options listed |

Status is one of Fixed, Already OK, Not applicable, or Needs decision. After the table, list the open questions for the owner, every TODO you left in the code, and anything out of scope that's worth knowing (for example, the failing sync and the unavailable ML service visible in the admin screenshots).
