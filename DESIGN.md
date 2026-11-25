---
name: Bookflix
description: A dark, cinematic reading catalog where covers are the art, red is reserved for actions and a navy-to-maroon gradient marks what is featured.
colors:
  canvas: "#000000"
  surface-deep: "#161616"
  surface-card: "#232323"
  surface-input: "#2d2d2d"
  hairline: "#414141"
  border-input: "#6d6d6e"
  ink: "#ffffff"
  ink-subtle: "#b3b3b3"
  ink-muted: "#808080"
  brand: "#e50914"
  brand-hover: "#c11119"
  link: "#448ef4"
  accent-electric: "#4061e7"
  success: "#46d369"
  featured-from: "#192247"
  featured-via: "#461518"
  featured-to: "#6f181d"
  reader-dark: "#0a0a0a"
  reader-dark-ink: "#ebebeb"
  reader-sepia: "#211c14"
  reader-sepia-ink: "#e8dcc6"
  paper: "#e9e4d8"
typography:
  hero:
    fontFamily: "Netflix Sans, Helvetica Neue, Segoe UI, Roboto, Ubuntu, sans-serif"
    fontSize: "3.5rem"
    fontWeight: 900
    lineHeight: 1.1
  title:
    fontFamily: "Netflix Sans, Helvetica Neue, Segoe UI, Roboto, Ubuntu, sans-serif"
    fontSize: "2.5rem"
    fontWeight: 800
    lineHeight: 1.1
  section:
    fontFamily: "Netflix Sans, Helvetica Neue, Segoe UI, Roboto, Ubuntu, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 500
  cta:
    fontFamily: "Netflix Sans, Helvetica Neue, Segoe UI, Roboto, Ubuntu, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 500
  lead:
    fontFamily: "Netflix Sans, Helvetica Neue, Segoe UI, Roboto, Ubuntu, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 500
  body:
    fontFamily: "Netflix Sans, Helvetica Neue, Segoe UI, Roboto, Ubuntu, sans-serif"
    fontSize: "1rem"
  meta:
    fontFamily: "Netflix Sans, Helvetica Neue, Segoe UI, Roboto, Ubuntu, sans-serif"
    fontSize: "0.875rem"
  legal:
    fontFamily: "Netflix Sans, Helvetica Neue, Segoe UI, Roboto, Ubuntu, sans-serif"
    fontSize: "0.8125rem"
rounded:
  control: "4px"
  card: "8px"
  brand: "16px"
  full: "9999px"
spacing:
  gutter-base: "1rem"
  gutter-md: "2rem"
  gutter-lg: "4rem"
  gutter-desktop: "148px"
  touch-target: "44px"
components:
  cta-primary:
    backgroundColor: "{colors.brand}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    height: "44px"
    padding: "8px 20px"
  cta-primary-hover:
    backgroundColor: "{colors.brand-hover}"
  cta-light:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.canvas}"
    rounded: "{rounded.control}"
    height: "44px"
  cta-secondary:
    backgroundColor: "{colors.surface-input}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    height: "44px"
  chip:
    backgroundColor: "{colors.surface-input}"
    textColor: "{colors.ink}"
    rounded: "{rounded.full}"
    height: "36px"
    padding: "0 12px"
  chip-active:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.canvas}"
  shelf:
    backgroundColor: "{colors.surface-deep}"
    rounded: "{rounded.card}"
    padding: "24px"
  cover-tile:
    backgroundColor: "{colors.surface-card}"
    textColor: "{colors.ink}"
    rounded: "{rounded.card}"
  settings-card:
    backgroundColor: "{colors.surface-card}"
    rounded: "{rounded.card}"
    padding: "24px"
  input:
    backgroundColor: "{colors.surface-input}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    height: "44px"
  switch:
    backgroundColor: "{colors.surface-input}"
    rounded: "{rounded.full}"
    height: "24px"
    width: "40px"
  switch-on:
    backgroundColor: "{colors.ink}"
---

# Design System: Bookflix

## 1. Overview

**Creative North Star: "The Dim Screening Room"**

Bookflix is a dark-only product. The canvas is pure black, covers are the only imagery and everything else steps back so they can glow. It borrows the feel of a streaming service (full-bleed bands, shelves that scroll sideways, a confident short voice) without copying one. PRODUCT.md puts it as "cinematic, premium, confident", and the system holds that by restraint: one focal point and one primary action per screen, standard controls at standard sizes, and colour that means something.

Colour is rationed. Red marks calls to action and nothing else. The featured gradient (navy to maroon to dark red) marks the moments the product wants to celebrate. Focus is always electric blue, so it never competes with either. Depth comes from tonal layering (black, then three greys), with exactly three shadow tokens.

The system rejects what PRODUCT.md rejects: generic AI or SaaS templates, cheap themes, and any Netflix asset (inspiration only, no Netflix logos, posters or Netflix Sans files).

**Key Characteristics:**
- Dark-only, tonal layering, covers as the art.
- Red only for CTAs; the featured gradient only where something is featured.
- 44px targets and one electric-blue focus ring on every interactive element.
- Full-bleed bands on the page gutter; no inset boxes where a band should run edge to edge.
- Motion only to show state, and always off under prefers-reduced-motion.

## 2. Colors

A black canvas with three stepped greys, white ink, one red for actions and a three-stop gradient for celebration.

### Primary
- **Cinema Red** (`brand`, `brand-hover` for hover): the fill of the primary `<x-cta>` and the logo mark. Never small text, never decoration, never a border or ring. Flux's accent is mapped to it so `variant="primary"` is the red button.

### Secondary
- **Featured Gradient** (`featured-from` navy, `featured-via` maroon, `featured-to` dark red): `bg-linear-to-r from-featured-from via-featured-via to-featured-to`, left to right. Allowed only on: the Trending Now shelf (`<x-shelf featured>`), the profile banner, the landing Top 10 band, the sign-in and register panel, Discover's header band and Ask's empty stage (the vertical `bg-featured-rise`). Nowhere else. Ask's empty stage uses the vertical variant, `bg-featured-rise` (app.css): the same three colours rising from the foot of the surface into the canvas, as a whole-surface wash with no hard edge.

### Tertiary
- **Electric Blue** (`accent-electric`): the focus ring and nothing else (`focus-ring`: 2px ring, 2px offset in canvas).
- **Link Blue** (`link`): inline text links, "See all", review-body links.
- **Success Green** (`success`): confirmation states only.

### Neutral
- **Canvas** (`canvas`): page background, header, reader chrome.
- **Surface Deep** (`surface-deep`): shelves, the auth side panel, admin sidebar on small screens.
- **Surface Card** (`surface-card`): cards, settings groups, menus, cover placeholders.
- **Surface Input** (`surface-input`): inputs, chips, secondary buttons, switch track, hover fill.
- **Hairline** (`hairline`): every border and divider (the base border colour).
- **Input Border** (`border-input`): 3:1 outline of form controls and the switch.
- **Ink** (`ink`): primary text, and the selected state of chips, checkboxes and switches.
- **Ink Subtle** (`ink-subtle`, 4.5:1): secondary text, descriptions, eyebrows.
- **Ink Muted** (`ink-muted`): disabled controls and decoration only, never text people must read.
- **Reader themes** (`reader-dark`, `reader-sepia` and their inks) and **Paper** (page edges of `<x-book-3d>` only): scoped to the reader and the 3D book.

### Named Rules
**The Red For CTAs Only Rule.** Red appears on `<x-cta variant="primary">` (one per view) and the logo mark. A test greps component views for `brand` utilities and fails on any other file. Settings save buttons are not red.

**The Grey Never Rides Gradient Rule.** On the featured gradient, text is `ink` or `ink` at reduced opacity (for example `text-ink/80`). Never `ink-subtle`, `ink-muted` or any grey: a grey on a mid-dark gradient drops below 4.5:1 and looks dirty. Links on it are `text-ink underline`, not `text-link`.

**The Token Only Rule.** Component views use tokens, never hex, `rgb()` or `hsl()`. A test enforces it.

**The Earned Gradient Rule.** If a band is not featuring something, it is `surface-deep` or canvas, not the gradient.

## 3. Typography

**Font:** Netflix Sans, falling back to Helvetica Neue, Segoe UI, Roboto, Ubuntu, sans-serif. One family, hierarchy by size and weight. The font is named in the token only as inspiration; do not ship its files, the stack is expected to fall through to a system face.

**Character:** Tight, heavy display type over a calm 16px body, so a title reads like a poster and the rest like a caption.

### Hierarchy
- **Hero** (900, 3.5rem, 1.1): Home's hero only.
- **Title** (800, 2.5rem, 1.1): page titles from `<x-page-header>` at `md` and up, tracking tight.
- **Section** (500, 1.5rem): bleed shelf titles, empty-state titles, page titles on small screens.
- **CTA** (500, 1.5rem): large calls to action in bands. Buttons themselves use the 16px label.
- **Lead** (500, 1.25rem): card shelf titles, settings group headings.
- **Body** (400, 1rem): paragraphs, button labels, form text. Descriptions cap at `max-w-prose`.
- **Meta** (400, 0.875rem): chips, tile titles, captions, "See all".
- **Legal** (400, 0.8125rem): tile reasons, badges, eyebrows (uppercase, wide tracking), footers.

### Named Rules
**The Titles Are Title Case Rule.** Shelf titles are Title Case; button labels and helper copy are sentence case.

**The No Small Red Rule.** Red never sets text, at any size.

## 4. Elevation

Flat by default. Layering is tonal: canvas, then `surface-deep`, `surface-card`, `surface-input`. All of Tailwind's stock shadows are reset, so nothing else can cast one. Component views may only use `shadow-none` and these three tokens (enforced by a test).

### Shadow Vocabulary
- **Raised** (`shadow-raised`: `0 18px 28px -14px rgb(0 0 0 / 0.75), 0 6px 10px -6px rgb(0 0 0 / 0.6)`): a book on a surface, such as the contact shadow under `<x-book-3d>`.
- **Overlay** (`shadow-overlay`: `0 24px 48px -12px rgb(0 0 0 / 0.8), 0 0 0 1px rgb(255 255 255 / 0.06)`): popovers, the command palette, menus, sheets.
- **Glow Brand** (`shadow-glow-brand`: `0 8px 32px -6px rgb(229 9 20 / 0.45)`): under the primary CTA, on hover only.

### Named Rules
**The Three Shadows Rule.** If you want a fourth shadow, you want a different surface step instead.

## 5. Components

Standard controls at standard sizes, the same vocabulary on every screen. Every interactive element wears `focus-ring`.

### Buttons (`<x-cta>`)
- **Shape:** 4px radius (`rounded-control`), 44px minimum height reached by padding, 16px medium label, no shadow.
- **Primary:** red fill, white label, `px-5`. One per view. Hover darkens to `brand-hover` and adds the red glow.
- **Light:** white fill, canvas label, for the main action of a view that is not reading (Edit profile, Post review). Hover `zinc-300`.
- **Secondary:** `surface-input` fill, quiet.
- **Danger:** outlined, red-400 label and border at 60%, hover tints 10%. A destructive action, never the red fill.
- **Icon:** outline icons at `size-5`, 10px from the label; icon-only is a 44px square and requires an `aria-label`.
- **Loading:** a Flux spinner shows once the request starts; still under reduced motion.

### Chips (`<x-chip>`)
- **Style:** 36px pill, `text-meta`, `surface-input` fill, a touch target stretched to 44px by an `after` element.
- **State:** hover `hairline`; active is ink fill with canvas text and `aria-current`. Intent chips: applied is outlined with an x, suggested is dashed with a plus.

### Shelves (`<x-shelf>`)
- **Style:** a titled row of tiles on `surface-deep`, 8px radius with 16 to 24px padding, or `bleed`: a full-width band (`py-8 lg:py-12`) whose title and first tile sit on the page gutter while the track runs to the right edge.
- **Featured:** the gradient band, reserved per the colour rule; "See all" turns `text-ink underline`.
- **Behaviour:** snap-scrolling track with an edge fade mask, 44px arrow buttons (hidden on phones, dim when disabled), tiles revealed with a 30ms stagger.

### Cover tiles (`<x-cover-tile>`)
- **Style:** 2:3 cover in an 8px frame on `surface-card`, widths 112, 160 and 224px; a designed fallback when there is no cover; title in `text-meta` clamped to 2 lines; optional reason line in `text-legal ink-subtle`.
- **States:** image scales to 105% on hover (`--motion-fast`), progress is a 4px ink bar on `surface-input`, selectable tiles ring in ink once ticked and in electric blue on focus, a 44px overflow button appears on hover or focus and always on touch.

### Cards and settings groups (`<x-settings-card>`)
- **Style:** `surface-card`, 8px radius, 20 to 24px padding, h2 in `text-lead`, help in `text-meta text-ink-subtle`. The danger group is a dashed hairline border with no fill.

### Inputs and fields
- **Style:** `surface-input` fill, `border-input` outline, 4px radius, 44px minimum height (Flux's 40px is overridden). Labels sit above at 8px.
- **Focus:** the electric-blue 2px ring with a canvas offset. The search input is a single 640 to 720px field (`max-w-2xl`, 672px) and never full-bleed.
- **Switch:** 24 x 40 pill with a 1px border in both states and an 18px knob inset 3px. Off is `surface-input` with an ink-subtle knob; on is white with a black knob. 150ms colour and slide, instant under reduced motion. Never red.
- **Checkbox (filter-check):** 20px square; ink fill with a canvas tick when checked, a dash when mixed, outline with a quiet tick when only implied.

### Navigation
- **Header:** canvas, hairline bottom border, logo on the gutter, navbar from `lg`, command palette and user menu on the right. Over a band it turns translucent (`bg-canvas/70`, blurred) and the band runs under it. A bottom nav serves small screens. A skip link parks above the viewport and shows at the logo's gutter on focus.
- **Admin shell** (`layouts/admin`): a sticky sidebar with grouped links (legal-size uppercase headings) and an account block, a drawer on small screens; none of the reader chrome.
- **Auth** (`layouts/auth/split`): two columns from `lg`, a showcase panel on the featured gradient on the left, and a max-w-md `surface-card` form on the right.

### Page header and empty state
- **Page header:** the one page-title scale, then a plain two-sentence line in `text-body text-ink-subtle`, actions right-aligned. The `featured` prop is for a header on the featured gradient: the quiet lines are `text-ink/80`, never grey on the colour.
- **Empty state:** a tilted 3D book from a real cover, a `text-section` title and a way out.

### Layout and motion
- **Gutters:** 16px, 32px at 48rem, 64px at 64rem, 148px at 90rem (`px-gutter`). Bands span the full width; content aligns to the gutter.
- **Motion:** tokens 150ms (fast), 250ms (base), 400ms (slow) with `cubic-bezier(0.2, 0, 0, 1)` (`ease-out-bookflix`). Used for state only: hover scale, fades, the hero cover morph (view transition), the book's lean. Every transition is removed under `prefers-reduced-motion`.

## 6. Do's and Don'ts

### Do:
- **Do** use red only for the one primary `<x-cta>` per view and the logo mark.
- **Do** put the featured gradient only on Trending Now, the profile banner, the landing Top 10 band, the sign-in and register panel, Discover's header band and Ask's empty stage (the vertical `bg-featured-rise`).
- **Do** set text on the gradient in `ink` (or `ink` at reduced opacity).
- **Do** give every control a 44px target and the electric-blue `focus-ring`.
- **Do** let bands run edge to edge and align their content to `px-gutter`.
- **Do** design the missing-cover fallback for every tile.
- **Do** use tokens and the three shadows; check text is 4.5:1 and UI parts 3:1.

### Don't:
- **Don't** use generic AI or SaaS template patterns: gradient blobs, glassmorphism, emoji, icon-in-circle feature rows, centered-everything layouts, filler copy.
- **Don't** make cheap themes: oversized buttons, full-bleed inputs, or inset rounded boxes where a band should run edge to edge.
- **Don't** use any Netflix asset: no Netflix logos, posters or Netflix Sans files. Inspiration only.
- **Don't** put `ink-subtle`, `ink-muted` or any grey text on the featured gradient.
- **Don't** use red for small text, borders, rings, links, switches or settings save buttons.
- **Don't** add a fourth shadow, a raw hex or a new full-round shape (only chips, step markers and slider thumbs are pills).
- **Don't** animate for decoration or cause layout shift; reserve image sizes.
- **Don't** rely on colour alone to show state.
