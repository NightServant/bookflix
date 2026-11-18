/**
 * What the reader engines (epub.js, pdf.js, pages.js) share: the window events, the translated strings with `:n` slots, the two ways a read ends
 * badly (`Unavailable`: the file was withdrawn, 403 or 404; `NetworkError`: the server or the connection kept failing), the expiry a signed address
 * carries, a way to hold a signed address fresh (P9-R14), and one retry with backoff for requests the server answered 429 or 5xx.
 */
export const emit = (name, detail) => window.dispatchEvent(new CustomEvent(name, { detail }));

export const fill = (text, values) => Object.entries(values).reduce((out, [key, value]) => out.replace(`:${key}`, value), text ?? '');

export class Unavailable extends Error {}

export class NetworkError extends Error {}

// The address's own expiry (Laravel signs `expires`, a unix time), or null when it has none.
export const expiryOf = (url) => {
    const expires = Number(new URL(url, window.location.href).searchParams.get('expires'));

    return Number.isFinite(expires) && expires > 0 ? expires : null;
};

// Ask for a new address when the one held has less than this many seconds to live.
export const REFRESH_MARGIN = 90;

/**
 * Keeps the newest signed address of the stream: `current()` answers the held one, asking the page's Livewire component for a fresh `streamUrl()`
 * first when the held one is about to run out. The address is also kept on the container (`data-stream-url`, and `data-url-refreshes` counts swaps).
 */
export function signedStream(container) {
    let url = container.dataset.streamUrl;
    let refreshing = null;

    async function refresh() {
        try {
            const fresh = await window.Livewire?.find(container.dataset.wireId)?.streamUrl();

            if (typeof fresh === 'string' && fresh !== '') {
                url = fresh;
                container.dataset.streamUrl = fresh;
                container.dataset.urlRefreshes = String(Number(container.dataset.urlRefreshes ?? 0) + 1);
            }
        } catch {
            // Keep the one held: if the file was withdrawn, the next request says so with its own 403.
        } finally {
            refreshing = null;
        }
    }

    return {
        refresh: () => (refreshing ??= refresh()),
        async current() {
            const expires = expiryOf(url);

            if (expires !== null && expires - Date.now() / 1000 < REFRESH_MARGIN) {
                await (refreshing ??= refresh());
            }

            return url;
        },
    };
}

// The waits before the second, third and fourth try of a request the server could not answer right now.
export const BACKOFF = [500, 1500, 4000];

const MAX_WAIT = 15000;

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// How long to wait before trying again: the server's `Retry-After` (seconds) when it gave one, else the backoff step; never more than 15 s.
export const waitFor = (response, step) => {
    const seconds = Number(response?.headers?.get('Retry-After'));

    return Math.min(MAX_WAIT, Number.isFinite(seconds) && seconds > 0 ? seconds * 1000 : step);
};

export const transient = (status) => status === 429 || status >= 500;

/**
 * Runs `request()` (which returns a fetch Response) up to four times. 403 and 404 throw `Unavailable` at once; 429, 5xx and a connection that failed
 * wait and try again (0.5 s, 1.5 s, 4 s, or the server's Retry-After); when they are used up, `NetworkError`. Any other status is returned as it is.
 */
export async function withBackoff(request) {
    for (let attempt = 0; ; attempt++) {
        let response = null;

        try {
            response = await request();
        } catch (error) {
            if (!(error instanceof TypeError)) {
                throw error;
            }
        }

        if (response && (response.status === 403 || response.status === 404)) {
            throw new Unavailable();
        }

        if (response && !transient(response.status)) {
            return response;
        }

        if (attempt >= BACKOFF.length) {
            throw new NetworkError();
        }

        await sleep(waitFor(response, BACKOFF[attempt]));
    }
}
