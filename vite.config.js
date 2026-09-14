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
            // out of the bundle.
            //
            // Measured, because the obvious claim is wrong: this does NOT
            // make a reintroduced string template fail at build time. The
            // build succeeds, and a production Vue build emits neither an
            // error nor a warning -- the component just renders nothing.
            // What it does buy is that the failure stays local instead of
            // throwing an EvalError at module top level and taking every
            // other island, and initializeUI(), down with it.
            //
            // Because that silence is not a guard,
            // tests/Unit/FrontendCspCompatibilityTest.php asserts on this
            // alias and on the absence of a `template` option.
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
