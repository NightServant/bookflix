/**
 * Alpine data for the public landing page (resources/views/components/landing). Registered as a plugin so it lands before
 * Livewire.start() boots Alpine. Each piece is progressive: without it the page is complete and readable, just not animated.
 */
const reducedMotion = '(prefers-reduced-motion: reduce)';

export default function landing(Alpine) {
    // The Top 10 marquee: a pause button (WCAG 2.2.2) and a flag for reduced motion, where the list scrolls by hand and so must take focus.
    Alpine.data('landingMarquee', () => ({
        paused: false,
        reduced: window.matchMedia(reducedMotion).matches,

        init() {
            const query = window.matchMedia(reducedMotion);
            const change = (event) => (this.reduced = event.matches);

            query.addEventListener('change', change);
            this.off = () => query.removeEventListener('change', change);
        },

        destroy() {
            this.off?.();
        },
    }));

    /*
     * The walkthrough: on a wide screen with motion allowed, the product preview pins beside a column of statements and swaps as each
     * statement passes a line a third of the way down the viewport (`data-live` turns the pinned layout on in CSS, and `data-current` marks the step
     * and the preview in view). On a phone, with reduced motion or without this script, every statement sits above its own preview and
     * nothing is hidden or driven by scrolling.
     */
    Alpine.data('landingWalk', () => ({
        init() {
            const root = this.$root;
            const query = window.matchMedia(`(min-width: 64rem) and (not ${reducedMotion})`);
            const steps = [...root.querySelectorAll('[data-step]')];
            const previews = [...root.querySelectorAll('[data-preview]')];
            let observer = null;

            const show = (index) => {
                steps.forEach((step, at) => step.toggleAttribute('data-current', at === index));
                previews.forEach((preview, at) => preview.toggleAttribute('data-current', at === index));
            };

            const sync = () => {
                observer?.disconnect();
                observer = null;
                root.toggleAttribute('data-live', query.matches);

                if (!query.matches) {
                    steps.forEach((step) => step.removeAttribute('data-current'));
                    previews.forEach((preview) => preview.removeAttribute('data-current'));

                    return;
                }

                show(0);
                observer = new IntersectionObserver(
                    (entries) => entries.forEach((entry) => entry.isIntersecting && show(steps.indexOf(entry.target))),
                    { rootMargin: '-30% 0px -69% 0px' },
                );
                steps.forEach((step) => observer.observe(step));
            };

            query.addEventListener('change', sync);
            sync();
            this.off = () => {
                query.removeEventListener('change', sync);
                observer?.disconnect();
            };
        },

        destroy() {
            this.off?.();
        },
    }));

    // The FAQ: one answer open at a time, each a button (aria-expanded, aria-controls) over a region. Arrow keys, Home and End move between the questions.
    Alpine.data('landingFaq', () => ({
        open: null,

        toggle(index) {
            this.open = this.open === index ? null : index;
        },

        move(event) {
            const buttons = [...this.$root.querySelectorAll('[data-faq-button]')];
            const at = buttons.indexOf(document.activeElement);
            const next = { ArrowDown: at + 1, ArrowUp: at - 1, Home: 0, End: buttons.length - 1 }[event.key];

            if (at === -1 || next === undefined) return;

            event.preventDefault();
            buttons[(next + buttons.length) % buttons.length].focus();
        },
    }));
}
