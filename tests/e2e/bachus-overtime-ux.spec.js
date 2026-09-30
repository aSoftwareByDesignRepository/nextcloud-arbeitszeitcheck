// @ts-check
/**
 * Bachus journeys: overtime discoverability + Saldo≠payout + simplified premium fixture a11y.
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import { login, credsFromEnv } from './helpers/auth.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const fixtureUrl = 'file://' + path.resolve(__dirname, 'fixtures/bachus-premium-config.html');
const dashboardPremiumFixtureUrl = 'file://' + path.resolve(__dirname, 'fixtures/dashboard-premium-summary.html');

test.describe('Bachus: overtime discoverability (employee)', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS');
		await login(page, credsFromEnv('EMPLOYEE'));
	});

	test('J-E1/J-E2: overtime balance card always visible with formula', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/dashboard');
		await page.waitForSelector('#azc-main-content', { timeout: 30000 });

		const heading = page.locator('#dashboard-overtime-heading');
		await expect(heading).toBeVisible();
		await expect(heading).toHaveText(/overtime balance|Überstundensaldo|Ihre Überstunden/i);

		const formula = page.locator('#dashboard-overtime-formula');
		await expect(formula).toBeVisible();
		await expect(formula).not.toBeEmpty();

		const balance = page.locator('#dashboard-overtime-balance-value');
		await expect(balance).toBeVisible();
		// hours unit — 'h' (en) or 'Std.'/'Stunden' (de)
		await expect(balance).toContainText(/h|std/i);
	});

	test('J-E3: axe clean on overtime card region', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/dashboard');
		await page.waitForSelector('#dashboard-overtime-heading', { timeout: 30000 });

		const results = await new AxeBuilder({ page })
			.include('.dashboard-overtime-card')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();

		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});
});

test.describe('Bachus: payout vs Saldo (admin)', () => {
	test('J-A1: payouts page explains Saldo is on the dashboard', async ({ page }) => {
		test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');
		await login(page, credsFromEnv('ADMIN'));
		await page.goto('/apps/arbeitszeitcheck/admin/overtime-payouts');
		await page.waitForSelector('#azc-main-content', { timeout: 30000 });

		const hint = page.locator('#admin-ot-payout-saldo-hint');
		await expect(hint).toHaveCount(1);
		await expect(hint).toBeVisible();
		await expect(hint).toContainText(/dashboard|Arbeitsplatz|Übersicht|balance|Saldo|contract|Soll|gearbeitet|Stundensaldo/i);
	});
});

test.describe('Bachus: simplified premium config fixture', () => {
	test('J-P1: one-screen config is axe-clean and saveable', async ({ page }) => {
		await page.goto(fixtureUrl);
		await expect(page.locator('#premium-title')).toBeVisible();

		const resultsOff = await new AxeBuilder({ page })
			.include('#premium-simple-config')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(resultsOff.violations, JSON.stringify(resultsOff.violations, null, 2)).toEqual([]);

		await page.locator('#premium-enabled').check();
		await expect(page.locator('#premium-panel')).toBeVisible();

		const resultsOn = await new AxeBuilder({ page })
			.include('#premium-simple-config')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(resultsOn.violations, JSON.stringify(resultsOn.violations, null, 2)).toEqual([]);

		await page.locator('#premium-save').click();
		await expect(page.locator('#premium-status')).toContainText(/saved|gespeichert/i);
	});

	test('J-P1b: More options stay collapsed until opened', async ({ page }) => {
		await page.goto(fixtureUrl);
		await page.locator('#premium-enabled').check();
		const more = page.locator('#premium-panel details').first();
		await expect(more).toHaveCount(1);
		await expect(more).not.toHaveAttribute('open', '');
		await more.locator('summary').click();
		await expect(more).toHaveAttribute('open', '');
	});
});

test.describe('Bachus: dashboard Saldo + Zuschläge fixture', () => {
	test('J-P2: premiums section stays separate from Saldo and is axe-clean', async ({ page }) => {
		await page.goto(dashboardPremiumFixtureUrl);
		await expect(page.locator('#dashboard-overtime-heading')).toBeVisible();
		await expect(page.locator('#dashboard-overtime-balance-value')).toContainText(/12\.50/);
		await expect(page.locator('#dashboard-premium-heading')).toBeVisible();
		await expect(page.locator('#dashboard-premium-help')).toContainText(/not pay|not your Saldo/i);
		await expect(page.locator('.dashboard-premium-list li')).toHaveCount(2);

		const results = await new AxeBuilder({ page })
			.include('.dashboard-overtime-card')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});
});

test.describe('Bachus: premium report preview fixture', () => {
	test('J-P3: report table is axe-clean and states orthogonal to Saldo', async ({ page }) => {
		const reportUrl = 'file://' + path.resolve(__dirname, 'fixtures/premium-report-preview.html');
		await page.goto(reportUrl);
		await expect(page.locator('#premium-report-title')).toBeVisible();
		await expect(page.locator('#premium-report-help')).toContainText(/not Saldo|not pay/i);

		const results = await new AxeBuilder({ page })
			.include('#premium-report-fixture')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});
});

test.describe('Bachus: DATEV org config fixture', () => {
	test('J-D1: DATEV numbers form is axe-clean and rejects partial pair', async ({ page }) => {
		const datevUrl = 'file://' + path.resolve(__dirname, 'fixtures/datev-org-config.html');
		await page.goto(datevUrl);
		await expect(page.locator('#datev-title')).toBeVisible();

		const results = await new AxeBuilder({ page })
			.include('#datev-org-config')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);

		await page.locator('#datev-berater').fill('1234567');
		await page.locator('#datev-save').click();
		await expect(page.locator('#datev-error')).toContainText(/both numbers|beide/i);

		await page.locator('#datev-mandant').fill('12345');
		await page.locator('#datev-save').click();
		await expect(page.locator('#datev-status')).toContainText(/saved|gespeichert/i);
		await expect(page.locator('#datev-error')).toBeEmpty();
	});
});

test.describe('Bachus: premium DATEV under details', () => {
	test('J-D2: DATEV Lohnart starts collapsed and is axe-clean when opened', async ({ page }) => {
		const url = 'file://' + path.resolve(__dirname, 'fixtures/premium-datev-details.html');
		await page.goto(url);
		await expect(page.locator('#premium-datev-details')).not.toHaveAttribute('open', '');
		await expect(page.locator('#premium-cat-ot-datev')).toBeHidden();
		await page.locator('#premium-datev-details summary').click();
		await expect(page.locator('#premium-cat-ot-datev')).toBeVisible();
		const results = await new AxeBuilder({ page })
			.include('#premium-datev-fixture')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});
});

test.describe('Bachus: vacation unit hours home fixture', () => {
	test('J-V1: hours copy is visible and axe-clean (NN-08)', async ({ page }) => {
		const url = 'file://' + path.resolve(__dirname, 'fixtures/vacation-unit-hours-home.html');
		await page.goto(url);
		await expect(page.locator('#vacation-metric')).toContainText(/hours left/i);
		await expect(page.locator('#vacation-metric')).not.toContainText(/days left/i);

		const results = await new AxeBuilder({ page })
			.include('#vacation-unit-home')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});
});

test.describe('Bachus: vacation hours booking fixture', () => {
	test('J-V2: presets fill hours and region is axe-clean', async ({ page }) => {
		const url = 'file://' + path.resolve(__dirname, 'fixtures/vacation-hours-booking.html');
		await page.goto(url);
		await expect(page.locator('#stat-remaining')).toHaveText('196.0');
		await expect(page.locator('#stat-remaining-unit')).toContainText(/hours/i);
		// Bachus smart default: full weekday range (Mon–Fri) × 8h = 40
		await expect(page.locator('#absence-duration-hours')).toHaveValue('40');
		await expect(page.locator('#absence-duration-hours-preview')).toContainText(/40/);

		await page.locator('button[data-hours="4"]').click();
		await expect(page.locator('#absence-duration-hours')).toHaveValue('4');

		await page.locator('button[data-hours-half]').click();
		await expect(page.locator('#absence-duration-hours')).toHaveValue('4');

		await page.locator('button[data-hours-full]').click();
		await expect(page.locator('#absence-duration-hours')).toHaveValue('8');

		await page.locator('button[data-hours-range]').click();
		await expect(page.locator('#absence-duration-hours')).toHaveValue('40');
		await expect(page.locator('#absence-duration-hours-preview')).toContainText(/This request/i);

		await page.locator('#absence-type').selectOption('sick_leave');
		await expect(page.locator('#absence-duration-hours-group')).toBeHidden();

		await page.locator('#absence-type').selectOption('vacation');
		await expect(page.locator('#absence-duration-hours-group')).toBeVisible();
		await expect(page.locator('#absence-duration-hours')).toHaveValue('40');

		const results = await new AxeBuilder({ page })
			.include('#vacation-hours-booking')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});
});

test.describe('Bachus: vacation unit admin radios fixture', () => {
	test('J-V3: Days|Hours radios gate Apply until client confirm + axe-clean', async ({ page }) => {
		const url = 'file://' + path.resolve(__dirname, 'fixtures/vacation-unit-admin-radios.html');
		await page.goto(url);
		await page.locator('#vacation-unit-hours').check();
		// Apply stays enabled (WCAG); status announces the missing confirmation.
		await expect(page.locator('#btn-vacation-unit-apply')).toBeEnabled();
		await expect(page.locator('#vacation-unit-migrate-status')).toContainText(/confirmation checkbox/i);
		await page.locator('#btn-vacation-unit-apply').click();
		await expect(page.locator('#vacation-unit-migrate-error')).toBeVisible();
		await expect(page.locator('#vacation-unit-migrate-error')).toContainText(/confirmation checkbox/i);
		await page.locator('#vacationUnitClientConfirmed').check();
		await expect(page.locator('#vacation-unit-migrate-status')).toHaveText('');
		await page.locator('#btn-vacation-unit-apply').click();
		await expect(page.locator('#vacation-unit-migrate-status')).toContainText(/hours/i);

		const results = await new AxeBuilder({ page })
			.include('#vacation-unit-admin')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});
});

test.describe('Bachus: absences hours list fixture', () => {
	test('J-V4: duration column shows hours not days and is axe-clean', async ({ page }) => {
		const url = 'file://' + path.resolve(__dirname, 'fixtures/absences-hours-list.html');
		await page.goto(url);
		await expect(page.locator('#duration-heading')).toHaveText(/Duration/i);
		await expect(page.locator('#row-duration')).toContainText(/4\.0\s*h/i);
		await expect(page.locator('#row-duration')).not.toContainText(/days/i);
		await expect(page.locator('#stat-remaining')).toHaveText('196.0');

		const results = await new AxeBuilder({ page })
			.include('#absences-hours-list')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});
});

test.describe('Bachus: dashboard vacation hours fixture', () => {
	test('J-V5: home remaining uses hours label (NN-08) and is axe-clean', async ({ page }) => {
		const url = 'file://' + path.resolve(__dirname, 'fixtures/dashboard-vacation-hours.html');
		await page.goto(url);
		await expect(page.locator('#dashboard-vacation-remaining-label')).toContainText(/hours/i);
		await expect(page.locator('#dashboard-vacation-remaining-label')).not.toContainText(/days/i);
		await expect(page.locator('#dashboard-vacation-remaining-value')).toHaveText('196.0');
		await expect(page.locator('#dashboard-vacation-hours')).toHaveAttribute('data-vacation-unit', 'hours');
		await expect(page.locator('#dash-annual-remaining')).toContainText(/hours/i);
		await expect(page.locator('#dash-carryover-remaining')).toContainText(/hours/i);
		await expect(page.locator('#dash-annual-remaining')).not.toContainText(/days/i);

		const results = await new AxeBuilder({ page })
			.include('#dashboard-vacation-hours')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});
});

test.describe('Bachus: manager vacation hours recording fixture', () => {
	test('J-V6: manager hours field/presets/preview are axe-clean', async ({ page }) => {
		const url = 'file://' + path.resolve(__dirname, 'fixtures/manager-vacation-hours.html');
		await page.goto(url);
		await expect(page.locator('#manager-absence-record-hours')).toBeVisible();
		await expect(page.locator('#manager-absence-record-hours-preview')).toContainText(/hours/i);
		await expect(page.locator('#manager-absence-record-hours-preview')).not.toContainText(/\bdays\b/i);
		await expect(page.locator('.manager-absence-hours-preset')).toHaveCount(4);

		const results = await new AxeBuilder({ page })
			.include('#manager-vacation-hours')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});
});
