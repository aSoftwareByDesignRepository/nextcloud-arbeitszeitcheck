// @ts-check
/**
 * Bachus: core employee simplicity after GH #37–#39
 * — glance hierarchy, calendar one-line help, axe on dashboard/calendar.
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { loginAs, gotoApp } from './helpers/auth.js';

async function expectAxeClean(page, includeSelector, label) {
	const builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']);
	if (includeSelector) {
		builder.include(includeSelector);
	}
	const results = await builder.analyze();
	expect(results.violations, `${label}:\n${JSON.stringify(results.violations, null, 2)}`).toEqual([]);
}

test.describe('Bachus simplify: dashboard hours hierarchy', () => {
	test.beforeEach(async ({ page }) => {
		await loginAs(page, 'EMPLOYEE');
	});

	test('J-S1: primary glance shows Today/Week; month/year behind details', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/dashboard');
		await page.waitForSelector('#dashboard-metrics-heading', { timeout: 30000 });

		const panel = page.locator('.azc-dashboard-metrics-panel');
		await expect(panel).toBeVisible();
		await expect(panel.locator('.azc-card__lead')).toContainText(/Saldo|balance|Überstunden|next card|nächste/i);

		const primaryList = panel.locator('.azc-dashboard-metrics').first();
		await expect(primaryList.locator('.azc-dashboard-metric')).toHaveCount(2);

		const more = panel.locator('details.azc-dashboard-metrics-more');
		await expect(more).toHaveCount(1);
		await expect(more).not.toHaveAttribute('open', '');
		await more.locator('summary').click();
		await expect(more).toHaveAttribute('open', '');
		await expect(more.locator('.azc-dashboard-metric')).toHaveCount(2);
	});

	test('J-S2: overtime Saldo card remains the clear year hero', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/dashboard');
		await page.waitForSelector('#dashboard-overtime-balance-value', { timeout: 30000 });
		await expect(page.locator('#dashboard-overtime-heading')).toBeVisible();
		await expect(page.locator('#dashboard-overtime-balance-value')).toBeVisible();
		await expect(page.locator('#dashboard-overtime-formula')).not.toBeEmpty();
	});

	test('J-S3: axe clean on metrics + overtime regions', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/dashboard');
		await page.waitForSelector('#dashboard-overtime-heading', { timeout: 30000 });
		await expectAxeClean(page, '.azc-dashboard-metrics-panel', 'metrics panel');
		await expectAxeClean(page, '.dashboard-overtime-card', 'overtime card');
	});

	test('J-S4: no punch dead-end — manual CTA or punch when stamping on', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/dashboard');
		await page.waitForSelector('#azc-main-content', { timeout: 30000 });
		const punch = page.locator('.azc-dashboard-punch');
		const manualCta = page.locator('#dashboard-manual-add-entry');
		const blocked = page.locator('#dashboard-manual-blocked-heading');
		const punchVisible = await punch.isVisible().catch(() => false);
		const manualVisible = await manualCta.isVisible().catch(() => false);
		const blockedVisible = await blocked.isVisible().catch(() => false);
		expect(punchVisible || manualVisible || blockedVisible).toBeTruthy();
		if (manualVisible) {
			await expect(manualCta).toHaveAttribute('href', /time-entries\/create/);
			const box = await manualCta.boundingBox();
			expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
		}
	});
});

test.describe('Bachus simplify: calendar day panel', () => {
	test.beforeEach(async ({ page }) => {
		await loginAs(page, 'EMPLOYEE');
	});

	test('J-C1: day panel short help + primary working-time CTA', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/calendar');
		await page.waitForSelector('#calendar-month-view, .calendar-day', { timeout: 30000 });
		const day = page.locator('.calendar-day[data-date], .calendar-day[tabindex="0"]').first();
		await day.click();
		const panel = page.locator('#day-details-panel');
		await expect(panel).toBeVisible();
		const help = page.locator('#day-details-actions-help');
		await expect(help).toBeVisible();
		const helpText = (await help.innerText()).trim();
		expect(helpText.length).toBeLessThan(120);
		expect(helpText.toLowerCase()).not.toContain('migration');

		const primary = panel.locator('.day-details-actions__link--primary, .day-details-actions a.azc-btn--primary').first();
		await expect(primary).toBeVisible();
		const box = await primary.boundingBox();
		expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
	});

	test('J-C2: axe clean on open day panel', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/calendar');
		await page.waitForSelector('#calendar-month-view, .calendar-day', { timeout: 30000 });
		// Prefer dated/focusable days — empty padding cells can sit under a grid overlay.
		const day = page.locator('.calendar-day[data-date], .calendar-day[tabindex="0"]').first();
		await day.click({ force: false });
		await expect(page.locator('#day-details-panel')).toBeVisible({ timeout: 15000 });
		await expectAxeClean(page, '#day-details-panel', 'day details panel');
	});
});
