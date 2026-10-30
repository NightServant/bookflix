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

        return {
            open: false,
            active: null,
            quick: null,
            timer: null,

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
                return this.$root.querySelector('[data-story-card]');
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
