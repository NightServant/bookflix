import { animate, stagger } from 'motion';
import noUiSlider from 'nouislider';
import { MOTION, prefersReducedMotion, release } from './motion';

/**
 * Alpine data for the component kit (resources/views/components). Each name matches `x-data="<name>"` in its Blade view.
 * Registered as a plugin so it lands before Livewire.start() boots Alpine.
 */
export default function components(Alpine) {
    // x-edge-fade: marks a horizontally scrolling element with `data-fade-start` once it has been scrolled away from its start and
    // `data-fade-end` while there is more to scroll to; the `edge-fade` utility (app.css) turns them into a mask that dissolves
    // that edge. Decorative only: it changes no markup assistive tech reads. Updated on scroll (passive, one frame at a time)
    // and when the element is resized or Livewire morphs it (tiles added or removed change what there is to scroll to without resizing it).
    Alpine.directive('edge-fade', (el, _, { cleanup }) => {
        let frame = 0;

        const update = () => {
            frame = 0;
            el.toggleAttribute('data-fade-start', el.scrollLeft > 1);
            el.toggleAttribute('data-fade-end', el.scrollLeft + el.clientWidth < el.scrollWidth - 1);
        };
        const queue = () => {
            frame ||= requestAnimationFrame(update);
        };
        const observer = new ResizeObserver(queue);
        const unhook = window.Livewire?.hook('morph.updated', ({ el: morphed }) => morphed === el && queue());

        el.addEventListener('scroll', queue, { passive: true });
        observer.observe(el);
        queue();

        cleanup(() => {
            cancelAnimationFrame(frame);
            observer.disconnect();
            unhook?.();
            el.removeEventListener('scroll', queue);
        });
    });

    // <x-shelf>: scroll-snap track with prev/next arrows that disable at the ends.
    // ponytail: left-to-right scrolling only (every supported language reads LTR); add RTL maths if one is ever added.
    Alpine.data('shelf', () => ({
        atStart: true,
        atEnd: true,

        get canScroll() {
            return !(this.atStart && this.atEnd);
        },

        update() {
            const track = this.$refs.track;

            this.atStart = track.scrollLeft <= 1;
            this.atEnd = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1;

            // A button that disables while focused would drop keyboard focus to the page; hand it to the other arrow.
            this.$nextTick(() => {
                const { prev, next } = this.$refs;

                if (document.activeElement === next && next.disabled) {
                    prev.focus();
                } else if (document.activeElement === prev && prev.disabled) {
                    next.focus();
                }
            });
        },

        go(direction) {
            const track = this.$refs.track;

            track.scrollBy({ left: direction * track.clientWidth * 0.9, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
        },
    }));

    // <x-hero>: one story at a time over its cover art, the next three as cards that jump to their slide.
    // A change morphs the chosen card into the backdrop (View Transitions), slides the remaining cards one place
    // (Motion transform) and reveals the new title word by word. Reduced motion swaps instantly; there is no autoplay.
    Alpine.data('hero', (cards) => {
        // Kept outside Alpine's scope: a card handler runs in the card's own scope, and `$root` stops resolving there
        // once that card has left the DOM.
        let root;

        const backdrop = (index) => root.querySelector(`[data-backdrop="${index}"]`);

        return {
            cards, // [{ id, title, cover }] for every slide, in order
            current: 0,
            fade: 0, // bumped to replay x-motion-crossfade on the backdrops where View Transitions are missing

            init() {
                root = this.$root;

                // The desktop centrepiece is one 3D book: its CSS cover follows `active` (bound in the markup), and when the three.js
                // scene is live it turns its cover like a leaf to the new one.
                this.$watch('current', (index) => {
                    const src = this.cards[index]?.scene;

                    src && root.querySelector('[data-hero-books] [data-scene-cover]')?.dispatchEvent(new CustomEvent('book-scene:turn', { detail: { src } }));
                });
            },

            get active() {
                return this.cards[this.current];
            },

            get count() {
                return this.cards.length;
            },

            // The next three after the current one, in the order they will be reached.
            get upcoming() {
                return Array.from({ length: Math.min(3, this.count - 1) }, (_, offset) => {
                    const index = (this.current + 1 + offset) % this.count;

                    return { ...this.cards[index], index };
                });
            },

            next() {
                this.show((this.current + 1) % this.count);
            },

            prev() {
                this.show((this.current - 1 + this.count) % this.count);
            },

            show(index) {
                if (index === this.current) {
                    return;
                }

                if (prefersReducedMotion()) {
                    this.current = index;
                    this.$nextTick(keepFocus);

                    return;
                }

                const first = cardPositions();
                const listed = root.querySelector(`[data-card="${this.cards[index].id}"] img`);
                const source = listed?.offsetParent ? listed : null; // the desktop fan has no flat card to morph

                const swap = async () => {
                    this.current = index;
                    await this.$nextTick();

                    keepFocus();
                    slideCards(first);
                    revealTitle(index);
                };

                if (!document.startViewTransition) {
                    this.fade++;
                    swap();

                    return;
                }

                // The card (old state) and the new backdrop (new state) share a name, so the browser morphs one into the other.
                const clear = () => {
                    delete document.documentElement.dataset.heroTransition;
                    source?.style.removeProperty('view-transition-name');
                    backdrop(index)?.style.removeProperty('view-transition-name');
                };

                document.documentElement.dataset.heroTransition = '';
                source?.style.setProperty('view-transition-name', 'hero-cover');

                document
                    .startViewTransition(async () => {
                        await swap();
                        backdrop(index)?.style.setProperty('view-transition-name', 'hero-cover');
                    })
                    .finished.then(clear, clear);
            },
        };

        function cardPositions() {
            return new Map([...root.querySelectorAll('[data-card]')].map((card) => [card.dataset.card, card.getBoundingClientRect()]));
        }

        // FLIP: cards that were already showing glide from where they were; a new one enters from the right.
        function slideCards(first) {
            root.querySelectorAll('[data-card]').forEach((card) => {
                const before = first.get(card.dataset.card);
                const distance = before ? before.left - card.getBoundingClientRect().left : card.getBoundingClientRect().width;
                const keyframes = before ? { x: [distance, 0] } : { x: [distance, 0], opacity: [0, 1] };

                animate(card, keyframes, { duration: MOTION.base / 1000, ease: 'easeOut' }).then(release([card]));
            });
        }

        // Each word rises out of its own overflow-hidden mask, 40ms after the one before it.
        function revealTitle(index) {
            const words = [...root.querySelectorAll(`[data-slide="${index}"] [data-word]`)];

            words.forEach((word) => (word.style.transform = 'translateY(100%)'));
            animate(words, { y: ['100%', '0%'] }, { duration: MOTION.base / 1000, ease: 'easeOut', delay: stagger(0.04) }).then(release(words));
        }

        // A card that became the backdrop leaves the page with the focus on it; hand focus to the next one up.
        function keepFocus() {
            if (!root.contains(document.activeElement)) {
                (root.querySelector('[data-card] button') ?? root.querySelector('[data-hero-next]'))?.focus();
            }
        }
    });

    // <x-accordion>: a native <details> whose panel collapses with x-collapse. Without JS it is still a plain disclosure.
    Alpine.data('accordion', (open) => ({
        expanded: open,
        closing: false,

        toggle() {
            const details = this.$root;

            if (details.open && this.expanded) {
                // The browser hides the content the moment `open` goes, so let the collapse play before dropping it.
                this.expanded = false;
                this.closing = true;
                setTimeout(() => {
                    this.closing = false;
                    this.expanded || (details.open = false);
                }, prefersReducedMotion() ? 0 : MOTION.base);
            } else {
                details.open = true;
                this.expanded = true;
            }
        },

        // The browser opens and closes it too (an exclusive `name` group closing a sibling): follow its state.
        // Its `toggle` events arrive a frame late, so ignore them while our own close is in flight.
        sync() {
            this.closing || (this.expanded = this.$root.open);
        },
    }));

    // <x-story-previews>: one preview card and one quick view dialog for every <x-story-preview> tile inside. `active` is the tile in use
    // (the card shows it, and the popover plugin's button is its link), `quick` the one the touch dialog shows; a tile keeps its summary in a
    // <template> and `fill` clones it into whichever of the two is asked. `open` is the model of the ui popover around the tiles. It opens 300ms
    // after a mouse rests on a tile or the keyboard reaches it, and closes a moment after both the tile and the card are left. Touch never opens
    // it, and neither does a click's focus (that is not :focus-visible); the popover plugin itself handles Escape, Tab out of the card and focus
    // returning to the tile. The card is not next to its tile in the DOM, so Tab from the tile enters it by hand.
    Alpine.data('storyPreviews', (quickViewName) => {
        // The tile whose link is marked expanded; kept out of the reactive data so the effect that sets it does not re-run itself.
        let lit = null;
        // The element that holds the card. `$root` cannot stand for it: in a tile's handlers it is the nearest x-data, the shelf's own section, which does not hold the card.
        let host = null;

        return {
            open: false,
            active: null,
            quick: null,
            timer: null,

            init() {
                host = this.$el;
            },

            // The tile's own link (or button); the popover plugin focuses it again when the card closes.
            link(tile) {
                const trigger = tile.firstElementChild;

                return trigger.querySelector('a[href], button') ?? trigger;
            },

            // Only the tile with the card open is expanded.
            mark(active, open) {
                const next = open ? active : null;

                if (lit && lit !== next) {
                    this.link(lit).setAttribute('aria-expanded', 'false');
                }

                lit = next;
                lit && this.link(lit).setAttribute('aria-expanded', 'true');
            },

            // Clones a tile's summary into `target`; `titleId` names the dialog's heading.
            fill(target, tile, titleId) {
                if (! tile) {
                    return;
                }

                target.replaceChildren(tile.querySelector('template').content.cloneNode(true));
                titleId && target.querySelector('h2')?.setAttribute('id', titleId);
            },

            quickView(tile) {
                this.quick = tile;
                this.$dispatch('modal-show', { name: quickViewName });
            },

            card() {
                return host.querySelector('[data-story-card]');
            },

            schedule(open, delay, tile) {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => {
                    tile && (this.active = tile);
                    this.open = open;
                }, delay);
            },

            dismiss() {
                clearTimeout(this.timer);
                this.open = false;
            },

            // A pointer over the card keeps it open.
            hold() {
                clearTimeout(this.timer);
            },

            enter(event, tile) {
                event.pointerType !== 'touch' && this.schedule(true, 300, tile);
            },

            leave(event) {
                event.pointerType !== 'touch' && this.schedule(false, 150);
            },

            // Focus coming back from the card (Escape, Shift+Tab) is not an arrival, so it must not reopen what it just closed.
            focus(event, tile) {
                const from = event.relatedTarget;

                // Focus the page moved here itself (after "Not interested") is not the reader arriving, so it leaves the card closed.
                if (event.target.hasAttribute('data-focus-quiet')) {
                    event.target.removeAttribute('data-focus-quiet');

                    return;
                }

                if (! event.target.matches(':focus-visible') || tile.contains(from) || (tile === this.active && this.card().contains(from))) {
                    return;
                }

                this.schedule(true, 300, tile);
            },

            // Tab from an open tile's link goes into its card, where the tile's neighbour would be if the card sat beside it.
            enterCard(event, tile) {
                if (! this.open || tile !== this.active || event.shiftKey || event.target !== this.link(tile)) {
                    return;
                }

                const first = this.card().querySelector('a[href], button');

                first && (event.preventDefault(), first.focus());
            },

            // Tab from the card's last control carries on to whatever follows the tile. The plugin's own version of this trips over the
            // controls it still lists while they are hidden (a tile's touch-only button), so it is pre-empted here; Shift+Tab is left to it.
            leaveCard(event) {
                const controls = this.card().querySelectorAll('a[href], button');

                if (event.shiftKey || event.target !== controls[controls.length - 1]) {
                    return;
                }

                const from = this.link(this.active);
                const next = [...document.querySelectorAll('a[href], button, input, select, textarea, summary, [tabindex]')].find((el) => (
                    el.tabIndex >= 0 && ! el.disabled && ! this.card().contains(el) && el.checkVisibility()
                        && from.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING
                ));

                if (next) {
                    event.preventDefault();
                    event.stopPropagation();
                    next.focus();
                }
            },

            // Focus moving between the open tile and its card, or a pointer still over either (a click on the card's text moves focus to the page), keeps it open.
            blur(event) {
                const next = event.relatedTarget;
                const card = this.card();

                if (next && (card.contains(next) || this.active?.contains(next))) {
                    return;
                }

                this.active?.matches(':hover') || card.matches(':hover') || this.schedule(false, 0);
            },
        };
    });

    // The tiles of Home that come from the recommender (<x-cover-tile recommendation dismissible>), around the page's shelves.
    // It reports back in the background, with no parameter in any link (Phase 4 R11): a click as a beacon, the tiles that came into view
    // (half of the tile, once each per page view) in one batched request. "Not interested" is a one-item menu next to the tile on a wide
    // screen and a bottom sheet on a phone or touch screen; confirming takes the tile out of the row (instantly under reduced motion),
    // moves focus to the next tile and offers Undo in a `role="status"` toast for `undoSeconds`, paused while it is hovered or has focus. Undo is
    // also on the Z key (or Ctrl/Cmd+Z) while the toast offers it, the toast says so, and closing it with focus inside it hands focus to the page.
    // The removal is client-side, from what the page already holds: the server forgets the cached shelves, and the next load rebuilds them.
    Alpine.data('recommendationTiles', ({ token, clickUrl, impressionsUrl, dismissUrl, undoUrl, undoSeconds = 10, messages }) => {
        const url = (template, id) => template.replace('__id__', id);
        const compact = () => window.matchMedia('(max-width: 639px), (pointer: coarse)').matches;
        const post = (target, fields) => {
            const body = new FormData();

            body.append('_token', token);
            Object.entries(fields).forEach(([name, value]) => [].concat(value).forEach((item) => body.append(Array.isArray(value) ? `${name}[]` : name, item)));

            navigator.sendBeacon?.(target, body) || fetch(target, { method: 'POST', body, keepalive: true, credentials: 'same-origin' }).catch(() => {});
        };
        const call = (method, target) => fetch(target, { method, headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, credentials: 'same-origin', keepalive: true });

        let trigger = null;
        // The dismissal whose toast is showing (set when its toast shows, not when the tile starts to leave), and the element focus went to after it.
        let removed = null;
        let afterFocus = null;
        let lastEnter = { tile: null, at: 0 };
        let announceTimer = 0;
        let observer = null;
        let flushTimer = 0;
        let toastTimer = 0;
        let toastLeft = 0;
        let toastSince = 0;
        const seen = new Set();
        const queue = new Set();

        const flush = () => {
            clearTimeout(flushTimer);
            const ids = [...queue];

            queue.clear();

            for (let i = 0; i < ids.length; i += 100) {
                post(impressionsUrl, { ids: ids.slice(i, i + 100) });
            }
        };
        const leave = () => document.visibilityState === 'hidden' && flush();

        return {
            menuId: null,
            sheetTitle: '',
            message: '',
            announced: '',
            canUndo: false,
            popup: 'menu',

            init() {
                // The overflow button opens a menu beside the tile on a wide screen and a dialog (the sheet) on a phone or touch screen.
                const query = window.matchMedia('(max-width: 639px), (pointer: coarse)');
                const sync = () => { this.popup = query.matches ? 'dialog' : 'menu'; };

                sync();
                query.addEventListener?.('change', sync);

                if (! ('IntersectionObserver' in window)) {
                    return;
                }

                observer = new IntersectionObserver((entries) => {
                    entries.filter((entry) => entry.isIntersecting).forEach((entry) => {
                        const id = Number(entry.target.dataset.recommendation);

                        observer.unobserve(entry.target);
                        seen.has(id) || (seen.add(id), queue.add(id));
                    });

                    queue.size && (clearTimeout(flushTimer), flushTimer = setTimeout(flush, 1000));
                }, { threshold: 0.5 });

                this.$root.querySelectorAll('a[data-recommendation]').forEach((tile) => observer.observe(tile));
                document.addEventListener('visibilitychange', leave);
                window.addEventListener('pagehide', flush);
            },

            destroy() {
                flush();
                observer?.disconnect();
                clearTimeout(toastTimer);
                clearTimeout(announceTimer);
                document.removeEventListener('visibilitychange', leave);
                window.removeEventListener('pagehide', flush);
            },

            // Opening a recommended tile: the link stays a plain link, and the page tells the server in the background. Enter is handled on its own:
            // Livewire's link handler cancels it, so no click event follows (and one that does, right after, is not counted twice). A tile that is
            // being dismissed is not opened.
            track(event) {
                const tile = event.target.closest?.('a[data-recommendation]');

                if (! tile || tile.closest('li')?.hasAttribute('data-dismissing')) {
                    return;
                }

                if (event.type === 'keydown') {
                    if (event.key !== 'Enter' || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.repeat) {
                        return;
                    }

                    lastEnter = { tile, at: Date.now() };
                } else if (event.type === 'click' && lastEnter.tile === tile && Date.now() - lastEnter.at < 1000) {
                    return;
                } else if (event.type !== 'click' && event.button !== 1) {
                    return;
                }

                post(clickUrl, { recommendation_id: tile.dataset.recommendation });
            },

            openMenu(button) {
                const id = Number(button.dataset.tileMenu);

                trigger = button;
                this.sheetTitle = button.dataset.title;

                if (compact()) {
                    this.menuId = null;
                    this.$refs.sheet.showModal();

                    return;
                }

                this.menuId = this.menuId === id ? null : id;
                // The menu is shown here, ahead of x-show's own (asynchronous) update, so focus lands in the same task as the key press.
                if (this.menuId !== null) {
                    const menu = button.nextElementSibling;

                    menu.style.removeProperty('display');
                    const item = menu.querySelector('[role=menuitem]');

                    item.focus();
                    // A pointer click can lose that focus to the click's own default handling; once the update has settled, make sure it is there.
                    this.$nextTick(() => this.menuId !== null && document.activeElement !== item && item.focus());
                }
            },

            closeMenu(refocus = false) {
                this.menuId = null;
                refocus && trigger?.focus();
            },

            // A press outside the open menu closes it, leaving focus where the press put it.
            outside(event) {
                this.menuId !== null && ! event.target.closest('[role=menu], [data-tile-menu]') && this.closeMenu();
            },

            closeSheet() {
                this.$refs.sheet.open && this.$refs.sheet.close();
            },

            async notInterested() {
                const li = trigger?.closest('li');

                if (! li || li.hasAttribute('data-dismissing')) {
                    return;
                }

                const id = Number(trigger.dataset.tileMenu);
                const neighbour = li.nextElementSibling ?? li.previousElementSibling;

                this.menuId = null;
                this.closeSheet();
                li.setAttribute('data-dismissing', '');

                // Focus goes to the neighbouring tile first, so it never falls back to the page while the tile is leaving.
                const next = neighbour?.querySelector('a[data-recommendation], a[href]') ?? document.getElementById('main');

                next.setAttribute('data-focus-quiet', '');
                next.focus();
                next.removeAttribute('data-focus-quiet');
                afterFocus = next;

                // Everything of this dismissal is kept in `entry`, so a second one made while this tile is still leaving cannot mix them up.
                const entry = { id, li, parent: li.parentElement, before: li.nextElementSibling, restored: false, sent: null };
                const collapsing = this.collapse(li);

                entry.sent = collapsing.then(() => {
                    entry.parent.querySelector('li') || entry.parent.closest('section')?.setAttribute('hidden', '');

                    return call('POST', url(dismissUrl, id)).then((response) => {
                        if (! response.ok) {
                            throw new Error('dismiss');
                        }

                        return true;
                    }).catch(() => {
                        this.restore(entry, false);
                        this.say(messages.failed, false);

                        return false;
                    });
                });

                await collapsing;

                if (! entry.restored) {
                    removed = entry;
                    this.say(messages.removed + (this.popup === 'menu' && messages.undoHint ? ` ${messages.undoHint}` : ''), true);
                }
            },

            // The tile closes up and the rest slide left; nothing moves under reduced motion.
            async collapse(li) {
                if (prefersReducedMotion()) {
                    li.remove();

                    return;
                }

                const width = li.getBoundingClientRect().width;

                li.style.overflow = 'hidden';
                await animate(li, { width: [`${width}px`, '0px'], marginRight: ['0px', '-1rem'], opacity: [1, 0] }, { duration: MOTION.base / 1000 }).finished.catch(() => {});
                li.remove();
            },

            // Takes back the dismissal the toast is about, once the server has it (the DELETE must not overtake the POST).
            async undo() {
                const entry = removed;

                if (! entry) {
                    return;
                }

                removed = null;
                this.hideToast();

                if (await entry.sent) {
                    this.restore(entry, true);
                    call('DELETE', url(undoUrl, entry.id)).catch(() => {});
                }
            },

            // Z or Ctrl/Cmd+Z, only while the toast offers Undo and never while typing or with a dialog open.
            undoKey(event) {
                const typing = event.target.closest?.('input, textarea, select, [contenteditable=""], [contenteditable="true"]');

                if (event.key.toLowerCase() !== 'z' || event.shiftKey || event.altKey || event.repeat || (event.ctrlKey && event.metaKey)
                    || typing || ! this.canUndo || ! removed || this.$refs.sheet.open) {
                    return;
                }

                event.preventDefault();
                this.undo();
            },

            // The tile goes back where it was and takes focus.
            restore(entry, focus) {
                if (entry.restored) {
                    return;
                }

                const { li, parent, before } = entry;

                entry.restored = true;
                removed === entry && (removed = null);
                li.removeAttribute('style');
                li.removeAttribute('data-dismissing');
                parent.closest('section')?.removeAttribute('hidden');
                parent.insertBefore(li, before?.parentElement === parent ? before : null);
                focus && li.querySelector('a[href]')?.focus();
            },

            // A message that is the same as the one showing is cleared and set again, so that a screen reader says it again. A toast under the
            // pointer or holding focus keeps waiting: a new message gives it a full time, but it does not start running until it is let go.
            say(message, canUndo) {
                const again = this.message === message;

                clearTimeout(toastTimer);
                clearTimeout(announceTimer);
                toastTimer = 0;
                this.message = message;
                this.canUndo = canUndo;
                toastLeft = undoSeconds * 1000;

                if (again) {
                    this.announced = '';
                    announceTimer = setTimeout(() => { this.announced = message; }, 60);
                } else {
                    this.announced = message;
                }

                this.$nextTick(() => {
                    const card = this.$refs.toastCard;

                    this.message === message && ! (card?.matches(':hover') || card?.contains(document.activeElement)) && this.startToast(toastLeft);
                });
            },

            startToast(left) {
                clearTimeout(toastTimer);
                toastLeft = left;
                toastSince = Date.now();
                toastTimer = setTimeout(() => this.hideToast(), left);
            },

            pauseToast() {
                if (this.message && toastTimer) {
                    clearTimeout(toastTimer);
                    toastTimer = 0;
                    toastLeft = Math.max(1000, toastLeft - (Date.now() - toastSince));
                }
            },

            resumeToast() {
                this.message && ! toastTimer && this.startToast(toastLeft);
            },

            // Closing the toast while focus is inside it would drop focus to the page: it goes to the tile that took it after the dismissal.
            hideToast() {
                clearTimeout(toastTimer);
                clearTimeout(announceTimer);
                toastTimer = 0;

                if (this.$refs.toast?.contains(document.activeElement)) {
                    (afterFocus?.isConnected && afterFocus.getClientRects().length ? afterFocus : document.getElementById('main'))?.focus();
                }

                this.message = '';
                this.announced = '';
                this.canUndo = false;
            },
        };
    });

    // <x-range-filter>: a two-handle nouislider. `values` is the model ([from, to], written when a drag or key press ends);
    // `display` follows the handles while they move. The slider is built in a fresh element every time, so a page restored
    // from Livewire's navigate cache (which keeps the old slider's DOM) never ends up with two, and destroyed on teardown.
    Alpine.data('rangeFilter', ({ min, max, step, names }) => ({
        values: [min, max],
        display: [min, max],
        slider: null,

        init() {
            const target = document.createElement('div');

            this.$refs.mount.replaceChildren(target);
            this.slider = noUiSlider.create(target, {
                start: this.bounded(this.values),
                connect: true,
                step,
                range: { min, max },
                behaviour: 'tap-drag',
                animate: ! prefersReducedMotion(),
                format: { to: (value) => Math.round(value), from: Number },
            });

            this.slider.on('update', (values) => {
                this.display = values.map(Number);
                this.describe(target);
            });
            this.slider.on('change', (values) => (this.values = values.map(Number)));
            this.$watch('values', (values) => this.slider?.set(this.bounded(values)));
        },

        // The model can arrive as anything (null before the server sets it, strings from a query string).
        bounded(values) {
            const [from, to] = (Array.isArray(values) && values.length === 2 ? values : [min, max]).map(Number);
            const clamp = (value) => Math.min(Math.max(Number.isNaN(value) ? min : value, min), max);

            return [clamp(from), clamp(to)];
        },

        // nouislider 15 gives the handle role="slider" but puts the value attributes on a child; move them to the handle (APG multi-thumb slider).
        describe(target) {
            const [from, to] = this.display;

            target.querySelectorAll('.noUi-handle').forEach((handle, index) => {
                ['aria-valuemin', 'aria-valuemax', 'aria-valuenow', 'aria-valuetext'].forEach((name) => handle.firstElementChild?.removeAttribute(name));

                handle.setAttribute('aria-label', names[index]);
                handle.setAttribute('aria-valuemin', index === 0 ? min : from);
                handle.setAttribute('aria-valuemax', index === 0 ? to : max);
                handle.setAttribute('aria-valuenow', index === 0 ? from : to);
            });
        },

        destroy() {
            this.slider?.destroy();
            this.$refs.mount?.replaceChildren();
        },
    }));

    // <x-combobox>: the filter and labels around @alpinejs/ui's combobox. Matching ignores case and accents.
    Alpine.data('comboboxField', (options, { multiple, freeform }) => ({
        options,
        query: '',

        // The ui plugin puts aria-multiselectable on the input, where a combobox may not carry it; the listbox has it (in the markup).
        // It also sets aria-controls once, after boot, from a binding that never re-runs, and a Livewire morph of the parent strips it
        // (the server markup has no such attribute) while putting aria-multiselectable back. So both are fixed after boot and after every morph of the input.
        init() {
            const fix = () => {
                const input = this.$refs.__input;

                multiple && input?.removeAttribute('aria-multiselectable');
                this.$refs.__options?.id && input?.setAttribute('aria-controls', this.$refs.__options.id);
            };

            this.$nextTick(fix);
            this.unhook = window.Livewire?.hook('morph.updated', ({ el }) => el === this.$refs.__input && this.$nextTick(fix));
        },

        destroy() {
            this.unhook?.();
        },

        get filtered() {
            const query = fold(this.query);

            return query ? this.options.filter((option) => fold(option.label).includes(query)) : this.options;
        },

        // The plugin empties the field for a moment when it opens the list and says so with a change event, so freeform filters by the model, not the field.
        filter(input) {
            this.query = freeform ? (this.$data.__value ?? '') : input.value;
        },

        labelFor(value) {
            return this.options.find((option) => option.value === value)?.label ?? value ?? '';
        },

        // Multiple mode: a chip's remove button. The list-selection state is the model itself, so editing it is enough.
        remove(value) {
            const picked = this.$combobox.value;

            picked.splice(picked.indexOf(value), 1);
            this.$refs.__input.focus();
        },

        // Freeform: the text typed is the model (a suggestion only fills the field). ponytail: writes the ui plugin's __value, pinned at 3.17.5.
        // The plugin's own input handler fires `change` before this runs, so `filter` has read the model one keystroke late: filter again now.
        typed(input) {
            if (freeform) {
                this.$data.__value = input.value;
                this.query = input.value;
            }
        },

        // Freeform: the plugin activates the first suggestion on its own. The first ArrowDown confirms it rather than skipping to the second...
        beforeArrow(event) {
            const context = this.$data.__context;

            if (freeform && this.$data.__isOpen && context.hasActive() && ! context.wasActivatedByKeyPress()) {
                event.preventDefault();
                event.stopImmediatePropagation();
                context.activateKey(context.getActiveItem().key, true);
            }
        },

        // ...and Enter must not take an activated suggestion that no arrow key chose, it keeps the typed text.
        beforePick(event) {
            if (freeform && ! this.$data.__context.wasActivatedByKeyPress()) {
                event.stopImmediatePropagation();
                this.$data.__close();
            }
        },

        // Multiple mode keeps the popup open after a pick, so empty the field for the next search.
        afterPick(input) {
            multiple && this.$nextTick(() => {
                input.value = '';
                this.query = '';
            });
        },
    }));

    // <x-rating-input>: a score from 1 to 10 on five stars with halves, a radio group after the APG pattern (arrows move focus and select,
    // wrapping; Home and End jump to the ends; only the checked radio, or the first while none is, is in the tab order). Delete or Backspace
    // clears it. `value` is the model (null or a whole score); `preview` is the score under the pointer. A choice is announced to the parent with
    // a bubbling `rated` event ({ value }): at once for a click, the clear button or Space, and after `settle` ms of quiet (or on leaving the
    // group) for arrow keys, so walking along the stars asks the server for one save, not ten. Nothing here animates, so reduced motion needs no case.
    Alpine.data('ratingInput', ({ hint, rated, cleared, settle = 700 }) => ({
        value: null,
        preview: null,
        announcement: '',
        timer: null,

        // The model can arrive as anything (null before the server sets it, a string, a score out of range): only a whole score of 1 to 10 counts.
        get score() {
            const number = Number(this.value);

            return this.value !== null && Number.isInteger(number) && number >= 1 && number <= 10 ? number : null;
        },

        get caption() {
            const shown = this.preview ?? this.score;

            return shown === null ? hint : `${shown}/10`;
        },

        isChecked(score) {
            return this.score === score;
        },

        // Roving tabindex: the checked radio, or the first one while nothing is checked.
        tabindex(score) {
            return (this.score ?? 1) === score ? 0 : -1;
        },

        // How much of a star is filled, in percent: each star holds two scores.
        fill(star) {
            return Math.max(0, Math.min(2, (this.preview ?? this.score ?? 0) - (star - 1) * 2)) * 50;
        },

        hover(event, score) {
            event.pointerType === 'mouse' && (this.preview = score);
        },

        choose(score) {
            this.value = score;
            this.preview = null;
            this.commit();
        },

        clear() {
            this.value = null;
            this.preview = null;
            this.commit();
        },

        commit() {
            clearTimeout(this.timer);
            this.timer = null;
            this.announcement = this.score === null ? cleared : rated.replace(':score', this.score);
            this.$dispatch('rated', { value: this.score });
        },

        keydown(event) {
            const radios = Array.from(this.$root.querySelectorAll('[role=radio]'));
            const at = radios.indexOf(event.target);

            if (at === -1 || event.altKey || event.ctrlKey || event.metaKey) {
                return;
            }

            let target;

            switch (event.key) {
                case 'ArrowRight':
                case 'ArrowDown':
                    target = at + 1;
                    break;
                case 'ArrowLeft':
                case 'ArrowUp':
                    target = at - 1;
                    break;
                case 'Home':
                    target = 0;
                    break;
                case 'End':
                    target = radios.length - 1;
                    break;
                case 'Delete':
                case 'Backspace':
                    event.preventDefault();
                    this.clear();

                    return;
                default:
                    return;
            }

            event.preventDefault();
            const next = radios[(target + radios.length) % radios.length];

            next.focus();
            this.value = Number(next.dataset.value);
            this.preview = null;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.commit(), settle);
        },

        // Focus left the group with a keyboard choice still waiting: send it now.
        leave(event) {
            this.timer !== null && ! this.$root.contains(event.relatedTarget) && this.commit();
        },

        destroy() {
            clearTimeout(this.timer);
        },
    }));
}

const fold = (text) => String(text).normalize('NFD').replace(/\p{Mn}/gu, '').toLowerCase();
