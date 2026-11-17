import * as pdfjs from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

/**
 * The PDF engine (pdf.js, same-origin worker). Contract: resources/js/reader/chrome.js.
 *
 * The file is read with HTTP range requests through a `PDFDataRangeTransport` of our own instead of pdf.js's built-in URL loader. P9-R14: the
 * signed address lasts `reader.url_ttl_minutes`, a tab can stay open longer, and pdf.js has no way to swap the URL of a loading task. Every range
 * request here goes through `currentUrl()`, which asks the page's Livewire component for a fresh `streamUrl()` when the held one is about to run out
 * (the `expires` it carries), so the transport simply keeps using the newest address. A 403 or 404 on a range means the file was withdrawn.
 *
 * Pages are one scroll column. The scroll height is computed (every page is laid out at the size of page 1), and only the pages in or next to the
 * viewport have a canvas: the others are dropped as the reader moves, so 1,000 pages cost a handful of canvases. Text selection is not offered (no
 * text layer): each canvas is an image labelled "Page n".
 */
const GAP = 24;
const PAD = 24;
const STEPS = [50, 75, 100, 125, 150, 200, 250, 300];
const MIN_ZOOM = 0.5;
const MAX_ZOOM = 3;
// At 100% the page is as wide as the column, up to this many CSS pixels (a comfortable reading size; "Fit width" goes beyond it on a wide screen).
const BASE_WIDTH = 720;
const CHUNK = 65536;
// Ask for a new address when the one held has less than this long to live.
const REFRESH_MARGIN = 90;
const MAX_CANVAS_PIXELS = 16_000_000;
// From this window width the file opens at fit page.
const WIDE = 1024;
// The most outline entries the Contents list holds.
const MAX_OUTLINE = 500;

const emit = (name, detail) => window.dispatchEvent(new CustomEvent(name, { detail }));

class Unavailable extends Error {}

// The address's own expiry (Laravel signs `expires`, a unix time), or null when it has none.
const expiryOf = (url) => {
    const expires = Number(new URL(url, window.location.href).searchParams.get('expires'));

    return Number.isFinite(expires) && expires > 0 ? expires : null;
};

const fill = (text, values) => Object.entries(values).reduce((out, [key, value]) => out.replace(`:${key}`, value), text ?? '');

// Where pdf.js finds its optional data: the build copies them next to the worker (vite.config.js); the dev server serves the package itself.
const dataBase = () => (import.meta.env.DEV ? new URL('/node_modules/pdfjs-dist/', window.location.href) : new URL('../pdfjs/', new URL(workerUrl, window.location.href))).href;

export default function startPdf(container) {
    const strings = JSON.parse(container.dataset.strings ?? '{}');
    const resume = JSON.parse(container.dataset.resumeLocator ?? 'null');
    const resumePercent = Number(container.dataset.resumePercent) || 0;
    const wireId = container.dataset.wireId;

    pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

    let alive = true;
    let doc = null;
    let task = null;
    let transport = null;
    let url = container.dataset.streamUrl;
    let refreshing = null;
    let ratio = 1.294; // height / width of page 1, until it is known
    let zoom = 1;
    let count = 0;
    let current = 0;
    let pinned = null;
    let typing = false;
    let frame = 0;
    let layoutKey = '';
    let initial = true;
    let outline = [];
    const live = new Map();

    const scroller = document.createElement('div');
    const sheet = document.createElement('div');

    scroller.dataset.reader = 'pdf-scroller';
    scroller.tabIndex = 0;
    scroller.setAttribute('role', 'region');
    scroller.setAttribute('aria-label', document.querySelector('[data-reader=toolbar] h1')?.textContent?.trim() || 'PDF');
    scroller.className = 'size-full overflow-auto bg-surface-deep outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ink';
    sheet.className = 'relative';
    scroller.append(sheet);
    container.append(scroller);

    const ui = (name) => [...document.querySelectorAll(`[data-reader="${name}"]`)];
    const pageInput = () => document.querySelector('[data-reader=page-input]');

    // ---- the signed address ----

    async function refresh() {
        try {
            const fresh = await window.Livewire?.find(wireId)?.streamUrl();

            if (typeof fresh === 'string' && fresh !== '') {
                url = fresh;
                container.dataset.streamUrl = fresh;
                container.dataset.urlRefreshes = String(Number(container.dataset.urlRefreshes ?? 0) + 1);
            }
        } catch {
            // Keep the one held: if the file was withdrawn, the next range says so with its own 403.
        } finally {
            refreshing = null;
        }
    }

    async function currentUrl() {
        const expires = expiryOf(url);

        if (expires !== null && expires - Date.now() / 1000 < REFRESH_MARGIN) {
            await (refreshing ??= refresh());
        }

        return url;
    }

    async function fetchRange(begin, end) {
        const response = await fetch(await currentUrl(), { credentials: 'same-origin', headers: { Range: `bytes=${begin}-${end - 1}` } });

        if (response.status === 403 || response.status === 404) {
            throw new Unavailable();
        }

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        return response;
    }

    class SignedRanges extends pdfjs.PDFDataRangeTransport {
        requestDataRange(begin, end) {
            fetchRange(begin, end)
                .then(async (response) => {
                    const bytes = new Uint8Array(await response.arrayBuffer());

                    // A server that ignored the Range header sent everything: cut out what was asked for.
                    this.onDataRange(begin, response.status === 206 ? bytes : bytes.subarray(begin, end));
                })
                .catch((error) => alive && fail(error));
        }
    }

    // ---- opening ----

    function fail(error) {
        if (!alive) {
            return;
        }

        const kind = error instanceof Unavailable ? 'unavailable' : error instanceof pdfjs.PasswordException ? 'password' : error instanceof TypeError ? 'network' : 'corrupt';

        close();
        emit('reader:error', { kind });
    }

    async function open() {
        const first = await fetchRange(0, CHUNK);
        const bytes = new Uint8Array(await first.arrayBuffer());
        const total = Number(/\/(\d+)\s*$/.exec(first.headers.get('Content-Range') ?? '')?.[1]);
        const base = dataBase();
        const options = {
            rangeChunkSize: CHUNK,
            disableAutoFetch: true,
            isEvalSupported: false,
            withCredentials: true,
            verbosity: pdfjs.VerbosityLevel.ERRORS,
            cMapUrl: `${base}cmaps/`,
            standardFontDataUrl: `${base}standard_fonts/`,
            wasmUrl: `${base}wasm/`,
        };

        if (first.status === 206 && total > bytes.length) {
            transport = new SignedRanges(total, bytes);
            task = pdfjs.getDocument({ ...options, range: transport });
        } else {
            // The whole file came back (it is small, or ranges are not supported): nothing more to ask for.
            task = pdfjs.getDocument({ ...options, data: bytes });
        }

        doc = await task.promise;

        if (!alive) {
            return;
        }

        count = doc.numPages;
        const first1 = await doc.getPage(1);
        const view = first1.getViewport({ scale: 1 });
        ratio = view.height / view.width;

        ui('page-total').forEach((element) => (element.textContent = String(count)));

        // A phone opens at fit width (the 100% of the column); a wide screen at fit page: the whole first page between the bars.
        zoom = window.innerWidth >= WIDE ? Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, fitPageZoom())) : 1;
        measure();
        outline = await readOutline();

        if (!alive) {
            return;
        }

        emit('reader:ready', { format: 'pdf', toc: outline.map(({ label, href, level }) => ({ label, href, level })), total: count, subtitle: fill(strings.pdfSubtitle, { n: count }) });
        updateZoomUi();

        const wanted = Number.isInteger(resume?.page) ? resume.page : Math.round((resumePercent / 100) * count);
        const start = Math.min(count, Math.max(1, wanted || 1));

        goTo(start);
        initial = false;
    }

    // ---- the file's outline (Contents) ----

    // The page a destination of the outline points at (1-based), or null when it can't be told.
    async function pageOf(dest) {
        try {
            const target = typeof dest === 'string' ? await doc.getDestination(dest) : dest;
            const ref = Array.isArray(target) ? target[0] : null;

            if (Number.isInteger(ref)) {
                return ref + 1;
            }

            return ref ? (await doc.getPageIndex(ref)) + 1 : null;
        } catch {
            return null;
        }
    }

    // The outline flattened in reading order (label, href `outline-n`, level, page); an entry that points nowhere is left out.
    async function readOutline() {
        try {
            const tree = (await doc.getOutline()) ?? [];
            const flat = [];
            const walk = async (items, level) => {
                for (const item of items) {
                    if (flat.length >= MAX_OUTLINE) {
                        return;
                    }

                    const label = String(item.title ?? '').trim();
                    const page = label && item.dest ? await pageOf(item.dest) : null;

                    if (page !== null && page >= 1 && page <= count) {
                        flat.push({ label, level: Math.min(level, 3), page, href: `outline-${flat.length}` });
                    }

                    await walk(item.items ?? [], level + 1);
                }
            };

            await walk(tree, 0);

            return flat;
        } catch {
            return [];
        }
    }

    // The entry of the outline the reader is in: the last one that starts on this page or before it.
    const sectionAt = (page) => outline.reduce((found, entry) => (entry.page <= page ? entry : found), null);

    // ---- layout ----

    // The zoom at which one whole page fits between the bars, with the column's padding above and below it.
    function fitPageZoom() {
        const g = geometry();
        const height = Math.max(1, scroller.clientHeight - PAD * 2);

        return Math.min(g.avail, height / ratio) / g.base;
    }

    function geometry() {
        const gutter = scroller.clientWidth < 640 ? 16 : 24;
        const avail = Math.max(1, scroller.clientWidth - gutter * 2);
        const base = Math.min(avail, BASE_WIDTH);
        const width = base * zoom;
        const height = width * ratio;

        return { gutter, avail, base, width, height, pitch: height + GAP, inner: Math.max(scroller.clientWidth, width + gutter * 2) };
    }

    // Sizes the column; the pages already drawn are redrawn when their width changed.
    function measure() {
        const g = geometry();
        const key = `${Math.round(g.width)}:${Math.round(g.height)}:${g.inner}`;

        sheet.style.width = `${g.inner}px`;
        sheet.style.height = `${PAD * 2 - GAP + count * g.pitch}px`;

        if (key !== layoutKey) {
            layoutKey = key;

            for (const entry of live.values()) {
                place(entry, g);
            }
        }

        return g;
    }

    const topOf = (n, g) => PAD + (n - 1) * g.pitch;

    // A page that is not the size of page 1 is drawn to fit inside its slot, centered.
    function place(entry, g = geometry()) {
        const box = entry.size ?? { w: g.width, h: g.height };
        const scale = Math.min(g.width / box.w, g.height / box.h);
        const w = box.w * scale;
        const h = box.h * scale;

        entry.css = { w, h };
        entry.left = (g.inner - w) / 2;
        entry.top = topOf(entry.n, g) + (g.height - h) / 2;
        entry.canvas && position(entry.canvas, entry);

        if (entry.drawn !== Math.round(w)) {
            render(entry);
        }
    }

    function position(canvas, entry) {
        canvas.style.cssText = `position:absolute;left:${entry.left}px;top:${entry.top}px;width:${entry.css.w}px;height:${entry.css.h}px;`;
    }

    async function render(entry) {
        entry.task?.cancel();
        const css = entry.css;
        const token = (entry.token = (entry.token ?? 0) + 1);

        try {
            const page = await doc.getPage(entry.n);

            if (!alive || token !== entry.token || !live.has(entry.n)) {
                return;
            }

            const natural = page.getViewport({ scale: 1 });

            if (!entry.size) {
                entry.size = { w: natural.width, h: natural.height };
                place(entry);

                return;
            }

            let output = Math.min(window.devicePixelRatio || 1, 2);
            const base = css.w / natural.width;

            output = Math.min(output, Math.sqrt(MAX_CANVAS_PIXELS / (css.w * css.h)));

            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.floor(css.w * output));
            canvas.height = Math.max(1, Math.floor(css.h * output));
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', fill(strings.pageLabel ?? 'Page :n', { n: entry.n }));
            canvas.dataset.page = String(entry.n);
            canvas.className = 'bg-white shadow-lg';
            position(canvas, entry);

            entry.task = page.render({ canvas, viewport: page.getViewport({ scale: base * output }), background: '#ffffff' });
            await entry.task.promise;

            if (!alive || token !== entry.token || !live.has(entry.n)) {
                return;
            }

            entry.canvas?.remove();
            entry.canvas = canvas;
            entry.drawn = Math.round(css.w);
            sheet.append(canvas);
        } catch (error) {
            if (error?.name !== 'RenderingCancelledException' && alive && !(error instanceof pdfjs.PasswordException) && error?.name !== 'AbortException') {
                // One page that won't draw leaves its slot empty; the rest keep working.
            }
        }
    }

    function drop(n) {
        const entry = live.get(n);

        entry.token = (entry.token ?? 0) + 1;
        entry.task?.cancel();
        entry.canvas?.remove();
        live.delete(n);
        doc?.getPage(n).then((page) => page.cleanup()).catch(() => {});
    }

    // Draws the pages in or next to the viewport and drops the rest; then works out which page the reader is on.
    function update() {
        frame = 0;

        if (!alive || !doc) {
            return;
        }

        const g = measure();
        const top = scroller.scrollTop;
        const bottom = top + scroller.clientHeight;
        const first = Math.max(1, Math.floor((top - PAD) / g.pitch) + 1);
        const last = Math.min(count, Math.floor((bottom - PAD) / g.pitch) + 1);
        const from = Math.max(1, first - 1);
        const to = Math.min(count, last + 1);

        for (const n of [...live.keys()]) {
            if (n < from || n > to) {
                drop(n);
            }
        }

        for (let n = from; n <= to; n++) {
            if (!live.has(n)) {
                const entry = { n, canvas: null, task: null, size: null, drawn: 0 };
                live.set(n, entry);
                place(entry, g);
            }
        }

        if (pinned === null) {
            const atBottom = top + scroller.clientHeight >= scroller.scrollHeight - 2 && top > 0;
            const probe = top + Math.min(scroller.clientHeight * 0.3, g.height * 0.5);

            relocate(atBottom ? count : Math.min(count, Math.max(1, Math.floor((probe - PAD + GAP) / g.pitch) + 1)));
        }
    }

    const schedule = () => {
        if (!frame) {
            frame = requestAnimationFrame(update);
        }
    };

    function relocate(page) {
        const changed = page !== current;
        current = page;

        if (!typing && pageInput()) {
            pageInput().value = String(page);
        }

        if (!changed && !initial) {
            return;
        }

        emit('reader:relocate', {
            percent: Math.round((page / count) * 10000) / 100,
            locator: { page },
            chapter: null,
            chapterHref: sectionAt(page)?.href,
            label: fill(strings.pageOfFirst, { n: page, total: count }),
            atEnd: page === count,
            initial,
        });
    }

    // Holds the page number where it was put until the reader scrolls on their own (a short column can't scroll to every page).
    function goTo(page) {
        const n = Math.min(count, Math.max(1, Math.round(page)));
        const g = geometry();

        pinned = n;
        scroller.scrollTop = topOf(n, g) - PAD;
        relocate(n);
        update();
        pinned = n;
    }

    // ---- zoom ----

    function setZoom(value) {
        const next = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, value));

        if (!doc || next === zoom) {
            return;
        }

        // Keep the same place on the page: the same fraction of the column.
        const fraction = scroller.scrollTop / Math.max(1, scroller.scrollHeight);
        zoom = next;
        measure();
        scroller.scrollTop = fraction * scroller.scrollHeight;
        updateZoomUi();
        update();
    }

    function stepZoom(direction) {
        const percent = Math.round(zoom * 100);
        const target = direction > 0 ? STEPS.find((step) => step > percent) : [...STEPS].reverse().find((step) => step < percent);

        target && setZoom(target / 100);
    }

    function fitWidth() {
        const g = geometry();

        setZoom(g.avail / g.base);
    }

    function updateZoomUi() {
        const percent = Math.round(zoom * 100);

        ui('zoom-value').forEach((element) => (element.textContent = `${percent}%`));
        ui('zoom-out').forEach((button) => button.setAttribute('aria-disabled', String(percent <= MIN_ZOOM * 100)));
        ui('zoom-in').forEach((button) => button.setAttribute('aria-disabled', String(percent >= MAX_ZOOM * 100)));
    }

    // ---- the page's own controls ----

    function jump() {
        const input = pageInput();
        const text = input.value.trim();
        const page = Number(text);

        typing = false;

        if (/^\d+$/.test(text) && page >= 1 && page <= count) {
            goTo(page);
        } else {
            input.value = String(current);
        }
    }

    const onInputKey = (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            jump();
        } else if (event.key === 'Escape') {
            typing = false;
            event.target.value = String(current);
        }
    };

    const onClick = (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-reader]') : null;
        const name = button?.dataset.reader;

        if (name === 'zoom-in' || name === 'zoom-out') {
            event.preventDefault();
            stepZoom(name === 'zoom-in' ? 1 : -1);
        } else if (name === 'fit-width') {
            event.preventDefault();
            fitWidth();
        }
    };

    // A reader's own scroll ends a jump's hold on the page number.
    const release = () => (pinned = null);

    const onKey = (event) => {
        if (event.ctrlKey || event.metaKey || event.altKey || event.target?.closest?.('input, textarea')) {
            return;
        }

        if (event.key === '+' || event.key === '=') {
            stepZoom(1);
        } else if (event.key === '-') {
            stepZoom(-1);
        }
    };

    const handlers = {
        'reader:next': () => doc && goTo(current + 1),
        'reader:prev': () => doc && goTo(current - 1),
        'reader:goto': ({ detail }) => {
            if (!doc) {
                return;
            }

            if (typeof detail?.href === 'string') {
                const entry = outline.find((item) => item.href === detail.href);

                entry && goTo(entry.page);
            } else if (typeof detail?.page === 'number') {
                goTo(detail.page);
            } else if (typeof detail?.percent === 'number') {
                goTo(Math.max(1, Math.round((detail.percent / 100) * count)));
            }
        },
        // The address held may have run out while the card was up: ask for a new one first.
        'reader:retry': () => (refreshing ??= refresh()).then(start),
    };

    for (const [name, handler] of Object.entries(handlers)) {
        window.addEventListener(name, handler);
    }

    const input = pageInput();
    const resizer = new ResizeObserver(schedule);

    input?.addEventListener('keydown', onInputKey);
    input?.addEventListener('input', () => (typing = true));
    input?.addEventListener('blur', () => {
        typing = false;
        input.value = String(current || input.value);
    });
    document.addEventListener('click', onClick);
    window.addEventListener('keydown', onKey);
    scroller.addEventListener('scroll', schedule, { passive: true });
    scroller.addEventListener('wheel', release, { passive: true });
    scroller.addEventListener('touchstart', release, { passive: true });
    scroller.addEventListener('pointerdown', release);
    scroller.addEventListener('keydown', release);
    resizer.observe(scroller);

    function close() {
        for (const n of [...live.keys()]) {
            drop(n);
        }

        task?.destroy().catch(() => {});
        task = null;
        doc = null;
        transport = null;
    }

    function start() {
        close();
        current = 0;
        initial = true;
        pinned = null;
        url = container.dataset.streamUrl;
        open().catch(fail);
    }

    start();

    return () => {
        alive = false;
        cancelAnimationFrame(frame);
        close();
        resizer.disconnect();
        document.removeEventListener('click', onClick);
        window.removeEventListener('keydown', onKey);
        input?.removeEventListener('keydown', onInputKey);

        for (const [name, handler] of Object.entries(handlers)) {
            window.removeEventListener(name, handler);
        }

        scroller.remove();
    };
}
