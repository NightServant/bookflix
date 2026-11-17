/**
 * The reader's save (P9-R13). Every engine dispatches `reader:relocate` on the window; this listens, keeps the latest place, and saves it to the page's
 * progress route: 2 s after the reader stops moving (and at least every 15 s while they keep paging), at once when the tab is hidden or closing
 * (`navigator.sendBeacon`, which can only send a form), and again, with backoff, when the network failed. `seconds` is the time the tab was visible
 * since the last save that went through; `client_ts` is this browser's own clock in milliseconds and only ever goes up, which is what the server
 * orders saves by. A 403 or 404 means the file was withdrawn: saving stops, `reader:error` says `unavailable`, and the place on screen is kept.
 */
const DEBOUNCE = 2000;
const MAX_WAIT = 15000;
const RETRY_FROM = 2000;
const RETRY_TO = 60000;
const MAX_SECONDS = 86400;

export default function startProgress({ url }) {
    const token = () => window.livewireScriptConfig?.csrf ?? document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    let latest = null;
    let version = 0;
    let saved = 0;
    let stopped = false;
    let inflight = false;
    let timer = null;
    let firstMove = null;
    let retryDelay = RETRY_FROM;
    let lastKey = null;
    let lastTs = 0;

    // Active time: the tab's visible seconds since the last save that went through.
    let visibleSince = document.visibilityState === 'visible' ? Date.now() : null;
    let accrued = 0;

    const settle = () => {
        if (visibleSince !== null) {
            accrued += Date.now() - visibleSince;
            visibleSince = Date.now();
        }
    };

    const nextTs = () => (lastTs = Math.max(Date.now(), lastTs + 1));

    const payload = () => {
        settle();

        return {
            locator: latest.locator,
            percent: latest.percent,
            ...(latest.chapter ? { chapter: String(latest.chapter).slice(0, 100) } : {}),
            seconds: Math.min(MAX_SECONDS, Math.round(accrued / 1000)),
            client_ts: nextTs(),
        };
    };

    const schedule = (delay) => {
        clearTimeout(timer);
        timer = setTimeout(() => send(), delay);
    };

    const unavailable = () => {
        stopped = true;
        clearTimeout(timer);
        window.dispatchEvent(new CustomEvent('reader:error', { detail: { kind: 'unavailable' } }));
    };

    async function send() {
        clearTimeout(timer);

        if (stopped || latest === null || saved === version || inflight) {
            return;
        }

        inflight = true;
        const sending = version;
        const body = payload();
        const seconds = accrued;
        firstMove = null;
        let failed = false;

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token(), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(body),
            });

            if (response.status === 403 || response.status === 404) {
                return unavailable();
            }

            // A payload the server won't take (422) is dropped; a server or session failure is tried again.
            if (!response.ok && response.status !== 422) {
                throw new Error(String(response.status));
            }

            saved = sending;
            accrued = Math.max(0, accrued - seconds);
            retryDelay = RETRY_FROM;
        } catch {
            failed = true;
            retryDelay = Math.min(RETRY_TO, retryDelay * 2);
            schedule(retryDelay);
        } finally {
            inflight = false;
        }

        // Moved again while this one was on its way.
        if (!failed && saved !== version && !stopped) {
            schedule(DEBOUNCE);
        }
    }

    // The tab is going away or going to the background: send what is not saved yet, in a way that survives the page.
    function flush() {
        if (stopped || latest === null || saved === version) {
            return;
        }

        const form = new FormData();
        form.append('_token', token());
        form.append('payload', JSON.stringify(payload()));

        if (navigator.sendBeacon?.(url, form)) {
            saved = version;
            accrued = 0;
            clearTimeout(timer);
        } else {
            send();
        }
    }

    const onRelocate = ({ detail }) => {
        // The place the book opened at is already saved; only the reader's own moves are.
        if (stopped || !detail?.locator || detail.initial) {
            return;
        }

        // The same place again (a re-layout after a settings change) is not a move.
        const key = detail.locator.cfi ?? JSON.stringify(detail.locator);

        if (key === lastKey) {
            return;
        }

        lastKey = key;
        latest = { locator: detail.locator, percent: detail.percent, chapter: detail.chapter };
        version++;
        firstMove ??= Date.now();
        schedule(Math.min(DEBOUNCE, Math.max(0, MAX_WAIT - (Date.now() - firstMove))));
    };

    const onVisibility = () => {
        if (document.visibilityState === 'hidden') {
            settle();
            visibleSince = null;
            flush();
        } else {
            visibleSince = Date.now();
        }
    };

    const onOnline = () => {
        retryDelay = RETRY_FROM;
        send();
    };

    window.addEventListener('reader:relocate', onRelocate);
    window.addEventListener('pagehide', flush);
    window.addEventListener('online', onOnline);
    document.addEventListener('visibilitychange', onVisibility);

    return () => {
        clearTimeout(timer);
        window.removeEventListener('reader:relocate', onRelocate);
        window.removeEventListener('pagehide', flush);
        window.removeEventListener('online', onOnline);
        document.removeEventListener('visibilitychange', onVisibility);
    };
}
