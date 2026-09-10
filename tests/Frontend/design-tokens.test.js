import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const parse = block => Object.fromEntries([...block.matchAll(/--color-([\w-]+):\s*(#[\da-f]{6});/gi)].map(([, name, color]) => [name, color]));
const light = parse(css.match(/@theme\s*\{([^}]+)\}/)[1]);
const dark = { ...light, ...parse(css.match(/\.dark\s*\{([^}]+)\}/)[1]) };
const luminance = hex => hex.slice(1).match(/../g).map(value => parseInt(value, 16) / 255).map(value => value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4).reduce((total, value, i) => total + value * [.2126, .7152, .0722][i], 0);
const contrast = (a, b) => (Math.max(luminance(a), luminance(b)) + .05) / (Math.min(luminance(a), luminance(b)) + .05);

for (const [theme, tokens] of Object.entries({ light, dark })) {
    test(`${theme}: shared text, status and action colors meet 4.5:1 contrast`, () => {
        const pairs = [
            ['foreground', 'background'], ['foreground', 'card'], ['muted-foreground', 'background'], ['muted-foreground', 'card'], ['muted-foreground', 'muted'],
            ['accent-foreground', 'accent'], ['secondary-foreground', 'secondary'],
            ['brand-ink', 'brand-panel'], ['brand-muted', 'brand-panel'], ['brand-highlight-ink', 'brand-highlight'],
        ];
        for (const role of ['primary', 'success', 'warning', 'destructive', 'info']) {
            pairs.push([role, 'card'], [role, `${role}-soft`], [`${role}-foreground`, role], [`${role}-foreground`, `${role}-hover`]);
        }
        for (const [foreground, background] of pairs) {
            assert.ok(tokens[foreground] && tokens[background], `Missing ${foreground}/${background}`);
            const ratio = contrast(tokens[foreground], tokens[background]);
            assert.ok(ratio >= 4.5, `${theme} ${foreground}/${background}: ${ratio.toFixed(2)}:1`);
        }
    });
    test(`${theme}: input boundaries and focus indicators meet 3:1 contrast`, () => {
        for (const foreground of ['input', 'ring']) {
            for (const background of ['card', 'background']) {
                const ratio = contrast(tokens[foreground], tokens[background]);
                assert.ok(ratio >= 3, `${theme} ${foreground}/${background}: ${ratio.toFixed(2)}:1`);
            }
        }
    });
}
