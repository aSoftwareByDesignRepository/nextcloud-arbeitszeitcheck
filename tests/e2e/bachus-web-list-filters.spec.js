// @ts-check
/**
 * Bachus: employee + manager list filter panels (time entries / absences).
 * Proves each status (and manager type) value can be toggled; empty results stay honest.
 */
import { test, expect } from '@playwright/test';
import { loginAs, gotoApp } from './helpers/auth.js';
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js';

// One worker — shared NC login must not race across roles.
test.describe.configure({ mode: 'serial' });

const TIME_STATUS_VALUES = ['', 'active', 'completed', 'pending_approval', 'paused'];
const ABSENCE_STATUS_VALUES = ['', 'pending', 'approved', 'rejected', 'substitute_declined'];
const MANAGER_TIME_STATUS = ['', 'active', 'break', 'paused', 'completed', 'pending_approval', 'rejected'];
const MANAGER_ABSENCE_TYPES = ['', 'vacation', 'sick_leave', 'personal_leave', 'home_office'];
const MANAGER_ABSENCE_STATUS = ['', 'pending', 'approved', 'rejected', 'cancelled'];

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} selectSelector
 * @param {string[]} values
 */
async function toggleSelectValues(page, selectSelector, values) {
	const select = page.locator(selectSelector);
	await expect(select).toBeVisible();
	for (const value of values) {
		await select.selectOption(value);
		await expect(select).toHaveValue(value);
	}
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} status
 * @param {() => Promise<void>} clickApply
 */
async function applyStatusAndExpectQuery(page, status, clickApply) {
	await Promise.all([
		page.waitForURL((url) => url.searchParams.get('status') === status, { timeout: 45000 }),
		clickApply(),
	]);
	await expect(page).toHaveURL(new RegExp(`[?&]status=${status}(?:&|$)`));
}

test.describe('Bachus: employee time-entries + absences filters', () => {
	test.beforeEach(async ({ page }) => {
		await loginAs(page, 'EMPLOYEE');
	});

	test('J-TF-01: time-entries filter panel toggles every status + empty stays honest', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/time-entries');
		await assertArbeitszeitcheckLoaded(page);
		await page.waitForSelector('#azc-main-content', { timeout: 30000 });

		await page.locator('#btn-filter').click();
		const panel = page.locator('#filter-section.time-entries-page__filter');
		await expect(panel).toBeVisible();
		await expect(page.locator('#filter-start-date')).toBeVisible();
		await expect(page.locator('#filter-end-date')).toBeVisible();

		await toggleSelectValues(page, '#filter-status', TIME_STATUS_VALUES);

		// Apply a rare status so list may empty; no fake KPI theater required beyond honest list/empty.
		await page.locator('#filter-status').selectOption('paused');
		await applyStatusAndExpectQuery(page, 'paused', () => page.locator('#btn-apply-filter').click());
		const empty = page.locator('.azc-empty-state__title, .empty-state__title, table tbody tr');
		await expect(empty.first()).toBeVisible();

		await page.locator('#btn-filter').click();
		await Promise.all([
			page.waitForURL((url) => !url.searchParams.has('status'), { timeout: 45000 }),
			page.locator('#btn-clear-filter').click(),
		]);
	});

	test('J-AF-01: absences filter panel toggles every status + apply/clear', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/absences');
		await assertArbeitszeitcheckLoaded(page);
		await page.waitForSelector('#azc-main-content', { timeout: 30000 });

		await page.locator('#btn-filter').click();
		const panel = page.locator('#filter-section.absences-page__filter');
		await expect(panel).toBeVisible();

		await toggleSelectValues(page, '#filter-status', ABSENCE_STATUS_VALUES);

		await page.locator('#filter-status').selectOption('rejected');
		await applyStatusAndExpectQuery(page, 'rejected', () => page.locator('#btn-apply-filter').click());
		const emptyOrRows = page.locator('.empty-state__title, .azc-empty-state__title, table tbody tr');
		await expect(emptyOrRows.first()).toBeVisible();

		await page.locator('#btn-filter').click();
		await Promise.all([
			page.waitForURL((url) => !url.searchParams.has('status'), { timeout: 45000 }),
			page.locator('#btn-clear-filter').click(),
		]);
	});
});

test.describe('Bachus: manager time-entries + absences filters', () => {
	test.beforeEach(async ({ page }) => {
		await loginAs(page, 'MANAGER');
	});

	test('J-MTF-01: manager time-entries status filter toggles + Show empty honest', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/manager/time-entries');
		await assertArbeitszeitcheckLoaded(page);
		await page.waitForSelector('#employee-time-entries-filter-form', { timeout: 30000 });

		await expect(page.locator('#start-date-filter')).not.toHaveValue('');
		await expect(page.locator('#end-date-filter')).not.toHaveValue('');

		await toggleSelectValues(page, '#status-filter', MANAGER_TIME_STATUS);

		await page.locator('#status-filter').selectOption('rejected');
		await page.locator('#employee-time-entries-submit').click();
		await page.waitForTimeout(500);
		const emptyOrTable = page.locator('#employee-time-entries-empty, #employee-time-entries-table-wrap');
		await expect(emptyOrTable.first()).toBeVisible();
		const emptyTitle = page.locator('#employee-time-entries-empty .azc-empty-state__title');
		if (await emptyTitle.isVisible().catch(() => false)) {
			await expect(emptyTitle).toContainText(/No entries|Select filters|Keine|Wählen/i);
		}
	});

	test('J-MAF-01: manager absences type+status filters toggle + Show', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/manager/absences');
		await assertArbeitszeitcheckLoaded(page);
		await page.waitForSelector('#employee-absences-filter-form, form.manager-scope-page__filter-form', { timeout: 30000 });

		await expect(page.locator('#employee-absences-start-date-filter')).toBeVisible();
		await expect(page.locator('#employee-absences-end-date-filter')).toBeVisible();

		await toggleSelectValues(page, '#employee-absences-type-filter', MANAGER_ABSENCE_TYPES);
		await toggleSelectValues(page, '#employee-absences-status-filter', MANAGER_ABSENCE_STATUS);

		await page.locator('#employee-absences-type-filter').selectOption('vacation');
		await page.locator('#employee-absences-status-filter').selectOption('cancelled');
		await page.locator('#employee-absences-submit').click();
		await page.waitForTimeout(500);
		const emptyOrTable = page.locator('#employee-absences-empty, #employee-absences-table-wrap, .azc-empty-state, table');
		await expect(emptyOrTable.first()).toBeVisible();
	});
});
