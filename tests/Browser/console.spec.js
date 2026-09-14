import { test, expect } from '@playwright/test';

/**
 * Issue #166 -- built assets, real browser, real CSP.
 *
 * The defect this exists for: `resources/js/app.js` mounted its Vue islands
 * by handing Vue a string template, Vue compiled it with `new Function`, and
 * the CSP added in #143 (`script-src 'self'` plus a nonce, no
 * `'unsafe-eval'`) refused. The EvalError was thrown at module top level, so
 * every line after it -- the remaining islands, initializeUI(), the
 * feature-page mount -- never ran. The layout renders an island on every
 * page, so this happened on every page load of every built deployment, and
 * the whole suite stayed green: the PHP tests assert on rendered HTML, and
 * the island markup was rendered exactly as expected.
 *
 * So the assertion is deliberately two-sided, and the second half is not
 * belt-and-braces -- it is measured. Reintroducing the exact defect (a
 * `template` string handed to createApp) and rebuilding leaves the three
 * console-error tests GREEN and fails only the mount test: app.js now wraps
 * each mount in a try/catch, added by the #166 fix precisely so one island
 * cannot take the page down with it, and that catch swallows the EvalError
 * before it ever reaches `pageerror`. The console check would have caught
 * the original defect, which threw at module top level; it would not catch
 * the same mistake made today. Proving an island really rendered is what
 * does.
 */

/**
 * Pages any visitor can reach, so that this needs no fixtures and no
 * database state beyond a migrated schema. All three render the layout, and
 * the layout is where <theme-toggle> lives -- which is exactly why the
 * original defect hit every page in the application.
 */
const PUBLIC_PAGES = ['/', '/login', '/register'];

/**
 * Console output that is noise rather than a defect. Kept deliberately tiny
 * and explained: a permissive list here would let the next EvalError
 * through, which is the one thing this file exists to prevent.
 */
function isIgnorable(text) {
    return (
        // Vite's dev-mode hint, harmless and absent from a production build.
        text.includes('You are running a development build of Vue')
        // A favicon or source map the server does not have is not a
        // JavaScript failure, and is reported as a console error by Chromium
        // rather than as a network event.
        || /Failed to load resource.*\b(favicon|\.map)\b/.test(text)
    );
}

/**
 * Attach listeners BEFORE the first navigation: the defect fires during
 * module evaluation, which is over long before any `waitFor` resolves.
 */
function watchFor(page) {
    const problems = [];

    page.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));
    page.on('console', (message) => {
        if (message.type() === 'error' && ! isIgnorable(message.text())) {
            problems.push(`console.error: ${message.text()}`);
        }
    });

    return problems;
}

for (const path of PUBLIC_PAGES) {
    test(`${path} loads with no console errors`, async ({ page }) => {
        const problems = watchFor(page);

        const response = await page.goto(path, { waitUntil: 'networkidle' });

        expect(response.status(), `${path} did not answer 200`).toBeLessThan(400);
        expect(problems, `JavaScript failed on ${path}`).toEqual([]);
    });
}

test('the theme toggle island really mounts', async ({ page }) => {
    const problems = watchFor(page);

    await page.goto('/login', { waitUntil: 'networkidle' });

    // The mount replaces <theme-toggle> with a div.vue-island; the component
    // renders a <button> inside it. Asserting on the button and not merely
    // on the wrapper is the difference between "app.js ran" and "app.js ran
    // and Vue produced a render function without the runtime compiler".
    const island = page.locator('div.vue-island button');

    await expect(island.first()).toBeVisible();
    expect(problems).toEqual([]);
});

/**
 * The CSP is the other half of the story, and a deployment that quietly
 * dropped it would make everything above pass for the wrong reason: the
 * original template compilation works fine WITHOUT a CSP. This fails if the
 * header stops arriving or if 'unsafe-eval' is ever added back to buy a
 * runtime compiler.
 */
test('the response still carries a CSP without unsafe-eval', async ({ page }) => {
    const response = await page.goto('/login');
    const csp = response.headers()['content-security-policy'];

    expect(csp, 'No Content-Security-Policy header (issue #90/#143)').toBeTruthy();
    expect(csp).toContain("script-src 'self'");
    expect(csp).not.toContain('unsafe-eval');
});
