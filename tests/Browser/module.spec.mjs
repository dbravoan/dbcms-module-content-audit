import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

// Manual acceptance lane. fixtures/bootstrap.php refuses an unmarked application.
const enabled = process.env.DBCMS_BROWSER_MODULE === '1';
const fixture = enabled ? JSON.parse(readFileSync(process.env.DBCMS_BROWSER_FIXTURES, 'utf8')) : {};
const brands = enabled ? JSON.parse(process.env.DBCMS_BROWSER_BRANDS) : {};
const diagnostics = new WeakMap();
if (!enabled) throw new Error('Requires the marked disposable module application and both brand URLs.');
test.use({ storageState: { cookies: [], origins: [] } });

test.beforeAll(() => {
    for (const brand of ['dbcms', 'termocms']) {
        expect(brands[brand], `Supply the disposable ${brand} URL`).toBeTruthy();
        expect(fixture[brand]).toBeTruthy();
    }
});

test.beforeEach(async ({ page }) => {
    const errors = [];
    diagnostics.set(page, errors);
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    page.on('response', response => { if (response.status() >= 500) errors.push(`${response.status()} ${response.url()}`); });
    await page.addInitScript(() => { if (window.top === window) localStorage.setItem('dbcms_tour_completed', 'true'); });
});

test.afterEach(async ({ page }, testInfo) => {
    await testInfo.attach('browser-errors', { body: JSON.stringify(diagnostics.get(page)), contentType: 'application/json' });
    expect(diagnostics.get(page)).toEqual([]);
});

async function login(page, base) {
    await page.goto(`${base}/admin/login`);
    await page.locator('input[name=email]').fill(process.env.DBCMS_BROWSER_EMAIL || 'browser@example.test');
    await page.locator('input[name=password]').fill(process.env.DBCMS_BROWSER_PASSWORD || 'browser-only-password');
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: /Iniciar sesión/ }).click()]);
    await expect(page).not.toHaveURL(/\/login/);
    await expect(page.locator('main').first()).toBeVisible();
}

const snapshot = (page, testInfo, name) => page.screenshot({ path: testInfo.outputPath(`${name}.png`), fullPage: true });

const comparison = page => page.getByRole('dialog', { name: 'Comparación visual', exact: true });
const preview = (modal, side) => modal.frameLocator(`iframe[data-audit-preview="${side}"]`);

async function openComparison(page) {
    await expect(page.locator('iframe[data-audit-preview]')).toHaveCount(0);
    await page.locator('[data-audit-comparison-open]').click();
    const modal = comparison(page);
    await expect(modal).toBeVisible();
    await expect(modal.locator('#audit-preview-market')).toBeFocused();
    await expect(page.locator('body')).toHaveCSS('overflow', 'hidden');
    await expect(modal.locator('iframe[data-audit-preview]')).toHaveCount(2);
    return modal;
}

async function closeComparison(page, modal) {
    await modal.locator('[data-admin-dialog-close]').click();
    await expect(modal).toBeHidden();
    await expect(page.locator('iframe[data-audit-preview]')).toHaveCount(0);
    await expect(page.locator('[data-audit-comparison-open]')).toBeFocused();
}

async function assertPreview(modal, side, title) {
    const frame = preview(modal, side);
    await expect(frame.getByRole('heading', { name: title, exact: true })).toBeVisible();
    await expect(frame.locator('#cms-frontoffice-admin-bar-root')).toHaveCount(0);
    await expect(frame.locator('[data-cms-consent-banner]')).toHaveCount(0);
    await expect(frame.getByRole('tab', { name: 'Datos A', exact: true })).toHaveAttribute('aria-selected', 'true');
    await frame.getByRole('tab', { name: 'Datos B', exact: true }).click();
    await expect(frame.getByRole('tab', { name: 'Datos B', exact: true })).toHaveAttribute('aria-selected', 'true');
    await expect(frame.getByText('Browser tab second', { exact: true })).toBeVisible();
    await expect(frame.getByText('Browser tab first', { exact: true })).toBeHidden();
    const fonts = await frame.locator('html').evaluate(async () => {
        await document.fonts.ready;
        return [...document.fonts].map(font => ({ family: font.family, status: font.status }));
    });
    expect(fonts.filter(font => font.status === 'error')).toEqual([]);
    expect(fonts.some(font => font.status === 'loaded')).toBe(true);
    return fonts;
}

for (const brand of ['dbcms', 'termocms']) {
    test.describe(brand, () => {
        test('Content Audit compares contextual previews then closes them before approval', async ({ page }, testInfo) => {
            const base = brands[brand], data = fixture[brand];
            await login(page, base);
            await page.goto(`${base}/admin/audit`);
            await expect(page.locator('main')).toContainText(`Browser audit ${brand}`);
            await page.goto(`${base}/admin/audit/review/page/${data.audit}`);
            await expect(page.getByRole('heading', { name: new RegExp(`Browser audit ${brand}`) }).first()).toBeVisible();
            const previousOverflow = await page.locator('body').evaluate(element => element.style.overflow);
            const previousMainScroll = await page.locator('main').first().evaluate(element => element.scrollTop);
            const writes = [];
            const recordWrite = request => { if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method())) writes.push({ method: request.method(), url: request.url() }); };
            page.on('request', recordWrite);
            const modal = await openComparison(page);
            const initialMarket = await modal.locator('#audit-preview-market').inputValue();
            const controls = [];
            for (const field of ['market', 'locale']) {
                controls.push(await modal.locator(`#audit-preview-${field}`).evaluate((element, field) => {
                    const foreground = getComputedStyle(element).color;
                    let node = element, background = '';
                    while (node) {
                        background = getComputedStyle(node).backgroundColor;
                        if (background !== 'rgba(0, 0, 0, 0)' && background !== 'transparent') break;
                        node = node.parentElement;
                    }
                    const luminance = color => {
                        const channels = color.match(/[\d.]+/g).slice(0, 3).map(Number).map(value => {
                            const linear = value / 255;
                            return linear <= 0.04045 ? linear / 12.92 : ((linear + 0.055) / 1.055) ** 2.4;
                        });
                        return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
                    };
                    const text = luminance(foreground), surface = luminance(background);
                    return { field, foreground, background, semanticControl: element.classList.contains('admin-control'), contrast: (Math.max(text, surface) + 0.05) / (Math.min(text, surface) + 0.05) };
                }, field));
            }
            await testInfo.attach('audit-control-contrast', { body: JSON.stringify(controls), contentType: 'application/json' });
            for (const control of controls) {
                expect(control.contrast, `${brand} ${control.field} text contrast`).toBeGreaterThanOrEqual(4.5);
                expect(control.semanticControl, `${brand} ${control.field} uses the shared control`).toBe(true);
            }
            const fontEvidence = {};
            for (const side of ['before', 'after']) fontEvidence[side] = await assertPreview(modal, side, `Browser audit ${brand}`);
            await modal.locator('#audit-preview-market').selectOption(String(fixture.secondMarket));
            await modal.locator('#audit-preview-locale').selectOption('en');
            for (const side of ['before', 'after']) await expect(preview(modal, side).getByRole('heading', { name: `Browser audit English ${brand}`, exact: true })).toBeVisible();
            await modal.locator('#audit-preview-market').selectOption(initialMarket);
            await modal.locator('#audit-preview-locale').selectOption('es');
            for (const side of ['before', 'after']) await expect(preview(modal, side).getByRole('heading', { name: `Browser audit ${brand}`, exact: true })).toBeVisible();
            await modal.getByRole('button', { name: 'Maximizar', exact: true }).click();
            await expect(modal).toHaveClass(/admin-dialog--maximized/);
            await expect(modal.getByRole('heading', { name: 'Comparación visual', exact: true })).toBeVisible();
            await expect(modal.locator('[data-admin-dialog-close]')).toBeVisible();
            await modal.getByRole('heading', { name: 'Comparación visual', exact: true }).hover();
            await page.mouse.wheel(0, 500);
            expect(await page.locator('main').first().evaluate(element => element.scrollTop)).toBe(previousMainScroll);
            await modal.getByRole('button', { name: 'Restaurar tamaño', exact: true }).click();
            await expect(modal).not.toHaveClass(/admin-dialog--maximized/);
            await testInfo.attach('preview-fonts', { body: JSON.stringify(fontEvidence), contentType: 'application/json' });
            expect(writes).toEqual([]);
            page.off('request', recordWrite);
            await snapshot(page, testInfo, `${brand}-audit-review`);
            await closeComparison(page, modal);
            expect(await page.locator('body').evaluate(element => element.style.overflow)).toBe(previousOverflow);
            await page.locator('[data-audit-comparison-open]').press('Enter');
            await expect(modal).toBeVisible();
            await expect(modal.locator('#audit-preview-market')).toBeFocused();
            await modal.locator('#audit-preview-market').press('Escape');
            await expect(modal).toBeHidden();
            await expect(page.locator('iframe[data-audit-preview]')).toHaveCount(0);
            await expect(page.locator('[data-audit-comparison-open]')).toBeFocused();
            for (const side of ['before', 'after']) {
                await openComparison(page);
                const frameTab = preview(modal, side).getByRole('tab', { name: 'Datos B', exact: true });
                await frameTab.click();
                await expect(frameTab).toBeFocused();
                await page.keyboard.press('Escape');
                await expect(modal).toBeHidden();
                await expect(page.locator('iframe[data-audit-preview]')).toHaveCount(0);
                await expect(page.locator('[data-audit-comparison-open]')).toBeFocused();
            }
            await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Aprobar estos cambios', exact: true }).click()]);
            await expect(page.locator('main')).toContainText(/Aprobad/);
            const response = await page.goto(`${base}/es/es/browser-audit-${brand}`);
            expect(response.status()).toBe(200);
        });

        test('module listing and contextual comparison fit mobile', async ({ page }, testInfo) => {
            await login(page, brands[brand]);
            await page.setViewportSize({ width: 390, height: 844 });
            await page.goto(`${brands[brand]}/admin/audit`);
            await expect(page.locator('main').first()).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
            await snapshot(page, testInfo, `${brand}-content-audit-mobile`);
            await page.goto(`${brands[brand]}/admin/audit/review/page/${fixture[brand].mobileAudit}`);
            const modal = await openComparison(page);
            await expect(preview(modal, 'after').getByRole('heading', { name: `Browser audit ${brand} mobile`, exact: true })).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
            await snapshot(page, testInfo, `${brand}-audit-comparison-mobile`);
            await closeComparison(page, modal);
        });
    });
}
