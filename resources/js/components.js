import { animate, stagger } from 'motion';
import noUiSlider from 'nouislider';
import { MOTION, prefersReducedMotion, release } from './motion';

/**
 * Alpine data for the component kit (resources/views/components). Each name matches `x-data="<name>"` in its Blade view.
 * Registered as a plugin so it lands before Livewire.start() boots Alpine.
 */
export default function components(Alpine) {
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
                const source = root.querySelector(`[data-card="${this.cards[index].id}"] img`);

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

    // <x-story-preview>: `open` is the model of the ui popover inside. It opens 300ms after a mouse rests on the trigger or the
    // keyboard reaches it, and closes a moment after both leave. Touch never opens it, and neither does a click's focus (that is
    // not :focus-visible); the popover plugin itself handles Escape, Tab out and focus returning to the trigger.
    Alpine.data('storyPreview', () => ({
        open: false,
        timer: null,

        // The trigger's own link (or button); the popover plugin focuses it again when the preview closes.
        link() {
            return this.$refs.trigger.querySelector('a[href], button') ?? this.$refs.trigger;
        },

        schedule(open, delay) {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => (this.open = open), delay);
        },

        dismiss() {
            clearTimeout(this.timer);
            this.open = false;
        },

        enter(event) {
            event.pointerType !== 'touch' && this.schedule(true, 300);
        },

        leave(event) {
            event.pointerType !== 'touch' && this.schedule(false, 150);
        },

        // Focus coming back from the panel (Escape, Shift+Tab) is not an arrival, so it must not reopen what it just closed.
        focus(event) {
            event.target.matches(':focus-visible') && ! event.currentTarget.contains(event.relatedTarget) && this.schedule(true, 300);
        },

        // A pointer still over the preview keeps it open (a click on its text moves focus to the page).
        blur(event) {
            const root = event.currentTarget;

            root.contains(event.relatedTarget) || root.matches(':hover') || this.schedule(false, 0);
        },
    }));

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
        init() {
            multiple && this.$nextTick(() => this.$refs.__input?.removeAttribute('aria-multiselectable'));
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
        typed(input) {
            freeform && (this.$data.__value = input.value);
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
}

const fold = (text) => String(text).normalize('NFD').replace(/\p{Mn}/gu, '').toLowerCase();
