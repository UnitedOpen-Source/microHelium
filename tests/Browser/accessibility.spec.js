import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Issue #394 -- automatic WCAG 2.1 A/AA check with axe-core.
 *
 * What this measures, and nothing more: the axe-core rules tagged for WCAG
 * 2.0/2.1 levels A and AA, on the rendered page, in both colour themes, for
 * the screens listed in SCREENS. That is the exact claim docs/acessibilidade.md
 * makes. Screen-reader output, keyboard flow and content quality are NOT
 * measured here; axe cannot see them, and the declaration says so.
 *
 * Needs the fixtures from seed-a11y.php (a running contest, a problem, a
 * judged run, a clarification, a team and an admin); without them the
 * authenticated screens would be empty and pass for the wrong reason, so the
 * login step fails loudly instead.
 */

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

const PASSWORD = 'a11y-local-only';
const ACCOUNTS = {
    team: 'a11y-team@example.test',
    admin: 'a11y-admin@example.test',
};

/**
 * Known violations that this PR could not fix, by rule AND by selector, each
 * with the reason. Empty is the goal. A rule is never switched off with
 * disableRules: an exception here covers only the named node, so the same
 * rule still fails anywhere else, and an exception that stops happening
 * fails too, so the list cannot rot into a silent allowlist.
 *
 * Shape: { path: '/scoreboard', rule: 'color-contrast', target: '#some > .selector',
 *          colorScheme: 'dark' (optional), reason: '...', issue: '#NNN' }
 * `path` is the SCREENS entry's path, so `problem`/`submit` stay symbolic.
 */
const KNOWN_EXCEPTIONS = [];

/**
 * `problem` resolves to the fixture's only problem, found through the list
 * page so the spec does not hard-code a database id.
 */
const SCREENS = [
    { role: null, path: '/login', name: 'login' },
    { role: null, path: '/', name: 'início' },
    { role: 'team', path: '/exercises', name: 'lista de problemas' },
    { role: 'team', path: 'problem', name: 'enunciado' },
    { role: 'team', path: 'submit', name: 'envio' },
    { role: 'team', path: '/submissions', name: 'envios e veredito' },
    { role: 'team', path: '/clarifications', name: 'clarificações' },
    { role: 'team', path: '/scoreboard', name: 'placar' },
    { role: 'admin', path: '/home', name: 'painel' },
    { role: 'admin', path: '/backend/configurations', name: 'lista de competições' },
];

/**
 * `/login` is throttled to five attempts a minute (routes/web.php), so each
 * role logs in once per worker and every later test reuses the session
 * cookies instead of hammering the form.
 */
const sessions = {};

async function sessionFor(browser, baseURL, role) {
    if (! sessions[role]) {
        const context = await browser.newContext({ baseURL });
        const page = await context.newPage();
        await page.goto('/login');
        await page.getByLabel('E-mail', { exact: true }).fill(ACCOUNTS[role]);
        await page.getByLabel('Senha', { exact: true }).fill(PASSWORD);
        await page.getByRole('button', { name: 'Entrar', exact: true }).click();
        await expect(page, `login as ${role} failed; did seed-a11y.php run?`).not.toHaveURL(/\/login$/);
        sessions[role] = await context.storageState();
        await context.close();
    }

    return sessions[role];
}

async function resolvePath(page, path) {
    if (path !== 'problem' && path !== 'submit') {
        return path;
    }
    await page.goto('/exercises');
    const href = await page.locator('a[href*="/exercise/"]').first().getAttribute('href');
    expect(href, 'no problem on /exercises; did seed-a11y.php run?').toBeTruthy();
    const id = new URL(href, 'http://x').pathname.split('/')[2];

    return path === 'problem' ? `/exercise/${id}` : `/submit/${id}`;
}

/**
 * axe answers "incomplete" when it cannot decide and a person has to look.
 * Those are not violations, but letting them pass silently would hide
 * exactly the two defects this PR fixed (a name on a generic <span>, two
 * labels on one input), which axe only ever reports as incomplete. So an
 * incomplete result fails too, except this one reason, which is not about
 * text at all:
 *
 *  - color-contrast / nonBmp: the node holds only a decorative glyph (the
 *    ✓ of a verdict badge, the → of a button), already aria-hidden, next to
 *    the text that carries the meaning. Text contrast (1.4.3) does not apply;
 *    the glyph shares the colour of the text beside it, which axe measures.
 */
function isAcceptedIncomplete(rule, node) {
    return rule === 'color-contrast'
        && [...node.any, ...node.all, ...node.none].some(check => check.data?.messageKey === 'nonBmp');
}

function isKnown(screen, colorScheme, rule, target) {
    return KNOWN_EXCEPTIONS.some(e => e.path === screen.path && e.rule === rule && e.target === target
        && (! e.colorScheme || e.colorScheme === colorScheme));
}

function describe(results) {
    return results.map(r => `${r.id} (${r.impact ?? 'needs review'}): ${r.help}\n    ${r.nodes.map(n => n.target.join(' ')).join('\n    ')}`).join('\n');
}

/** Keeps only the nodes a filter rejects, dropping results left empty. */
function remaining(results, keep) {
    return results
        .map(r => ({ ...r, nodes: r.nodes.filter(n => keep(r.id, n)) }))
        .filter(r => r.nodes.length);
}

for (const colorScheme of ['light', 'dark']) {
    test.describe(`tema ${colorScheme === 'light' ? 'claro' : 'escuro'}`, () => {
        for (const screen of SCREENS) {
            test(`${screen.name} (${screen.path}) sem violação WCAG 2.1 A/AA`, async ({ browser, baseURL }) => {
                const context = await browser.newContext({
                    baseURL,
                    colorScheme,
                    // Tall on purpose. At 720px the lower sidebar links sit
                    // inside a scrolled-away overflow container, and axe
                    // reports their contrast as "could not be determined"
                    // instead of measuring it. 2400px puts every link of the
                    // admin sidebar on screen, so it is measured.
                    viewport: { width: 1280, height: 2400 },
                    storageState: screen.role ? await sessionFor(browser, baseURL, screen.role) : undefined,
                });
                const page = await context.newPage();
                const path = await resolvePath(page, screen.path);
                const response = await page.goto(path, { waitUntil: 'networkidle' });
                expect(response.status(), `${path} did not answer 2xx`).toBeLessThan(300);
                // The theme is applied by partials/theme-init from
                // prefers-color-scheme; a scan of the wrong theme would
                // pass for the wrong reason.
                if (colorScheme === 'dark') {
                    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
                } else {
                    await expect(page.locator('html')).not.toHaveClass(/\bdark\b/);
                }

                const { violations, incomplete } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
                await context.close();

                const seen = new Set();
                const unexpected = remaining(violations, (rule, node) => {
                    const target = node.target.join(' ');
                    if (isKnown(screen, colorScheme, rule, target)) {
                        seen.add(`${rule}|${target}`);

                        return false;
                    }

                    return true;
                });
                const unreviewed = remaining(incomplete, (rule, node) => ! isAcceptedIncomplete(rule, node));

                expect(unexpected, `axe found WCAG 2.1 A/AA violations on ${path}:\n${describe(unexpected)}`).toEqual([]);
                expect(unreviewed, `axe could not decide on ${path}; review by hand and fix, or explain in isAcceptedIncomplete():\n${describe(unreviewed)}`).toEqual([]);
                const stale = KNOWN_EXCEPTIONS.filter(e => e.path === screen.path
                    && (! e.colorScheme || e.colorScheme === colorScheme)
                    && ! seen.has(`${e.rule}|${e.target}`));
                expect(stale, 'a known exception no longer happens on this screen; remove it from KNOWN_EXCEPTIONS').toEqual([]);
            });
        }
    });
}
