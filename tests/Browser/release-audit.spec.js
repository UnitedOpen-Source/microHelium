import { test, expect } from '@playwright/test';

// Opt-in against the isolated fixtures from seed-audit.php. Never uses production accounts.
test.skip(!process.env.BROWSER_RELEASE_AUDIT, 'Requires isolated audit.sqlite fixtures; see docs/frontend-release-audit.md.');

test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill('ui-audit@example.test');
    await page.getByLabel('Senha', { exact: true }).fill('audit-local-only');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page).not.toHaveURL(/\/login$/);
});

test('production CSP allows edit, focus restoration and safe delete cancellation', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    const response = await page.goto('/backend/sites');
    expect(response.headers()['content-security-policy']).not.toContain('unsafe-inline');
    const edit = page.getByRole('button', { name: 'Editar Sede Sul', exact: true });
    await edit.click();
    const dialog = page.getByRole('dialog', { name: 'Editar Sede Sul' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Nome do site')).toHaveValue('Sede Sul');
    await dialog.getByLabel('Tempo max. de espera para julgamento (segundos)').fill('73');
    await dialog.getByRole('button', { name: 'Salvar', exact: true }).click();
    await expect(page.getByRole('row', { name: /Sede Sul/ })).toContainText('73 s');
    await edit.click();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden(); await expect(edit).toBeFocused();
    await page.getByRole('button', { name: 'Excluir Sede Sul', exact: true }).click();
    const confirm = page.getByRole('dialog', { name: 'Confirmar ação' });
    await expect(confirm).toBeVisible();
    await expect(confirm.getByRole('button', { name: 'Cancelar', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(confirm).toBeHidden();
    await expect(page.getByRole('row', { name: /Sede Sul/ })).toBeVisible();
    expect(errors).toEqual([]);
});

test('file selection exposes the name and competition operations explain blockers', async ({ page }) => {
    await page.goto('/backend/import-boca');
    await page.locator('input[type=file]').setInputFiles({ name: 'exemplo.zip', mimeType: 'application/zip', buffer: Buffer.from('fixture: do not submit') });
    await expect(page.locator('#fileName')).toHaveText('exemplo.zip');
    await page.goto('/backend/configurations');
    await page.getByRole('link', { name: /^Operações\s*: Auditoria de interface$/ }).click();
    await expect(page.getByRole('heading', { name: '1. Conferir pendências' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Finalizar competição', exact: true })).toBeDisabled();
    await expect(page.getByText('Congelado — resultados ainda ocultos')).toBeVisible();
    await page.getByRole('button', { name: 'Revelar placar', exact: true }).click();
    const confirm = page.getByRole('dialog', { name: 'Confirmar ação' });
    await expect(confirm).toContainText('Auditoria de interface');
    await confirm.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await expect(confirm).toBeHidden();
});

for (const width of [375, 768, 1440]) {
    test(`management screens contain their layout at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        for (const path of ['/backend/configurations', '/backend/sites', '/backend/languages']) {
            await page.goto(path);
            await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
            await expect(page.getByRole('button', { name: /Ativar modo/ })).toBeVisible();
            await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), { message: path }).toBeLessThanOrEqual(1);
        }
        await page.getByRole('button', { name: 'Editar Python', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Editar Python' });
        await expect(dialog).toBeVisible();
        await page.keyboard.press('Shift+Tab');
        await expect(dialog.getByRole('button', { name: 'Salvar', exact: true })).toBeFocused();
        await expect(dialog.getByRole('button', { name: 'Salvar', exact: true })).toBeInViewport();
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
    });
}
