import { prefersReducedMotion } from './motion';

const MAX_LEAN = 1; // The lean is -1..1 on each axis; the CSS turns 1 into 10deg.

/**
 * Alpine data for <x-book-3d> (resources/views/components/book-3d.blade.php): the pointer lean and the broken-cover fallback.
 * Registered as a plugin so it lands before Livewire.start() boots Alpine. The book's geometry and lighting are pure CSS.
 */
export default function book3d(Alpine) {
    Alpine.data('book3d', (interactive = false) => ({
        init() {
            // An image that failed before Alpine started has no error event left to deliver.
            const cover = this.$refs.cover;

            if (cover?.complete && cover.naturalWidth === 0) {
                this.broken();
            }

            if (interactive) {
                this.lean();
            }
        },

        broken() {
            this.$root.toggleAttribute('data-broken', true);
        },

        // The book leans towards the pointer while the pointer is over it, and settles back when it leaves.
        // Fine pointers only: a finger has no hover, and a tap must not leave the book tilted. Read at call time so a changed setting is honoured.
        lean() {
            const root = this.$root;
            const fine = window.matchMedia('(hover: hover) and (pointer: fine)');
            let frame = 0;
            let point = null;

            const apply = () => {
                frame = 0;

                if (! point) {
                    root.style.removeProperty('--px');
                    root.style.removeProperty('--py');

                    return;
                }

                const box = root.getBoundingClientRect();
                const clamp = (value) => Math.max(-MAX_LEAN, Math.min(MAX_LEAN, value));

                root.style.setProperty('--px', clamp(((point.x - box.left) / box.width - 0.5) * 2).toFixed(3));
                root.style.setProperty('--py', clamp(((point.y - box.top) / box.height - 0.5) * 2).toFixed(3));
            };
            const queue = () => {
                frame ||= requestAnimationFrame(apply);
            };
            const move = (event) => {
                if (! fine.matches || prefersReducedMotion()) {
                    return;
                }

                point = { x: event.clientX, y: event.clientY };
                queue();
            };
            const leave = () => {
                point = null;
                queue();
            };

            root.addEventListener('pointermove', move, { passive: true });
            root.addEventListener('pointerleave', leave);

            this._unlean = () => {
                cancelAnimationFrame(frame);
                root.removeEventListener('pointermove', move);
                root.removeEventListener('pointerleave', leave);
            };
        },

        destroy() {
            this._unlean?.();
        },
    }));
}
