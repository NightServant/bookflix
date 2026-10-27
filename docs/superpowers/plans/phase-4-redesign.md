# Phase 4 redesign direction

User brief (2026-10-27): cool 3D objects where they help; icons in the general button components; every page explains what to do right after its main heading; don't hold back, beat the Netflix design system.

This direction governs the remaining design items in `phase-4-review.md` (1–3, 6–19) and the new requests. The review items stay the floor; this is the ceiling.

## 1. Identity: books are objects, not posters

Netflix treats every title as a flat 16:9 poster. Bookflix's advantage is that a book is a physical object. The redesign leans into that:

- **`<x-book-3d>`**, a CSS-only 3D book. It has a front cover (the real cover image), a spine (darkened cover edge plus a hinge highlight), a page block (fore-edge stripes in `--color-paper`) and a soft contact shadow.
  - Props: `media` or `src`, `size` (`sm|md|lg|xl`), `tilt` (resting yaw, default `-18deg`), `interactive` (pointer-follow tilt on fine pointers, max ±10° over 400 ms with `--ease-out-bookflix`).
  - No WebGL and no new dependency: `transform-style: preserve-3d` plus `perspective`, so it costs nothing for Lighthouse.
  - With `prefers-reduced-motion`, there is no pointer tilt; the book keeps its static resting angle.
  - A broken or missing cover falls back to `<x-cover-fallback>` on the front face.
- **Where it's used.** 3D goes only where the object is the subject. Shelves and grids stay flat, fast and scannable.
  - Home hero: the right-hand slide picks become a fanned stack of 3 books, and the active one comes forward.
  - Story header: the cover becomes a large 3D book on the cover-backdrop band.
  - Series header: the first 3–5 volumes stand as a staggered spine-out stack.
  - Discover header band: a 3-book collage.
  - Empty states (no results, empty catalog, empty shelf): one tilted book with a short line and a way out.
- **Ambient colour.** Detail pages and the hero tint their band from the cover. The blurred cover goes at very low opacity behind a `bg-surface-deep` scrim, never as a smudge (design item 1).

## 2. Depth and surfaces

- Re-introduce a small elevation scale; the theme currently wipes all shadows. Use three tokens:
  - `--shadow-raised`, for the 3D books' contact shadow.
  - `--shadow-overlay`, for popovers, the palette and sheets.
  - `--shadow-glow-brand`, a faint red bloom under the primary CTA on hover only.
- The header is translucent over bands: `bg-canvas/70 backdrop-blur-md` with the hairline bottom border, and opaque when there is no band under it.
- Cards: `bg-surface-card` with a 1 px hairline border, and an inner top highlight (`inset 0 1px 0 rgb(255 255 255 / .04)`) for tactility.
- New token `--color-paper: #e9e4d8` for page edges only. Never use it for text or surfaces.

## 3. Buttons with icons

- `<x-cta>` gains an `icon` prop (a Flux/Heroicons name, leading, `size-5`) and an `icon:trailing` prop for directional actions. Each call site picks the icon that names the action:

  | Action | Icon |
  |---|---|
  | View story | `book-open` |
  | See volume 1 | `book-open` |
  | Add to Library | `plus` |
  | Browse Discover | `compass` |
  | Explore the Library | `building-library` |
  | See all | `arrow-right` (trailing) |
  | Filters | `adjustments-horizontal` |
  | Clear filters | `x-mark` |
  | Search | `magnifying-glass` |
  | Retry | `arrow-path` |
  | Save | `check` |
  | Delete | `trash` |
  | Sign in | `arrow-right-end-on-rectangle` |

- Icon-only buttons always carry an `aria-label` and keep the 44 px target.
- Flux buttons used directly (settings, admin, auth) get the same icon treatment.

## 4. Every page guides

- A new `<x-page-header>` takes `title`, `description`, an optional `eyebrow`, and an `actions` slot.
  - The h1 uses the one page-title scale (design item 3).
  - The description is required, at most 2 sentences, in `text-body text-ink-subtle max-w-prose`. It tells the reader what the page is for and what to do next, in plain words.
  - Examples:
    - Discover: "Browse the whole catalog by format, genre and subject. Pick a shelf to start, or filter for something specific."
    - Search: "Search titles, authors, series and subjects. Narrow the results with the filters."
    - Settings > Profile: "Update the name and email on your account."
- Applies to every routed page: Home (the hero line plays this role), Discover, Search, Explore index and class, Story, Series, Settings (profile, security, appearance, privacy), Admin (all), and Auth (each form says what happens next).
- Section headings (h2) on long pages get a one-line `text-meta text-ink-subtle` helper where the section isn't self-evident, e.g. "Explore by Subject — Dewey classes, from the 000s to the 900s."

## 5. Type, rhythm and controls

These follow review items 3, 10, 15, 17 and 19, unchanged, plus:
- `--text-title: 2.5rem / 800 / 1.1` for detail-page h1s. `text-hero` stays only on Home's hero.
- One gap scale for sections: `mt-12 lg:mt-16`. Inside a section, heading to content is `mt-4`.

## 6. Guardrails

- WCAG AA contrast on every text, including text over cover-tinted bands, where the scrim guarantees ≥ 4.5:1.
- The existing browser accessibility and reduced-motion tests stay green. New 3D and motion code adds no running animation under reduced motion.
- No new runtime dependencies. Icons come from Flux's bundled Heroicons.
- Every visual change ships with before/after screenshots at 1440 and 390 px.
