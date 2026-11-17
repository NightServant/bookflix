import startProgress from './progress';

/**
 * `readerChrome`: the state and behaviour of the reader page's own interface, for every format (resources/views/pages/reader/⚡show.blade.php).
 * The engines (EPUB now; PDF and comics next) talk to it only through events on the window, so it never touches a book:
 *   in:  `reader:ready` { format, toc, total, direction?, subtitle? }, `reader:relocate` { percent, locator, chapter, chapterHref?, label, atEnd },
 *        `reader:error` { kind: 'corrupt' | 'password' | 'unavailable' | 'network' }, `reader:activity` (input inside a book's iframe),
 *        `reader:tap` { x } (a touch tap inside it, as a fraction of the window's width).
 *   out: `reader:next`, `reader:prev`, `reader:goto` { href | percent | page }, `reader:settings` { ...settings }, `reader:retry`.
 * It also owns what the page shows around the book: the panels (settings, contents, with focus trapped by `x-trap`), the auto-hiding bars, the
 * keyboard, full screen, the cards for the opening, couldn't-open and withdrawn states and the end of the book, and the reading settings, which
 * are kept on this device in localStorage (the direction of a comic per story). The progress save is started from here too (./progress.js).
 */
const HIDE_AFTER = 3000;
const SETTINGS_KEY = 'bookflix.reader.settings';
const COMIC_KEY = 'bookflix.reader.comic.';
const FONT_SIZES = [90, 100, 115, 130, 150];

const DEFAULTS = {
    epub: { fontSize: 100, lineHeight: '1.6', typeface: 'book', layout: 'paginated', theme: 'dark' },
    pages: { fit: 'height', spread: 'one', direction: 'ltr' },
    webtoon: { fit: 'standard' },
    pdf: {},
};

// Every read and write of the device's storage may throw (private windows, blocked site data): the reader works without it.
const read = (key) => {
    try {
        return JSON.parse(window.localStorage.getItem(key) ?? 'null');
    } catch {
        return null;
    }
};

const write = (key, value) => {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Not kept; the settings still apply to this page.
    }
};

const percentText = (value) => `${Math.round((Number(value) || 0) * 10) / 10}`;

// The bottom bar shows a whole number; the saved percent keeps its decimals.
const wholeText = (value) => `${Math.round(Number(value) || 0)}`;

export default function readerChrome(Alpine) {
    Alpine.data('readerChrome', (config) => ({
        engine: config.engine,
        panel: null,
        state: config.state,
        finished: false,
        chrome: true,
        toc: [],
        current: null,
        percent: config.percent,
        subtitle: config.subtitle,
        meta: config.meta,
        direction: config.direction,
        settings: {},
        sizes: FONT_SIZES,
        canFullscreen: false,
        fullscreen: false,
        strings: config.strings,
        atEnd: false,
        running: false,
        hideTimer: null,
        cleanups: [],

        init() {
            this.settings = this.loadSettings();
            this.canFullscreen = Boolean(document.fullscreenEnabled);
            this.$watch('settings', () => this.saveSettings());

            const on = (target, name, handler, options) => {
                target.addEventListener(name, handler, options);
                this.cleanups.push(() => target.removeEventListener(name, handler, options));
            };

            on(window, 'reader:ready', (event) => this.onReady(event.detail ?? {}));
            on(window, 'reader:relocate', (event) => this.onRelocate(event.detail ?? {}));
            on(window, 'reader:error', (event) => this.onError(event.detail ?? {}));
            on(window, 'reader:activity', (event) => this.activity(event.detail?.pointerType));
            on(window, 'reader:tap', (event) => this.tap(event.detail?.x));
            on(window, 'keydown', (event) => this.onKey(event));
            on(window, 'pointermove', (event) => event.pointerType === 'mouse' && this.wake());
            on(this.$root, 'focusin', () => this.wake());
            on(this.$root, 'pointerup', (event) => this.onPointerUp(event));
            on(document, 'fullscreenchange', () => (this.fullscreen = Boolean(document.fullscreenElement)));

            this.wake();

            this.cleanups.push(startProgress({ url: this.container.dataset.progressUrl }));

            this.start();
        },

        destroy() {
            clearTimeout(this.hideTimer);
            this.cleanups.forEach((cleanup) => cleanup());
            this.cleanups = [];
        },

        get container() {
            return this.$root.querySelector('[data-reader-engine]');
        },

        // The engine's own code is loaded only on the reader page, and only for the format that is open.
        start() {
            if (this.state === 'corrupt') {
                return;
            }

            const load = this.engine === 'pdf' ? import('./pdf') : this.engine === 'epub' ? import('./epub') : import('./pages');
            const mode = this.engine === 'webtoon' ? 'webtoon' : 'pages';

            load.then((module) => {
                this.running = true;
                this.cleanups.push(module.default(this.container, { settings: { ...this.settings }, mode }));
            }).catch(() => this.onError({ kind: 'corrupt' }));
        },

        // ---- settings, on this device ----

        loadSettings() {
            const defaults = { ...DEFAULTS[this.engine] };
            const comic = this.engine === 'pages' || this.engine === 'webtoon';
            // A comic's choices are kept for the story; a book's for every book.
            const stored = (comic ? read(COMIC_KEY + config.mediaId) : read(SETTINGS_KEY)) ?? {};
            const settings = { ...defaults };

            if (this.engine === 'pages') {
                settings.direction = config.direction;
            }

            for (const key of Object.keys(defaults)) {
                if (stored[key] !== undefined && typeof stored[key] === typeof defaults[key]) {
                    settings[key] = stored[key];
                }
            }

            if (settings.direction !== undefined && settings.direction !== 'rtl' && settings.direction !== 'ltr') {
                settings.direction = config.direction;
            }

            if (this.engine === 'pages') {
                this.direction = settings.direction;
            }

            return settings;
        },

        saveSettings() {
            if (this.engine === 'pages' || this.engine === 'webtoon') {
                write(COMIC_KEY + config.mediaId, this.settings);
            } else {
                write(SETTINGS_KEY, { ...(read(SETTINGS_KEY) ?? {}), ...this.settings });
            }

            if (this.settings.direction !== undefined) {
                this.direction = this.settings.direction;
            }

            window.dispatchEvent(new CustomEvent('reader:settings', { detail: { ...this.settings } }));
        },

        stepSize(by) {
            const index = Math.min(this.sizes.length - 1, Math.max(0, this.sizes.indexOf(this.settings.fontSize) + by));
            this.settings.fontSize = this.sizes[index];
        },

        // ---- engine events ----

        onReady(detail) {
            this.toc = Array.isArray(detail.toc) ? detail.toc : [];

            // An engine may name the book's kind and size ("PDF · 248 pages") where the chapter would be.
            if (typeof detail.subtitle === 'string') {
                this.subtitle = detail.subtitle;
            }

            if (detail.direction === 'rtl' || detail.direction === 'ltr') {
                this.direction = detail.direction;
            }

            this.state = 'ready';
            this.wake();
        },

        onRelocate(detail) {
            this.percent = Number(detail.percent) || 0;
            this.current = detail.chapterHref ?? null;

            if (detail.chapter) {
                this.subtitle = detail.chapter;
            }

            if (detail.label !== undefined) {
                this.meta = detail.label;
            }

            // The card shows when the reader turns onto the last page, not when the book opens on it.
            this.finished = Boolean(detail.atEnd) && !detail.initial && (this.engine === 'epub' || this.engine === 'pdf');
            this.atEnd = Boolean(detail.atEnd);
        },

        onError({ kind }) {
            if (kind === 'unavailable') {
                this.state = 'withdrawn';
                const text = this.$root.querySelector('[data-reader=withdrawn-text]');

                if (text) {
                    text.textContent = text.dataset.template.replace(':percent', percentText(this.percent));
                }
            } else if (kind === 'password') {
                this.state = 'password';
            } else {
                this.state = 'corrupt';
            }

            this.finished = false;
            this.panel = null;
            this.chrome = true;
        },

        retry() {
            // An archive the server could not read never started an engine: ask for the page again.
            if (!this.running) {
                window.location.reload();

                return;
            }

            this.state = 'opening';
            window.dispatchEvent(new CustomEvent('reader:retry'));
        },

        // ---- the numbers on the bars ----

        get percentLabel() {
            return `${wholeText(this.percent)}%`;
        },

        // PDF contents come from the file's outline: a document without one has no Contents button.
        get hasContents() {
            return this.engine !== 'pdf' || this.toc.length > 0;
        },

        get valueText() {
            return this.strings.read.replace(':percent', percentText(this.percent));
        },

        get contentsNote() {
            const item = this.toc.find((entry) => entry.href === this.current);
            const text = item ? this.strings.noteIn.replace(':chapter', item.label) : this.strings.note;

            return text.replace(':percent', percentText(this.percent));
        },

        // Once the book is open and the chapter list has nothing after this chapter.
        get lastChapter() {
            return this.state === 'ready' && this.nextChapterHref === null;
        },

        get nextChapterHref() {
            const index = this.toc.findIndex((entry) => entry.href === this.current);

            return this.toc[index + 1]?.href ?? null;
        },

        nextChapter() {
            const href = this.nextChapterHref;

            href && !this.lastChapter && this.command('goto', { href });
        },

        scrub(value) {
            this.percent = Number(value);
        },

        seek(value) {
            this.percent = Number(value);
            this.command('goto', { percent: this.percent });
        },

        // ---- commands to the engine ----

        command(name, detail) {
            window.dispatchEvent(new CustomEvent(`reader:${name}`, { detail }));
        },

        // The reading direction of the page: a right-to-left comic or book turns the other way.
        get rtl() {
            return this.direction === 'rtl';
        },

        // The side buttons: the left one goes forward when the page turns right to left.
        get forwardLeft() {
            return this.rtl;
        },

        get forwardRight() {
            return !this.rtl;
        },

        turn(forward) {
            if (forward && this.atEnd && this.engine === 'epub') {
                this.finished = true;
            }

            this.command(forward ? 'next' : 'prev');
        },

        // ---- panels ----

        open(panel, opener) {
            // The trap hands focus back to whatever had it when the panel opened: with the keyboard that has to be the button.
            opener && document.activeElement !== opener && opener.focus();
            this.panel = panel;
        },

        // ---- the bars ----

        wake() {
            this.chrome = true;
            clearTimeout(this.hideTimer);
            this.hideTimer = setTimeout(() => this.tryHide(), HIDE_AFTER);
        },

        activity(pointerType) {
            pointerType !== 'touch' && this.wake();
        },

        tryHide() {
            const busy = this.panel !== null || this.state !== 'ready' || this.finished;
            const inside = [...this.$root.querySelectorAll('.reader-chrome')].some((bar) => bar.contains(document.activeElement) || bar.matches(':hover'));

            if (busy || inside) {
                return this.wake();
            }

            this.chrome = false;
        },

        // A tap on the page (touch only): the middle third shows or hides the bars, the sides turn the page of a paged format.
        onPointerUp(event) {
            if (event.pointerType !== 'touch' || event.target.closest('a, button, input, [role=dialog], [role=radio], .reader-chrome, [data-reader-state]')) {
                return;
            }

            this.tap(event.clientX / window.innerWidth);
        },

        tap(x) {
            if (typeof x !== 'number' || this.state !== 'ready' || this.panel !== null || this.finished) {
                return;
            }

            if (x > 1 / 3 && x < 2 / 3) {
                this.chrome ? this.hideNow() : this.wake();
            } else if (this.engine === 'epub' || this.engine === 'pages') {
                this.turn((x >= 2 / 3) !== this.rtl);
            } else if (this.engine === 'webtoon') {
                this.chrome ? this.hideNow() : this.wake();
            }
        },

        hideNow() {
            clearTimeout(this.hideTimer);
            this.chrome = false;
        },

        // ---- the keyboard ----

        onKey(event) {
            this.wake();

            if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            const target = event.target instanceof Element ? event.target : null;

            if (target?.closest('input, textarea, select, [contenteditable=""], [contenteditable=true]')) {
                return;
            }

            if (event.key === 'Escape') {
                if (this.panel !== null) {
                    this.panel = null;
                    event.preventDefault();
                }

                return;
            }

            if (this.state !== 'ready') {
                return;
            }

            const key = event.key;

            if (key === 'f' || key === 'F') {
                event.preventDefault();
                this.toggleFullscreen();
            } else if (key === '/' && this.hasContents && this.engine !== 'pages' && this.engine !== 'webtoon' && this.panel === null) {
                event.preventDefault();
                this.open('contents', this.$root.querySelector('[data-reader=contents-button]'));
            } else if (this.panel === null && !this.finished) {
                // Space on a button or a link is theirs; the arrows on a radio group or a slider are theirs.
                const forward = { PageDown: true, ' ': !event.shiftKey, ArrowRight: !this.rtl, ArrowLeft: this.rtl };
                const backward = { PageUp: true, ' ': event.shiftKey, ArrowLeft: !this.rtl, ArrowRight: this.rtl };

                if ((key === ' ' && target?.closest('button, a, summary, [role=radio], [role=slider]')) || (key.startsWith('Arrow') && target?.closest('[role=radio], [role=slider]'))) {
                    return;
                }

                if (forward[key]) {
                    event.preventDefault();
                    this.turn(true);
                } else if (backward[key]) {
                    event.preventDefault();
                    this.turn(false);
                }
            }
        },

        toggleFullscreen() {
            if (!document.fullscreenEnabled) {
                return;
            }

            const result = document.fullscreenElement ? document.exitFullscreen?.() : document.documentElement.requestFullscreen?.();

            result?.catch?.(() => {});
        },
    }));
}
