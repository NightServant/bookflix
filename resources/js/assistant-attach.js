/**
 * The assistant page's Alpine state, and its attach button (P11-R26). The native file input is behind the "+" button. A PDF is read here, in the browser, with pdf.js
 * (loaded only when one is picked, same worker setup as the reader): its words go to the component as text and its bytes never leave the
 * device. Anything else is uploaded to the component's temporary upload, which checks it again on the server.
 */
const PDF_BYTES = 10 * 1024 * 1024;
const OTHER_BYTES = 4 * 1024 * 1024;
const PAGES = 30;
const CHARS = 20000;

// Where pdf.js finds its optional data (see resources/js/reader/pdf.js).
const dataBase = (workerUrl) => (import.meta.env.DEV ? new URL('/node_modules/pdfjs-dist/', window.location.href) : new URL('../pdfjs/', new URL(workerUrl, window.location.href))).href;

/**
 * The text of the first 30 pages, at most 20,000 characters. Throws `password` for a locked file.
 */
async function pdfText(file) {
    const pdfjs = await import('pdfjs-dist');
    const workerUrl = (await import('pdfjs-dist/build/pdf.worker.min.mjs?url')).default;
    const base = dataBase(workerUrl);

    pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

    const task = pdfjs.getDocument({
        data: new Uint8Array(await file.arrayBuffer()),
        verbosity: pdfjs.VerbosityLevel.ERRORS,
        cMapUrl: `${base}cmaps/`,
        standardFontDataUrl: `${base}standard_fonts/`,
        wasmUrl: `${base}wasm/`,
    });

    try {
        const doc = await task.promise;
        let text = '';

        for (let number = 1; number <= Math.min(doc.numPages, PAGES) && text.length < CHARS; number++) {
            const content = await (await doc.getPage(number)).getTextContent();

            text += content.items.map((item) => ('str' in item ? item.str + (item.hasEOL ? '\n' : ' ') : '')).join('') + '\n';
        }

        return text.replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim().slice(0, CHARS);
    } catch (error) {
        throw error instanceof pdfjs.PasswordException ? new Error('password') : error;
    } finally {
        task.destroy();
    }
}

export default function (Alpine) {
    Alpine.data('assistantChat', (strings) => ({
        pending: '',
        count: 0,
        reading: false,
        notice: '',

        settle(focus) {
            this.$nextTick(() => {
                if (focus) this.$refs.input.focus({ preventScroll: true });
                document.querySelector('[data-latest-answer]')?.scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
            });
        },

        async pick(event) {
            const input = event.target;
            const files = [...input.files];
            const uploads = [];

            input.value = '';
            this.notice = '';

            for (const file of files) {
                const name = file.name;

                if (/\.pdf$/i.test(name) || file.type === 'application/pdf') {
                    if (file.size > PDF_BYTES) {
                        this.notice = strings.pdfTooBig.replace(':name', name);
                        continue;
                    }

                    this.reading = true;

                    try {
                        await this.$wire.addPdfText(name, await pdfText(file));
                    } catch (error) {
                        this.notice = (error?.message === 'password' ? strings.pdfLocked : strings.pdfUnreadable).replace(':name', name);
                    } finally {
                        this.reading = false;
                    }
                } else if (file.size > OTHER_BYTES) {
                    this.notice = strings.tooBig.replace(':name', name);
                } else {
                    uploads.push(file);
                }
            }

            if (uploads.length > 0) {
                this.$wire.$uploadMultiple('incoming', uploads, () => {}, () => { this.notice = strings.uploadFailed; });
            }
        },
    }));
}
