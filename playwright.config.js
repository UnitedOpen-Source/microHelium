import { existsSync } from 'node:fs';

import { defineConfig } from '@playwright/test';

// `public/hot` means a Vite dev server is running, and @vite() then emits
// script tags pointing at http://localhost:5173 instead of the built bundle.
// That is the opposite of this suite's subject -- the defect in #166 existed
// only in built assets -- and it fails here as a wall of CSP violations that
// look like a real regression. Stopping with the reason beats that.
if (existsSync(new URL('./public/hot', import.meta.url))) {
    throw new Error(
        'public/hot exists, so the app would serve unbuilt modules from the Vite dev server. '
        + 'These tests are about the PRODUCTION bundle under the real CSP: stop `npm run dev`, '
        + 'run `npm run build`, and try again.'
    );
}

/**
 * Issue #166 -- the layer that was missing.
 *
 * Every Vue island in the application was dead in any built deployment for
 * an unknown number of weeks: app.js mounted components by handing Vue a
 * string template, Vue compiled it with `new Function`, and issue #143's CSP
 * refuses that. The EvalError fired at module top level, so nothing after it
 * ran, on every page, in every browser.
 *
 * Nothing in the suite could have seen it. The PHP tests assert on rendered
 * HTML and the island markup was rendered exactly as expected; the Node
 * frontend tests import components directly, with no CSP and no browser. The
 * defect lived in the gap: built assets, executed under a real Content
 * Security Policy. That is this config's entire subject.
 *
 * Deliberately NOT a general end-to-end suite. It loads a couple of pages and
 * fails on a console error or a dead island -- fast enough to belong in CI on
 * every push, which a real E2E suite would not be.
 */
export default defineConfig({
    testDir: './tests/Browser',
    // The point is to catch a deterministic, environment-wide breakage, and a
    // retry on that only hides a flake that does not exist here.
    retries: 0,
    fullyParallel: false,
    // Fail rather than pass if someone leaves a .only behind in CI.
    forbidOnly: !! process.env.CI,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL: process.env.APP_URL || 'http://127.0.0.1:8123',
        // A page that took this long to answer is a broken server, not a slow
        // one: everything here is static or a single query.
        actionTimeout: 10_000,
        navigationTimeout: 30_000,
    },
    projects: [
        {
            name: 'chromium',
            use: { browserName: 'chromium' },
        },
    ],
    // `php artisan serve` and not `vite dev`: the defect only exists in built
    // assets served through the application's own middleware, which is where
    // SecurityHeaders puts the CSP. A dev server would serve unbuilt modules
    // and no CSP at all, and would have been green throughout.
    webServer: process.env.PLAYWRIGHT_NO_SERVER
        ? undefined
        : {
            command: 'php artisan serve --host=127.0.0.1 --port=8123',
            url: 'http://127.0.0.1:8123/up',
            reuseExistingServer: ! process.env.CI,
            timeout: 60_000,
        },
});
