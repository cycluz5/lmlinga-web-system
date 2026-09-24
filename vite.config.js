import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import esbuild from 'esbuild';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dirname = path.dirname(fileURLToPath(import.meta.url));

function lmlingaServiceWorker() {
    const entry = path.resolve(dirname, 'resources/js/offline/offline-sw.js');
    const outfile = path.resolve(dirname, 'public/sw.js');

    const bundle = () =>
        esbuild.build({
            absWorkingDir: dirname,
            entryPoints: [entry],
            bundle: true,
            format: 'iife',
            platform: 'browser',
            target: ['es2020'],
            outfile,
            logLevel: 'silent',
        });

    return {
        name: 'lmlinga-service-worker',
        buildStart() {
            this.addWatchFile(entry);
            this.addWatchFile(path.resolve(dirname, 'resources/js/offline/offline-sw-policy.js'));
            this.addWatchFile(path.resolve(dirname, 'resources/js/offline/offline-sw-runtime.js'));
        },
        async closeBundle() {
            await bundle();
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        lmlingaServiceWorker(),
    ],
    server: {
        host: '127.0.0.1',
    },
});
