import { prefersReducedMotion } from './motion';

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

    // <x-hero>: one slide at a time with prev/next, a counter and an optional rotation, following the APG carousel pattern.
    Alpine.data('hero', (count, autoplay, interval) => ({
        current: 0,
        playing: false,
        hold: false,
        timer: null,

        init() {
            // Reduced motion never starts rotating on its own; the reader can still press Play.
            this.playing = autoplay && !prefersReducedMotion();
            this.$watch('playing', () => this.schedule());
            this.schedule();
        },

        schedule() {
            clearInterval(this.timer);

            if (this.playing) {
                // Hovering or focusing the hero holds the rotation so nobody loses a slide they are reading.
                this.timer = setInterval(() => !this.hold && !document.hidden && this.next(), interval);
            }
        },

        next() {
            this.current = (this.current + 1) % count;
            this.schedule();
        },

        prev() {
            this.current = (this.current - 1 + count) % count;
            this.schedule();
        },

        toggle() {
            this.playing = !this.playing;
        },

        destroy() {
            clearInterval(this.timer);
        },
    }));
}
