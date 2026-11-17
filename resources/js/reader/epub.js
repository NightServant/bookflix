import 'foliate-js/view.js';
import * as CFI from 'foliate-js/epubcfi.js';

/**
 * The EPUB engine (foliate-js 1.0.1). `<foliate-view>` renders the book in sandboxed iframes (the build strips `allow-scripts`, see vite.config.js),
 * one section at a time, paginated in columns or scrolled. This file only translates: the signed file is fetched and opened as a Blob, the place the
 * reader left is resumed (CFI, then fraction, then percent), foliate's `relocate` becomes `reader:relocate`, and the page's commands and
 * settings (`reader:next`, `reader:prev`, `reader:goto`, `reader:settings`) become calls on the view. Errors become `reader:error`: a file that
 * can't be opened is `corrupt`, a fetch answered 403 or 404 is `unavailable`. Contract: resources/js/reader/chrome.js.
 */
const COLORS = {
    dark: { background: ['--color-reader-dark', '#0a0a0a'], ink: ['--color-reader-dark-ink', '#ebebeb'], link: '#9ec5fe' },
    sepia: { background: ['--color-reader-sepia', '#211c14'], ink: ['--color-reader-sepia-ink', '#e8dcc6'], link: '#e0b96f' },
};

// 100% in the settings is 18px of body text, a comfortable size for a page of two columns.
const BASE_SIZE = 1.125;

const FAMILIES = { serif: 'ui-serif, Georgia, serif', sans: 'ui-sans-serif, system-ui, sans-serif' };

const emit = (name, detail) => window.dispatchEvent(new CustomEvent(name, { detail }));

const cssColor = ([name, fallback]) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;

class Unavailable extends Error {}

// What goes inside every section of the book: the reader's theme, size, line spacing and typeface over the book's own styles.
function bookStyles(settings) {
    const colors = COLORS[settings.theme] ?? COLORS.dark;
    const background = cssColor(colors.background);
    const ink = cssColor(colors.ink);
    const family = FAMILIES[settings.typeface];

    return `
        html { color-scheme: dark; font-size: ${Math.round((Number(settings.fontSize) || 100) * BASE_SIZE)}% !important; background: ${background} !important; }
        body { background: ${background} !important; color: ${ink} !important; font-size: 1rem; }
        body *:not(a):not(a *) { color: inherit !important; }
        a:link, a:visited { color: ${colors.link} !important; }
        p, li, blockquote, dd, td, div { line-height: ${settings.lineHeight} !important; }
        ${family ? `body, body *:not(code):not(pre):not(kbd):not(samp) { font-family: ${family} !important; }` : ''}
        pre { white-space: pre-wrap !important; }
        img, svg, video { max-width: 100%; }
    `;
}

const flatten = (items, level = 0) => (items ?? []).flatMap((item) => [{ label: String(item.label ?? '').trim(), href: item.href, level }, ...flatten(item.subitems, level + 1)]);

export default function startEpub(container, { settings }) {
    const strings = JSON.parse(container.dataset.strings ?? '{}');
    const wide = window.matchMedia('(min-width: 1024px)');
    const resume = JSON.parse(container.dataset.resumeLocator ?? 'null');
    const resumePercent = Number(container.dataset.resumePercent) || 0;

    let current = { ...settings };
    let view = null;
    let flat = [];
    let initial = true;
    let opened = 0;

    const fill = (text, values) => Object.entries(values).reduce((out, [key, value]) => out.replace(`:${key}`, value), text ?? '');

    const layout = () => {
        const renderer = view?.renderer;

        if (!renderer) {
            return;
        }

        const scrolled = current.layout === 'scrolled';

        renderer.setAttribute('flow', scrolled ? 'scrolled' : 'paginated');
        // Two columns of about 552px on a wide screen (a spread of 1104px), one column on a phone.
        renderer.setAttribute('max-column-count', wide.matches ? '2' : '1');
        renderer.setAttribute('max-inline-size', scrolled ? '720px' : '552px');
        renderer.setAttribute('gap', wide.matches ? '8%' : '6%');
        renderer.setStyles?.(bookStyles(current));
    };

    // Where the visible page starts in the whole book, 0 to 1 (foliate's own fraction is where it ends).
    const startFraction = (detail) => {
        const renderer = view.renderer;
        const fractions = view.getSectionFractions();
        const index = detail.section?.current ?? 0;
        const inSection = current.layout === 'scrolled' ? renderer.start / renderer.viewSize : (renderer.page - 1) / (renderer.pages - 2);
        const from = fractions[index] ?? 0;
        const to = fractions[index + 1] ?? 1;
        const value = from + (Number.isFinite(inSection) ? Math.min(1, Math.max(0, inSection)) : 0) * (to - from);

        return Number.isFinite(value) ? Math.min(1, Math.max(0, value)) : Math.min(1, Math.max(0, detail.fraction ?? 0));
    };

    const relocated = ({ detail }) => {
        const renderer = view.renderer;
        const atEnd = Boolean(renderer.atEnd) || (current.layout === 'scrolled' && renderer.viewSize - renderer.end <= 2);
        const fraction = startFraction(detail);
        const percent = atEnd ? 100 : Math.min(99.99, Math.round(fraction * 10000) / 100);
        const item = detail.tocItem;
        const location = detail.location;
        const parts = [];

        if (item && Number.isInteger(item.id) && flat.length > 0) {
            parts.push(fill(strings.chapterOf, { n: item.id + 1, total: flat.length }));
        }

        if (location?.total > 0) {
            const text = parts.length > 0 ? strings.pageOf : strings.pageOfFirst;

            parts.push(fill(text, { n: atEnd ? location.total : Math.min(location.total, location.current + 1), total: location.total }));
        }

        emit('reader:relocate', {
            percent,
            locator: { cfi: detail.cfi, fraction: Math.round(fraction * 100000) / 100000 },
            chapter: item?.label ? String(item.label).trim() : null,
            chapterHref: item?.href ?? null,
            label: parts.join(' · '),
            atEnd,
            initial,
        });
    };

    // Input inside the book's iframe never reaches the page: forward it, so the keys, the bars and the touch taps work the same everywhere.
    const forward = ({ detail: { doc } }) => {
        const frame = doc.defaultView?.frameElement;
        let down = null;

        doc.addEventListener('keydown', (event) => {
            const copy = new KeyboardEvent('keydown', { key: event.key, code: event.code, shiftKey: event.shiftKey, ctrlKey: event.ctrlKey, altKey: event.altKey, metaKey: event.metaKey, bubbles: true, cancelable: true });

            document.body.dispatchEvent(copy) || event.preventDefault();
        });

        doc.addEventListener('pointermove', (event) => emit('reader:activity', { pointerType: event.pointerType }));

        doc.addEventListener('pointerdown', (event) => {
            down = { x: event.clientX, y: event.clientY, type: event.pointerType };
            emit('reader:activity', { pointerType: event.pointerType });
        });

        doc.addEventListener('pointerup', (event) => {
            const moved = down === null || Math.hypot(event.clientX - down.x, event.clientY - down.y) > 10;

            if (event.pointerType === 'touch' && !moved && !event.target.closest?.('a[href]') && frame) {
                emit('reader:tap', { x: (event.clientX + frame.getBoundingClientRect().left) / window.innerWidth });
            }

            down = null;
        });
    };

    const freshUrl = async () => {
        try {
            const url = await window.Livewire?.find(container.dataset.wireId)?.streamUrl();

            return typeof url === 'string' ? url : container.dataset.streamUrl;
        } catch {
            return container.dataset.streamUrl;
        }
    };

    async function place() {
        if (resume?.cfi && CFI.isCFI.test(resume.cfi)) {
            let usable = false;

            try {
                const target = view.resolveCFI(resume.cfi);

                usable = Number.isInteger(target?.index) && target.index >= 0 && target.index < view.book.sections.length;
            } catch {
                usable = false;
            }

            if (usable) {
                return view.init({ lastLocation: resume.cfi });
            }
        }

        // The saved fraction is where a page starts: a hair after it, so rounding never opens the page before.
        const fraction = typeof resume?.fraction === 'number' ? resume.fraction : resumePercent / 100;

        if (fraction > 0) {
            return view.goToFraction(Math.min(1, Math.max(0, fraction + 0.0002)));
        }

        return view.init({});
    }

    async function open(fresh = false) {
        const token = ++opened;

        close();
        initial = true;

        try {
            const response = await fetch(fresh ? await freshUrl() : container.dataset.streamUrl, { credentials: 'same-origin' });

            if (response.status === 403 || response.status === 404) {
                throw new Unavailable();
            }

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const file = new File([await response.blob()], 'book.epub', { type: 'application/epub+zip' });

            if (token !== opened) {
                return;
            }

            view = document.createElement('foliate-view');
            view.style.cssText = 'display: block; width: 100%; height: 100%;';
            container.append(view);

            await view.open(file);

            flat = flatten(view.book.toc);
            view.addEventListener('relocate', relocated);
            view.addEventListener('load', forward);
            layout();

            // Ready first: the chapters and the keys work from the moment the first page is up. `initial` marks the moves made by opening at the saved place.
            emit('reader:ready', { format: 'epub', toc: flat, total: flat.length, direction: view.book.dir === 'rtl' ? 'rtl' : 'ltr' });

            await place();

            initial = false;
        } catch (error) {
            if (token !== opened) {
                return;
            }

            close();
            emit('reader:error', { kind: error instanceof Unavailable ? 'unavailable' : error instanceof TypeError ? 'network' : 'corrupt' });
        }
    }

    function close() {
        try {
            view?.close();
        } catch {
            // Already closed.
        }

        view?.remove();
        view = null;
    }

    const handlers = {
        'reader:next': () => view?.next(),
        'reader:prev': () => view?.prev(),
        'reader:goto': ({ detail }) => {
            if (detail?.href) {
                view?.goTo(detail.href);
            } else if (typeof detail?.percent === 'number') {
                view?.goToFraction(Math.min(1, Math.max(0, detail.percent / 100)));
            }
        },
        'reader:settings': ({ detail }) => {
            current = { ...current, ...detail };
            layout();
        },
        'reader:retry': () => open(true),
    };

    for (const [name, handler] of Object.entries(handlers)) {
        window.addEventListener(name, handler);
    }

    wide.addEventListener('change', layout);

    open();

    return () => {
        opened++;
        close();
        wide.removeEventListener('change', layout);

        for (const [name, handler] of Object.entries(handlers)) {
            window.removeEventListener(name, handler);
        }
    };
}
