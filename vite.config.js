import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            // Issue #144: NOT vue/dist/vue.esm-bundler.js. That build carries
            // the runtime template compiler, which compiles with
            // `new Function` -- refused by issue #143's CSP (script-src
            // 'self' plus a nonce, no 'unsafe-eval'). Nothing here needs it:
            // every component is a .vue SFC compiled to a render function at
            // build time. Pointing at the default entry keeps the compiler
            // out of the bundle, so a runtime string template fails at build
            // time rather than throwing an EvalError in the browser and
            // taking the rest of the page's JavaScript with it.
            '@': '/resources/js',
        },
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
            port: 5173,
        },
        watch: {
            usePolling: true,
        },
    },
});
