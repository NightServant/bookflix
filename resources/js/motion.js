import { animate, inView, stagger } from 'motion';

/**
 * Motion constants. Keep in sync with the CSS tokens `--motion-fast`, `--motion-base`
 * and `--motion-slow` in resources/css/app.css (milliseconds there, spring is JS-only).
 */
export const MOTION = {
    fast: 150,
    base: 250,
    slow: 400,
    spring: { stiffness: 300, damping: 30 },
};

// Read at call time so a changed OS/browser setting is honoured without a reload.
const prefersReducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Motion leaves its final keyframe as inline style; drop it so CSS (e.g. hover transforms) owns the element again.
const release = (targets) => () => targets.forEach((target) => {
    target.style.removeProperty('opacity');
    target.style.removeProperty('transform');
});

/**
 * x-motion-reveal: children (or the element itself when it has none) fade in and rise 8px
 * once scrolled into view, staggered by `data-stagger` ms (default 30).
 */
function reveal(el, _, { cleanup }) {
    if (prefersReducedMotion()) {
        return;
    }

    const targets = el.children.length ? [...el.children] : [el];
    const delay = Number(el.dataset.stagger ?? 30) / 1000;

    targets.forEach((target) => {
        target.style.opacity = '0';
        target.style.transform = 'translateY(8px)';
    });

    const stopObserving = inView(el, () => {
        animate(targets, { opacity: 1, y: 0 }, { duration: MOTION.base / 1000, ease: 'easeOut', delay: stagger(delay) })
            .then(release(targets));
    }, { amount: 0.1 });

    cleanup(() => {
        stopObserving();
        release(targets)();
    });
}

/**
 * x-motion-expand: spring scale + fade for previews. Plays on mount, or each time the optional
 * expression turns truthy (e.g. `x-motion-expand="open"` next to `x-show="open"`).
 * Height is not animated (only transform and opacity are).
 */
function expand(el, { expression }, { effect, evaluateLater, cleanup }) {
    if (prefersReducedMotion()) {
        return;
    }

    let controls;
    const play = () => {
        el.style.transformOrigin = 'top center';
        controls?.stop();
        controls = animate(el, { opacity: [0, 1], scale: [0.96, 1] }, { type: 'spring', ...MOTION.spring });
        controls.then(release([el]));
    };

    if (expression) {
        const isOpen = evaluateLater(expression);

        effect(() => isOpen((open) => open && play()));
    } else {
        play();
    }

    cleanup(() => controls?.stop());
}

/**
 * x-motion-crossfade: fades the element in on mount (so a `wire:key` change on a hero remounts and
 * crossfades the new content in) and replays whenever the optional expression changes value.
 */
function crossfade(el, { expression }, { effect, evaluateLater, cleanup }) {
    if (prefersReducedMotion()) {
        return;
    }

    let controls;
    const play = () => {
        controls?.stop();
        controls = animate(el, { opacity: [0, 1] }, { duration: MOTION.slow / 1000, ease: 'easeInOut' });
        controls.then(release([el]));
    };

    if (expression) {
        const read = evaluateLater(expression);

        effect(() => read(play));
    } else {
        play();
    }

    cleanup(() => controls?.stop());
}

export default function motionDirectives(Alpine) {
    Alpine.directive('motion-reveal', reveal);
    Alpine.directive('motion-expand', expand);
    Alpine.directive('motion-crossfade', crossfade);
}
