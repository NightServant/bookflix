/**
 * The comic engines (one file, two modes). Contract: resources/js/reader/chrome.js.
 *
 * `pages`: a comic one page at a time (or two side by side on a wide screen, never splitting a page that is wider than tall), fitted to the height
 * or the width of the window, right to left when the reader says so (the chrome swaps the arrows; here only the order of a spread turns round).
 * Only the pages next to the one on screen have an `<img>` (two before, three after): they are what "preload" means, and the others are dropped.
 * `webtoon`: one centered strip of every page, each an `<img loading="lazy">` in a box of the size of the first page until it has loaded; the
 * browser fetches an image when it is near the screen, and an IntersectionObserver asks the page's Livewire component for signed addresses in
 * batches of 50 as the reader nears pages that have none.
 *
 * Page addresses are signed and short-lived (`reader.url_ttl_minutes`): they are asked for in batches (`pageUrls(from, count)`), kept with the
 * `expires` they carry, and asked for again when an image that has not loaded yet holds one that is about to run out. A 403 or 404 from the component
 * (the access gate says no) or from a page is the file withdrawn: `reader:error` says `unavailable` and the place on screen is kept.
 */
const BATCH = 50;
const REFRESH_MARGIN = 90;
const BEHIND = 2;
const AHEAD = 3;
const WIDE = 1024;
const STRIP_WIDTH = 720;
const BAR = 64;
const FOOT = 88;

const emit = (name, detail) => window.dispatchEvent(new CustomEvent(name, { detail }));
const fill = (text, values) => Object.entries(values).reduce((out, [key, value]) => out.replace(`:${key}`, value), text ?? '');
const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

class Unavailable extends Error {}

// The address's own expiry (Laravel signs `expires`, a unix time), or null when it has none.
const expiryOf = (url) => {
    const expires = Number(new URL(url, window.location.href).searchParams.get('expires'));

    return Number.isFinite(expires) && expires > 0 ? expires : null;
};

const isLoaded = (img) => Boolean(img?.getAttribute('src')) && img.complete && img.naturalWidth > 0;

// Resolves true when the image has loaded, false when it failed (or has no address).
const loaded = (img) =>
    new Promise((resolve) => {
        if (!img.getAttribute('src')) {
            return resolve(false);
        }

        if (img.complete) {
            return resolve(img.naturalWidth > 0);
        }

        img.addEventListener('load', () => resolve(true), { once: true });
        img.addEventListener('error', () => resolve(false), { once: true });
    });

export default function startPages(container, { settings, mode }) {
    const webtoon = mode === 'webtoon';
    const strings = JSON.parse(container.dataset.strings ?? '{}');
    const resume = JSON.parse(container.dataset.resumeLocator ?? 'null');
    const resumePercent = Number(container.dataset.resumePercent) || 0;
    const count = Number(container.dataset.pageCount) || 0;
    const wireId = container.dataset.wireId;

    let alive = true;
    let current = { fit: 'height', spread: 'one', direction: 'ltr', ...settings };
    let page = 0;
    let initial = true;
    let failed = false;
    let wired = false;
    const urls = new Map();
    const inflight = new Map();
    const imgs = new Map();
    const retried = new Set();
    const cleanups = [];

    const start = Number.isInteger(resume?.page) ? resume.page : Math.round((resumePercent / 100) * count);
    const first = Math.min(count, Math.max(1, start || 1));

    const label = (n) => fill(strings.pageLabel ?? 'Page :n', { n });
    const on = (target, name, handler, options) => {
        target.addEventListener(name, handler, options);
        cleanups.push(() => target.removeEventListener(name, handler, options));
    };

    // ---- the signed addresses ----

    // Livewire's own error dialog would cover the page when the component answers 403: this call handles that answer itself.
    async function component() {
        for (let tries = 0; tries < 100; tries++) {
            const wire = window.Livewire?.find(wireId);

            if (wire) {
                if (!wired) {
                    wired = true;
                    wire.$intercept?.('pageUrls', ({ onError }) => onError(({ preventDefault }) => preventDefault()));
                }

                return wire;
            }

            await new Promise((resolve) => setTimeout(resolve, 50));
        }

        throw new Error('no component');
    }

    function fetchUrls(from, n) {
        const key = `${from}:${n}`;

        if (!inflight.has(key)) {
            inflight.set(
                key,
                (async () => {
                    const wire = await component();
                    let result;

                    try {
                        result = await wire.pageUrls(from, n);
                    } catch (error) {
                        throw error?.status === 403 || error?.status === 404 ? new Unavailable() : new Error('network');
                    }

                    for (const [key, url] of Object.entries(result ?? {})) {
                        if (urls.has(Number(key))) {
                            container.dataset.urlRefreshes = String(Number(container.dataset.urlRefreshes ?? 0) + 1);
                        }

                        urls.set(Number(key), { url, expires: expiryOf(url) });
                    }
                })().finally(() => inflight.delete(key)),
            );
        }

        return inflight.get(key);
    }

    // Does the address held for a page have to be asked for again: none, or one about to run out for an image that has not loaded yet.
    const stale = (n) => {
        const held = urls.get(n);

        return !held || (held.expires !== null && held.expires - Date.now() / 1000 < REFRESH_MARGIN && !isLoaded(imgs.get(n)));
    };

    // Asks for the addresses `pages` need (runs of up to 50 at a time).
    async function ensure(pages) {
        const wanted = pages.filter((n) => n >= 1 && n <= count && stale(n)).sort((a, b) => a - b);

        for (let i = 0; i < wanted.length; ) {
            const from = wanted[i];
            const run = wanted.filter((n) => n >= from && n < from + BATCH);

            await fetchUrls(from, run[run.length - 1] - from + 1);
            i += run.length;
        }
    }

    function address(n) {
        const img = imgs.get(n);
        const held = urls.get(n);

        if (img && held && img.getAttribute('src') !== held.url && !isLoaded(img)) {
            img.src = held.url;
        }
    }

    // An image that failed: ask for a fresh address once (the old one may have run out; the component says 403 when the file was withdrawn), then
    // ask the page itself whether it is gone.
    async function recover(n, img) {
        if (!retried.has(n)) {
            retried.add(n);
            urls.delete(n);
            await fetchUrls(n, 1);
            img.src = urls.get(n)?.url ?? '';

            return loaded(img);
        }

        try {
            const response = await fetch(img.getAttribute('src'), { method: 'HEAD', credentials: 'same-origin' });

            if (response.status === 403 || response.status === 404) {
                throw new Unavailable();
            }
        } catch (error) {
            if (error instanceof Unavailable) {
                throw error;
            }
        }

        return false;
    }

    async function ready(n) {
        const img = imgs.get(n);

        if (!img) {
            return null;
        }

        if (await loaded(img)) {
            return img;
        }

        // A fresh address first, then (if that fails too) the page itself says whether it is gone.
        if (img.isConnected && (await recover(n, img))) {
            return img;
        }

        return img.isConnected && (await recover(n, img)) ? img : null;
    }

    const wide = (img) => img !== null && img.naturalWidth > img.naturalHeight;

    function fail(error) {
        if (!alive || failed) {
            return;
        }

        failed = true;
        emit('reader:error', { kind: error instanceof Unavailable ? 'unavailable' : 'network' });
    }

    function relocate(shown, extra = {}) {
        const last = shown[shown.length - 1];
        const changed = shown[0] !== page;
        page = shown[0];

        if (!changed && !initial) {
            return;
        }

        emit('reader:relocate', {
            percent: Math.round((last / count) * 10000) / 100,
            locator: { page: shown[0] },
            chapter: null,
            label: fill(strings.pageOfFirst, { n: shown[0], total: count }),
            atEnd: last === count || Boolean(extra.atEnd),
            initial,
        });
    }

    // =========================================================== paged

    const stage = document.createElement('div');
    const row = document.createElement('div');
    let shown = [];
    let showToken = 0;

    const spreadOn = () => current.spread === 'two' && window.innerWidth >= WIDE;

    function layoutPaged() {
        const phone = window.innerWidth < 768;
        const availW = stage.clientWidth;
        const availH = Math.max(1, stage.clientHeight - (phone ? 32 : 0));
        const items = shown.map((n) => imgs.get(n)).filter(Boolean);
        const ratios = items.map((img) => (isLoaded(img) ? img.naturalWidth / img.naturalHeight : 2 / 3));
        const sum = ratios.reduce((total, ratio) => total + ratio, 0) || 1;
        const height = current.fit === 'width' ? availW / sum : Math.min(availH, availW / sum);

        row.style.flexDirection = current.direction === 'rtl' ? 'row-reverse' : 'row';
        row.style.paddingBottom = phone ? '32px' : '0';
        row.style.minHeight = '100%';
        items.forEach((img, i) => {
            img.style.height = `${Math.floor(height)}px`;
            img.style.width = `${Math.floor(height * ratios[i])}px`;
        });
    }

    function drop(n) {
        const img = imgs.get(n);

        img?.remove();
        imgs.delete(n);
        retried.delete(n);
    }

    function mount(n) {
        if (imgs.has(n) || n < 1 || n > count) {
            return;
        }

        const img = document.createElement('img');

        img.alt = label(n);
        img.decoding = 'async';
        img.draggable = false;
        img.dataset.page = String(n);
        img.hidden = true;
        img.style.cssText = 'display:block;flex:none;user-select:none;';
        imgs.set(n, img);
        row.append(img);
    }

    async function show(target) {
        const token = ++showToken;
        const n = Math.min(count, Math.max(1, Math.round(target)));
        const window_ = [];

        for (let p = n - BEHIND; p <= n + AHEAD + 1; p++) {
            p >= 1 && p <= count && window_.push(p);
        }

        try {
            for (const p of [...imgs.keys()]) {
                if (!window_.includes(p)) {
                    drop(p);
                }
            }

            window_.forEach(mount);
            await ensure(window_);
            window_.forEach(address);

            const one = await ready(n);
            const two = spreadOn() && n < count ? await ready(n + 1) : null;

            if (token !== showToken || !alive) {
                return;
            }

            const pages = spreadOn() && n < count && one && two && !wide(one) && !wide(two) ? [n, n + 1] : [n];

            shown = pages;

            for (const [p, img] of imgs) {
                img.hidden = !pages.includes(p);
            }

            layoutPaged();
            stage.scrollTop = 0;
            relocate(pages);
        } catch (error) {
            fail(error);
        }
    }

    // The page before: with a spread on, the pair that ends here (or the one page before it when either is wide).
    async function before() {
        const from = shown[0] ?? page;

        if (from <= 1) {
            return null;
        }

        if (spreadOn() && from > 2) {
            const [a, b] = await Promise.all([ready(from - 2), ready(from - 1)]);

            if (a && b && !wide(a) && !wide(b)) {
                return from - 2;
            }
        }

        return from - 1;
    }

    // =========================================================== webtoon

    const strip = document.createElement('div');
    let pinned = null;
    let frame = 0;
    let observer = null;
    let timer = 0;
    let ratio = 1.5;
    let learned = false;

    const wrappers = () => strip.children;

    function layoutStrip() {
        const narrow = window.innerWidth < 768;

        strip.style.maxWidth = narrow || current.fit === 'wide' ? 'none' : `${STRIP_WIDTH}px`;
    }

    function buildStrip() {
        const fragment = document.createDocumentFragment();

        for (let n = 1; n <= count; n++) {
            const box = document.createElement('div');
            const img = document.createElement('img');

            box.dataset.page = String(n);
            box.style.cssText = `background:var(--color-surface-deep);aspect-ratio:1 / ${ratio};`;
            img.alt = label(n);
            img.loading = 'lazy';
            img.decoding = 'async';
            img.draggable = false;
            img.style.cssText = 'display:block;width:100%;height:auto;';
            img.addEventListener('load', () => {
                if (!learned) {
                    // The first page that loads sets the size the boxes of the others hold until theirs have loaded.
                    learned = true;
                    ratio = img.naturalHeight / img.naturalWidth;
                    [...wrappers()].forEach((other) => !other.dataset.sized && (other.style.aspectRatio = `1 / ${ratio}`));
                }

                box.dataset.sized = '1';
                box.style.aspectRatio = 'auto';
                pinned ? realign() : schedule();
            });
            img.addEventListener('error', () => {
                imgs.has(n) && wireError(n, img);
            });
            box.append(img);
            imgs.set(n, img);
            fragment.append(box);
        }

        strip.append(fragment);
    }

    async function wireError(n, img) {
        try {
            await recover(n, img);
        } catch (error) {
            fail(error);
        }
    }

    async function batch(from) {
        try {
            await fetchUrls(from, BATCH);

            for (let n = from; n < from + BATCH && n <= count; n++) {
                address(n);
            }
        } catch (error) {
            error instanceof Unavailable && fail(error);
        }
    }

    // A box came near the screen: if the pages just ahead of it have no address yet, ask for the next 50.
    function near(entries) {
        for (const entry of entries) {
            if (!entry.isIntersecting) {
                continue;
            }

            const n = Number(entry.target.dataset.page);

            for (let p = n; p < Math.min(count + 1, n + 10); p++) {
                if (!urls.has(p)) {
                    batch(p);
                    break;
                }
            }
        }
    }

    async function refreshStale() {
        const old = [...imgs.keys()].filter((n) => urls.has(n) && stale(n));

        old.length && (await batch(old[0]));
    }

    function pageInView() {
        const boxes = wrappers();
        const top = stage.scrollTop + BAR;
        const bottom = stage.scrollTop + stage.clientHeight;
        let low = 0;
        let high = boxes.length - 1;

        // The first box that ends below the top of the screen.
        while (low < high) {
            const mid = (low + high) >> 1;

            boxes[mid].offsetTop + boxes[mid].offsetHeight > top ? (high = mid) : (low = mid + 1);
        }

        let best = low + 1;
        let most = -1;

        for (let i = low; i < boxes.length && boxes[i].offsetTop < bottom; i++) {
            const visible = Math.min(bottom, boxes[i].offsetTop + boxes[i].offsetHeight) - Math.max(top, boxes[i].offsetTop);

            if (visible > most) {
                most = visible;
                best = i + 1;
            }
        }

        return best;
    }

    function update() {
        frame = 0;

        if (!alive) {
            return;
        }

        if (pinned !== null && Math.abs(stage.scrollTop - pinned.top) > 150) {
            pinned = null;
        }

        const atEnd = stage.scrollTop + stage.clientHeight >= stage.scrollHeight - 2 && stage.scrollTop > 0;
        const n = pinned !== null ? pinned.page : atEnd ? count : pageInView();

        relocate([n], { atEnd });
    }

    function schedule() {
        frame ||= requestAnimationFrame(update);
    }

    // While the place the reader opened on is held, the boxes above it settling to the size of their images must not move it.
    function realign() {
        const box = wrappers()[pinned.page - 1];

        if (box) {
            stage.scrollTop = box.offsetTop - BAR;
            pinned.top = stage.scrollTop;
        }
    }

    function scrollToPage(n) {
        const box = wrappers()[Math.min(count, Math.max(1, n)) - 1];

        box && (stage.scrollTop = box.offsetTop - BAR);
        pinned = null;
        schedule();
    }

    // ---- both ----

    function go(target) {
        webtoon ? scrollToPage(target) : show(target);
    }

    const handlers = {
        'reader:next': () => {
            if (webtoon) {
                pinned = null;
                stage.scrollBy({ top: stage.clientHeight * 0.9, behavior: reduced() ? 'auto' : 'smooth' });
            } else if ((shown[shown.length - 1] ?? 0) < count) {
                show(shown[shown.length - 1] + 1);
            }
        },
        'reader:prev': async () => {
            if (webtoon) {
                pinned = null;
                stage.scrollBy({ top: -stage.clientHeight * 0.9, behavior: reduced() ? 'auto' : 'smooth' });
            } else {
                const target = await before();

                target !== null && show(target);
            }
        },
        'reader:goto': ({ detail }) => {
            if (typeof detail?.page === 'number') {
                go(detail.page);
            } else if (typeof detail?.percent === 'number') {
                go(Math.max(1, Math.round((detail.percent / 100) * count)));
            }
        },
        'reader:settings': ({ detail }) => {
            const spread = spreadOn();

            current = { ...current, ...detail };

            if (webtoon) {
                layoutStrip();
            } else if (spread !== spreadOn()) {
                show(shown[0] ?? first);
            } else if (shown.length) {
                layoutPaged();
            }
        },
        'reader:retry': () => {
            reset();
            open();
        },
    };

    for (const [name, handler] of Object.entries(handlers)) {
        on(window, name, handler);
    }

    function reset() {
        showToken++;
        failed = false;
        initial = true;
        learned = false;
        page = 0;
        shown = [];
        pinned = null;
        observer?.disconnect();
        clearInterval(timer);
        urls.clear();
        imgs.clear();
        retried.clear();
        row.replaceChildren();
        strip.replaceChildren();
    }

    async function open() {
        try {
            if (webtoon) {
                layoutStrip();
                buildStrip();
                await fetchUrls(Math.max(1, first - 5), BATCH);

                if (!alive) {
                    return;
                }

                for (let n = 1; n <= count; n++) {
                    address(n);
                }

                observer = new IntersectionObserver(near, { root: stage, rootMargin: '100% 0px' });
                [...wrappers()].forEach((box) => observer.observe(box));
                timer = setInterval(refreshStale, 20000);

                if (first > 1) {
                    scrollToPage(first);
                    pinned = { page: first, top: stage.scrollTop };
                }

                emit('reader:ready', { format: 'pages', toc: [], total: count });
                relocate([first]);
                initial = false;
            } else {
                await show(first);

                if (!alive || failed) {
                    return;
                }

                emit('reader:ready', { format: 'pages', toc: [], total: count });
                initial = false;
            }
        } catch (error) {
            fail(error);
        }
    }

    // ---- the page ----

    stage.dataset.reader = webtoon ? 'webtoon-scroller' : 'pages-stage';
    stage.tabIndex = 0;
    stage.setAttribute('role', 'region');
    stage.setAttribute('aria-label', document.querySelector('[data-reader=toolbar] h1')?.textContent?.trim() || 'Comic');
    stage.className = 'size-full overflow-auto outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ink';

    if (webtoon) {
        stage.style.paddingTop = `${BAR}px`;
        stage.style.position = 'relative';
        strip.dataset.reader = 'webtoon-strip';
        strip.style.cssText = 'margin:0 auto;width:100%;';
        strip.style.paddingBottom = `${FOOT}px`;
        stage.append(strip);
        on(stage, 'scroll', schedule, { passive: true });
        on(stage, 'wheel', () => (pinned = null), { passive: true });
        on(stage, 'touchstart', () => (pinned = null), { passive: true });
        // Nothing in the strip has focus, so the arrow keys scroll it from here.
        on(window, 'keydown', (event) => {
            const down = event.key === 'ArrowDown';

            if ((down || event.key === 'ArrowUp') && !event.defaultPrevented && !event.target?.closest?.('input, textarea, select, [role=dialog]')) {
                event.preventDefault();
                pinned = null;
                stage.scrollBy({ top: down ? 80 : -80 });
            }
        });
    } else {
        row.style.cssText = 'display:flex;align-items:center;justify-content:center;';
        row.dataset.reader = 'pages-row';
        stage.append(row);
    }

    container.append(stage);

    const resizer = new ResizeObserver(() => (webtoon ? (layoutStrip(), schedule()) : shown.length && layoutPaged()));

    resizer.observe(stage);

    const query = window.matchMedia(`(min-width: ${WIDE}px)`);

    on(query, 'change', () => !webtoon && shown.length && show(shown[0]));
    open();

    return () => {
        alive = false;
        cancelAnimationFrame(frame);
        clearInterval(timer);
        observer?.disconnect();
        resizer.disconnect();
        cleanups.forEach((cleanup) => cleanup());
        stage.remove();
    };
}
