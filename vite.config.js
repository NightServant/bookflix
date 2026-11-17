import fs from 'node:fs';
import path from 'node:path';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

/**
 * P9-R16: foliate-js puts `allow-scripts` on the iframes that hold a book (WebKit bug 218086), but the reader's rule is a sandbox without it. This
 * rewrites both places at build time (no new dependency). If a foliate-js update changes the line, the build stops instead of shipping the wider sandbox.
 */
const foliateSandbox = () => ({
    name: 'bookflix:foliate-sandbox',
    enforce: 'pre',
    transform(code, id) {
        const file = id.split('?')[0].replace(/\\/g, '/');

        if (!/\/node_modules\/foliate-js\/(paginator|fixed-layout)\.js$/.test(file)) {
            return null;
        }

        const wide = "'allow-same-origin allow-scripts'";

        if (!code.includes(wide)) {
            this.error(`foliate-js sandbox line not found in ${file}: review the update (P9-R16).`);
        }

        return { code: code.replaceAll(wide, "'allow-same-origin'"), map: null };
    },
});

/**
 * pdf.js loads its optional data on demand: the WebAssembly decoders (JPEG 2000, JBIG2, ICC colour), the CJK character maps and the standard fonts.
 * They are copied to `build/pdfjs/` under their own names (pdf.js asks for them by name, so no hashes), next to the worker, same origin.
 */
const pdfjsData = () => ({
    name: 'bookflix:pdfjs-data',
    generateBundle() {
        for (const dir of ['wasm', 'cmaps', 'standard_fonts']) {
            const from = path.resolve('node_modules/pdfjs-dist', dir);

            for (const name of fs.readdirSync(from)) {
                if (!name.startsWith('LICENSE') && !name.startsWith('quickjs')) {
                    this.emitFile({ type: 'asset', fileName: `pdfjs/${dir}/${name}`, source: fs.readFileSync(path.join(from, name)) });
                }
            }
        }
    },
});

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
        foliateSandbox(),
        pdfjsData(),
    ]),
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
});
